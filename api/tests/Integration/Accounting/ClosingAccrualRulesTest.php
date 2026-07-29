<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * ČÚS 019 — kontace časového rozlišení 381 / 383 / 384 / 385.
 *
 * Matice účetnictví je vedla jako „kontace existují a jsou dostupné — ČÁSTEČNĚ / BEZ
 * TESTU": účty i kontace v seedu byly, ale nic neověřovalo, že přes ně zaúčtovaná
 * částka skutečně skončí na správném účtu a na správné straně.
 *
 * Záměna je přitom tichá a věcná. Všechny čtyři jsou účty časového rozlišení, ale liší
 * se DVĚMA nezávislými osami:
 *   • aktivum (381 náklady příštích období, 385 příjmy příštích období)
 *     vs. pasivum (383 výdaje příštích období, 384 výnosy příštích období),
 *   • náklad (381, 383) vs. výnos (384, 385).
 * Prohození 383 ↔ 384 tedy převrátí náklad na výnos a rozvahu i výsledovku posune
 * dvakrát — a bez testu by se to projevilo až nesedící závěrkou.
 *
 * Testy zamykají tři věci: účet, stranu a to, že kontace nesahá na sousední účet.
 *
 * Izolovaný supplier v transakci s rollbackem (vzor ClosingLegalRepairReserveTest).
 */
#[Group('integration')]
final class ClosingAccrualRulesTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2091;

    private Connection $db;
    private ClosingService $closing;
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

        // Asistované zápisy jsou uzávěrková operace — období musí být ve stavu `closing`.
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

    /**
     * 381 náklady příštích období — zaplaceno teď, náklad patří do dalšího období.
     * AKTIVUM: 381 MD, peníze/závazek D.
     */
    public function testPrepaidExpenseBooks381AsAsset(): void
    {
        $lines = $this->book('accrual.prepaid.expense', 24_000.00, 'Roční předplatné hrazené předem');

        self::assertEqualsWithDelta(24_000.00, $lines['381']['debit'], 0.001, '381 je aktivum — MD.');
        self::assertEqualsWithDelta(24_000.00, $lines['221']['credit'], 0.001);
        self::assertArrayNotHasKey('383', $lines, '381 a 383 jsou opačné strany rozvahy.');
    }

    /**
     * 383 výdaje příštích období — náklad patří sem, zaplatí se až později.
     * PASIVUM: 383 D, náklad 5xx MD (protiúčet dodá účetní dle druhu).
     */
    public function testAccruedExpenseBooks383AsLiability(): void
    {
        $lines = $this->book('accrual.accrued.expense', 12_000.00, 'Nájem za prosinec, fakturace v lednu', '518');

        self::assertEqualsWithDelta(12_000.00, $lines['383']['credit'], 0.001, '383 je pasivum — D.');
        self::assertEqualsWithDelta(12_000.00, $lines['518']['debit'], 0.001, 'Náklad běžného období.');
        self::assertArrayNotHasKey('381', $lines);
    }

    /**
     * 384 výnosy příštích období — přijato teď, výnos patří do dalšího období.
     * PASIVUM: 384 D, pohledávka/peníze MD.
     */
    public function testDeferredRevenueBooks384AsLiability(): void
    {
        $lines = $this->book('accrual.deferred.revenue', 30_000.00, 'Předplacená služba na příští rok');

        self::assertEqualsWithDelta(30_000.00, $lines['384']['credit'], 0.001, '384 je pasivum — D.');
        self::assertEqualsWithDelta(30_000.00, $lines['311']['debit'], 0.001);
        self::assertArrayNotHasKey('385', $lines, '384 a 385 jsou opačné strany rozvahy.');
    }

    /**
     * 385 příjmy příštích období — výnos patří sem, inkasuje se až později.
     * AKTIVUM: 385 MD, výnos 6xx D (protiúčet dodá účetní dle druhu).
     */
    public function testAccruedRevenueBooks385AsAsset(): void
    {
        $lines = $this->book('accrual.accrued.revenue', 18_000.00, 'Provedená práce, fakturace v lednu', '602');

        self::assertEqualsWithDelta(18_000.00, $lines['385']['debit'], 0.001, '385 je aktivum — MD.');
        self::assertEqualsWithDelta(18_000.00, $lines['602']['credit'], 0.001, 'Výnos běžného období.');
        self::assertArrayNotHasKey('384', $lines);
    }

    /**
     * 382 komplexní náklady příštích období — odklad SOUHRNU věcně souvisejících nákladů
     * (příprava výroby, výzkum trhu). Protiúčtem není druhový 5xx účet, ale 555, aby
     * druhové členění nákladů ve výsledovce zůstalo nedotčené.
     *
     * 382 byl posledním účtem časového rozlišení bez kontace — v osnově byl, ale nedalo
     * se na něj nic zaúčtovat (migrace 1160).
     */
    public function testComplexPrepaidExpenseBooks382Over555(): void
    {
        $lines = $this->book('accrual.complex.defer', 400_000.00, 'Příprava a záběh výroby — odklad do dalšího období');

        self::assertEqualsWithDelta(400_000.00, $lines['382']['debit'], 0.001, '382 je aktivum — MD.');
        self::assertEqualsWithDelta(400_000.00, $lines['555']['credit'], 0.001, 'Protiúčtem je 555, ne druhový 5xx.');
        self::assertArrayNotHasKey('381', $lines, '382 není 381 — odkládá souhrn, ne jeden druh nákladu.');
    }

    /** Rozpuštění komplexních nákladů otáčí strany: 555 MD / 382 D. */
    public function testComplexPrepaidExpenseReleaseReversesSides(): void
    {
        $lines = $this->book('accrual.complex.release', 100_000.00, 'Rozpuštění komplexních nákladů do období, kam patří');

        self::assertEqualsWithDelta(100_000.00, $lines['555']['debit'], 0.001);
        self::assertEqualsWithDelta(100_000.00, $lines['382']['credit'], 0.001);
    }

    /**
     * Kontace s volnou stranou (383/385) bez protiúčtu se ODMÍTNE. Bez toho by zápis
     * buď spadl na neúplné kontaci, nebo si systém protiúčet domyslel — a domyšlený
     * nákladový účet u časového rozlišení je tichá chyba ve výsledovce.
     */
    public function testOpenSidedRuleWithoutCounterAccountIsRejected(): void
    {
        $this->expectException(ClosingException::class);
        $this->expectExceptionMessageMatches('/protiúčet/i');

        $this->closing->createAssistedEntry($this->supplierId, $this->periodId, 'deferrals', [
            'row_version' => $this->rowVersion(),
            'rule_key'    => 'accrual.accrued.expense',
            'amount'      => 5_000.00,
            'description' => 'Bez protiúčtu',
        ], $this->meta());
    }

    /** Všechny čtyři kontace časového rozlišení jsou v kroku `deferrals` dostupné. */
    public function testAllFourAccrualRulesAreAvailable(): void
    {
        $booked = [];
        foreach ([
            ['accrual.prepaid.expense', null, '381'],
            ['accrual.accrued.expense', '518', '383'],
            ['accrual.deferred.revenue', null, '384'],
            ['accrual.accrued.revenue', '602', '385'],
        ] as [$rule, $counter, $expectedAccount]) {
            $lines = $this->book($rule, 1_000.00, 'Kontrola dostupnosti ' . $rule, $counter);
            self::assertArrayHasKey($expectedAccount, $lines, $rule . ' musí zaúčtovat na ' . $expectedAccount);
            $booked[] = $expectedAccount;
        }

        self::assertSame(['381', '383', '384', '385'], $booked);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /** @return array<string, array{debit:float, credit:float}> */
    private function book(string $ruleKey, float $amount, string $description, ?string $counter = null): array
    {
        $body = [
            'row_version' => $this->rowVersion(),
            'rule_key'    => $ruleKey,
            'amount'      => $amount,
            'description' => $description,
        ];
        if ($counter !== null) {
            $body['counter_account'] = $counter;
        }

        $result = $this->closing->createAssistedEntry($this->supplierId, $this->periodId, 'deferrals', $body, $this->meta());

        return $this->linesByAccountCode((int) $result['entry_id']);
    }

    private function rowVersion(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT row_version FROM accounting_periods WHERE id = ?');
        $stmt->execute([$this->periodId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{user_id:int, posted_by:int} */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }

    /** @return array<string, array{debit:float, credit:float}> */
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
