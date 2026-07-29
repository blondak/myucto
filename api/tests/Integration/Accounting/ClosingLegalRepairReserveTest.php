<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * ZoR § 7 — zákonná rezerva na opravy hmotného majetku.
 *
 * Matice daní z příjmů (F4) vedla tuhle položku jako CHYBÍ s vysokým rizikem: účty
 * 451 (rezervy podle zvláštních právních předpisů) a 552 (tvorba a zúčtování zákonných
 * rezerv) byly v účtové osnově od začátku, ale kontace k nim NEEXISTOVALA. Jediné
 * seedované rezervní kontace byly `reserve.other.*` (554/459) — účetní rezerva, která
 * je podle § 25 ZDP daňově NEUZNATELNÁ. Daňovou rezervu tedy nešlo uplatnit vůbec,
 * leda ručním zápisem mimo uzávěrkový průvodce.
 *
 * Rozdíl mezi oběma je věcný, ne kosmetický, a tenhle test ho zamyká: kdyby se
 * `reserve.repairs.*` omylem namapovalo na 554, rezerva by se tiše stala daňově
 * neuznatelnou a poplatník by přišel o odpočet, aniž by se cokoli rozbilo.
 */
#[Group('integration')]
final class ClosingLegalRepairReserveTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2097;

    private Connection $db;
    private ClosingService $closing;
    private JournalEntryRepository $journal;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $periodId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->closing = $c->get(ClosingService::class);
            $this->journal = $c->get(JournalEntryRepository::class);
            $this->periods = $c->get(AccountingPeriodRepository::class);
            $seeder = $c->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier / user.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');

        // Asistované zápisy jsou uzávěrková operace — období musí být ve stavu `closing`
        // (R7: do otevřeného období se účtuje běžnou cestou, ne přes asistenta).
        $pdo->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ?")
            ->execute([$this->periodId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /** Tvorba zákonné rezervy → 552 MD / 451 D, tedy daňově uznatelný náklad. */
    public function testCreatingLegalReserveBooks552Over451(): void
    {
        $result = $this->closing->createAssistedEntry($this->supplierId, $this->periodId, 'provisions', [
            'row_version' => $this->rowVersion(),
            'rule_key'    => 'reserve.repairs.create',
            'amount'      => 120000.00,
            'description' => 'Rezerva na opravu střechy haly (plán 2 období)',
        ], $this->meta());

        $lines = $this->linesByAccountCode((int) $result['entry_id']);
        self::assertEqualsWithDelta(120000.00, $lines['552']['debit'], 0.001, 'Tvorba je náklad na 552.');
        self::assertEqualsWithDelta(120000.00, $lines['451']['credit'], 0.001, 'Závazek rezervy na 451.');
        self::assertArrayNotHasKey('554', $lines, 'Zákonná rezerva NESMÍ jít na 554 — to je daňově neuznatelné.');
    }

    /** Čerpání / zrušení otáčí strany: 451 MD / 552 D. */
    public function testReleasingLegalReserveReversesSides(): void
    {
        $result = $this->closing->createAssistedEntry($this->supplierId, $this->periodId, 'provisions', [
            'row_version' => $this->rowVersion(),
            'rule_key'    => 'reserve.repairs.release',
            'amount'      => 120000.00,
            'description' => 'Čerpání rezervy — oprava provedena',
        ], $this->meta());

        $lines = $this->linesByAccountCode((int) $result['entry_id']);
        self::assertEqualsWithDelta(120000.00, $lines['451']['debit'], 0.001);
        self::assertEqualsWithDelta(120000.00, $lines['552']['credit'], 0.001);
    }

    /**
     * Účetní (ostatní) rezerva zůstává dostupná a účtuje se na 554/459 — daňově
     * NEUZNATELNĚ. Kdyby se obě kontace slily do jedné, přišel by poplatník buď
     * o odpočet, nebo by si ho vzal neoprávněně.
     */
    public function testAccountingReserveStaysOnNonDeductibleAccounts(): void
    {
        $result = $this->closing->createAssistedEntry($this->supplierId, $this->periodId, 'provisions', [
            'row_version' => $this->rowVersion(),
            'rule_key'    => 'reserve.other.create',
            'amount'      => 50000.00,
            'description' => 'Účetní rezerva na soudní spor',
        ], $this->meta());

        $lines = $this->linesByAccountCode((int) $result['entry_id']);
        self::assertEqualsWithDelta(50000.00, $lines['554']['debit'], 0.001);
        self::assertEqualsWithDelta(50000.00, $lines['459']['credit'], 0.001);
        self::assertArrayNotHasKey('552', $lines, 'Účetní rezerva NESMÍ jít na 552.');
    }

    /** Kontace mimo povolený seznam kroku se odmítne — asistent není volný zápis. */
    public function testUnknownRuleForStepIsRejected(): void
    {
        $this->expectException(ClosingException::class);
        $this->closing->createAssistedEntry($this->supplierId, $this->periodId, 'provisions', [
            'row_version' => $this->rowVersion(),
            'rule_key'    => 'invoice.services.issued',
            'amount'      => 1000.00,
            'description' => 'Nesmysl',
        ], $this->meta());
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function rowVersion(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT row_version FROM accounting_periods WHERE id = ?');
        $stmt->execute([$this->periodId]);

        return (int) $stmt->fetchColumn();
    }

    // ── ČÚS 018: rezerva na daň z příjmů (453) ───────────────────────────────

    /**
     * Rezerva na daň z příjmů → 599 MD / 453 D.
     *
     * 453 byl posledním účtem třídy 45, na který nemířila žádná kontace — v osnově byl,
     * ale nedalo se na něj nic zaúčtovat. Slouží k tomu, aby výsledek hospodaření nebyl
     * nadhodnocený o daň, která prokazatelně vznikne, když se závěrka sestavuje dřív, než
     * je známa skutečná daňová povinnost.
     */
    public function testCreatingIncomeTaxReserveBooks599Over453(): void
    {
        $result = $this->closing->createAssistedEntry($this->supplierId, $this->periodId, 'provisions', [
            'row_version' => $this->rowVersion(),
            'rule_key'    => 'reserve.income_tax.create',
            'amount'      => 250000.00,
            'description' => 'Rezerva na daň z příjmů — závěrka sestavena před podáním přiznání',
        ], $this->meta());

        $lines = $this->linesByAccountCode((int) $result['entry_id']);
        self::assertEqualsWithDelta(250000.00, $lines['599']['debit'], 0.001);
        self::assertEqualsWithDelta(250000.00, $lines['453']['credit'], 0.001);
        self::assertArrayNotHasKey('591', $lines,
            'Rezerva NENÍ splatná daň — ta se účtuje 591/341 až krokem income_tax.');
    }

    /** Rozpuštění po podání přiznání otáčí strany: 453 MD / 599 D. */
    public function testReleasingIncomeTaxReserveReversesSides(): void
    {
        $result = $this->closing->createAssistedEntry($this->supplierId, $this->periodId, 'provisions', [
            'row_version' => $this->rowVersion(),
            'rule_key'    => 'reserve.income_tax.release',
            'amount'      => 250000.00,
            'description' => 'Rozpuštění rezervy — přiznání podáno',
        ], $this->meta());

        $lines = $this->linesByAccountCode((int) $result['entry_id']);
        self::assertEqualsWithDelta(250000.00, $lines['453']['debit'], 0.001);
        self::assertEqualsWithDelta(250000.00, $lines['599']['credit'], 0.001);
    }

    /**
     * Rezerva na daň nesmí skončit na 451 ani 459. Zákonná rezerva (451) je daňově
     * uznatelný náklad a ostatní rezerva (459) neuznatelný — daň z příjmů není ani
     * jedno, leží pod výsledkem hospodaření před zdaněním.
     */
    public function testIncomeTaxReserveDoesNotUseOtherReserveAccounts(): void
    {
        $result = $this->closing->createAssistedEntry($this->supplierId, $this->periodId, 'provisions', [
            'row_version' => $this->rowVersion(),
            'rule_key'    => 'reserve.income_tax.create',
            'amount'      => 100000.00,
            'description' => 'Rezerva na daň',
        ], $this->meta());

        $lines = $this->linesByAccountCode((int) $result['entry_id']);
        self::assertArrayNotHasKey('451', $lines);
        self::assertArrayNotHasKey('459', $lines);
        self::assertArrayNotHasKey('552', $lines);
        self::assertArrayNotHasKey('554', $lines);
    }

    /** @return array<string,mixed> */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }

    /**
     * @return array<string, array{debit:float, credit:float}>
     */
    private function linesByAccountCode(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, SUM(l.amount) AS amt
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ?
           GROUP BY a.account_code, l.side'
        );
        $stmt->execute([$entryId]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $code = (string) $row['account_code'];
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][(string) $row['side']] += (float) $row['amt'];
        }

        return $out;
    }
}
