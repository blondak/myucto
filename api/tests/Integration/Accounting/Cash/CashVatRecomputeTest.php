<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Cash;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * § 37 odst. 2 — rozpad DPH pokladního dokladu počítá BACKEND, ne klient.
 *
 * Pokladna byla jediné místo, kde byla hodnota z frontendu autoritativní:
 * `validateVatLines()` ověřovala pouze `Σ(základ+daň) == celkem`. Při brutto 121 Kč
 * a sazbě 21 % tou kontrolou projde 12 101 různých rozpadů, přestože zákonný je právě
 * jeden (daň 21,00). Klient — vlastní i cizí přes API — tak mohl odvést libovolně
 * nízkou daň, aniž by cokoli zaprotestovalo.
 *
 * Autoritativní je nově jen BRUTTO řádku a sazba; rozpad se přepočítá SSOT
 * `InvoiceMath` v režimu shora, tedy týmž kódem jako u faktur.
 *
 * Zadání ZDOLA (§ 37/1) se tím nerozbíjí — přepočet je vůči němu idempotentní,
 * změřeno na 10 000 000 kombinací (`private/scripts/cash_vat_split_sweep.php`,
 * 0 rozdílů). Testy níže to pokrývají i behaviorálně.
 */
#[Group('integration')]
final class CashVatRecomputeTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;

    private Connection $db;
    private CashDocumentService $service;
    private CashRegisterService $registers;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(CashDocumentService::class);
            $this->registers = $container->get(CashRegisterService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();

        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier / user.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $seeder->seedForSupplier($this->supplierId);
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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
     * Jádro nálezu: klient pošle rozpad, který sedí na celkovou částku, ale odvádí
     * o 10 Kč nižší daň. Dnešní kontrola součtu ho pustí; přepočet ho musí opravit.
     */
    public function testUnderstatedTaxIsRecomputedFromGross(): void
    {
        $reg = $this->makeRegister();

        $res = $this->service->create($this->supplierId, $this->doc([
            'purpose'      => 'sale',
            'doc_type'     => 'in',
            'total_amount' => 121.00,
            'vat_mode'     => 'vat',
            'partner_dic'  => 'CZ12345678',
            // Σ = 121,00 → dnešní kontrola součtu projde, daň je ale o 10 Kč nižší.
            'vat_lines'    => [['vat_rate' => 21, 'base_amount' => 110.00, 'vat_amount' => 11.00]],
        ], $reg), $this->userId);

        $stored = $this->service->get($this->supplierId, $res['id']);
        $line = $stored['vat_lines'][0];

        self::assertEqualsWithDelta(21.00, (float) $line['vat_amount'], 0.001, 'Daň = 121 × 21/121 = 21,00 (§ 37/2).');
        self::assertEqualsWithDelta(100.00, (float) $line['base_amount'], 0.001, 'Základ je zbytek po dani.');
    }

    /**
     * Přepočet se musí promítnout i do DENÍKU — jinak by seděl rozpad, ale zaúčtovaná
     * daň na 343 by zůstala klientova.
     */
    public function testRecomputedTaxReachesJournal(): void
    {
        $reg = $this->makeRegister();

        $res = $this->service->create($this->supplierId, $this->doc([
            'purpose'      => 'sale',
            'doc_type'     => 'in',
            'total_amount' => 121.00,
            'vat_mode'     => 'vat',
            'partner_dic'  => 'CZ12345678',
            'vat_lines'    => [['vat_rate' => 21, 'base_amount' => 110.00, 'vat_amount' => 11.00]],
        ], $reg), $this->userId);

        $byAcc = $this->linesByAccountCode((int) $res['journal_entry_id']);
        self::assertEqualsWithDelta(21.00, $byAcc['343.200']['credit'], 0.001, 'Na 343 patří přepočtená daň.');
        self::assertEqualsWithDelta(100.00, $byAcc['602']['credit'], 0.001);
        self::assertEqualsWithDelta(121.00, $byAcc['211']['debit'], 0.001);
    }

    /**
     * Zadání ZDOLA (§ 37/1) musí projít beze změny. 12 % je sazba, u které se metody
     * rozcházejí nejčastěji — kdyby přepočet zdola zadané doklady posouval, projeví
     * se to právě tady.
     */
    public function testBottomUpEntryPassesUnchanged(): void
    {
        $reg = $this->makeRegister();

        foreach ([[1.12, 12.0], [9.38, 12.0], [826.45, 21.0], [100.00, 21.0]] as [$base, $rate]) {
            $vat = round($base * $rate / 100.0, 2);
            $gross = round($base + $vat, 2);

            $res = $this->service->create($this->supplierId, $this->doc([
                'purpose'      => 'sale',
                'doc_type'     => 'in',
                'total_amount' => $gross,
                'vat_mode'     => 'vat',
                'partner_dic'  => 'CZ12345678',
                'vat_lines'    => [['vat_rate' => $rate, 'base_amount' => $base, 'vat_amount' => $vat]],
            ], $reg), $this->userId);

            $line = $this->service->get($this->supplierId, $res['id'])['vat_lines'][0];
            self::assertEqualsWithDelta($base, (float) $line['base_amount'], 0.001, "Základ zdola {$base} @ {$rate} % se nesmí změnit.");
            self::assertEqualsWithDelta($vat, (float) $line['vat_amount'], 0.001, "Daň zdola {$vat} @ {$rate} % se nesmí změnit.");
        }
    }

    /**
     * Dva řádky téže sazby: součet řádkové daně musí odpovídat dani z jejich součtu
     * (distribuce rezidua per sazba, shodně s fakturou). Bez ní by se rozpad
     * pokladny a faktury na týchž částkách rozešel o haléř.
     */
    public function testSameRateLinesDistributeRoundingResidual(): void
    {
        $reg = $this->makeRegister();

        // 2 × 10,00 Kč @ 21 %: po řádcích 1,74 + 1,74 = 3,48; z celku 20,00 × 21/121 = 3,47.
        // Bez distribuce rezidua by pokladna odvedla o haléř víc než faktura na týchž částkách.
        $res = $this->service->create($this->supplierId, $this->doc([
            'purpose'      => 'sale',
            'doc_type'     => 'in',
            'total_amount' => 20.00,
            'vat_mode'     => 'vat',
            'partner_dic'  => 'CZ12345678',
            'vat_lines'    => [
                ['vat_rate' => 21, 'base_amount' => 8.26, 'vat_amount' => 1.74],
                ['vat_rate' => 21, 'base_amount' => 8.26, 'vat_amount' => 1.74],
            ],
        ], $reg), $this->userId);

        $stored = $this->service->get($this->supplierId, $res['id']);
        $sumVat = 0.0;
        $sumAll = 0.0;
        foreach ($stored['vat_lines'] as $vl) {
            $sumVat += (float) $vl['vat_amount'];
            $sumAll += (float) $vl['base_amount'] + (float) $vl['vat_amount'];
        }

        self::assertEqualsWithDelta(
            round(20.00 * 21.0 / 121.0, 2),
            $sumVat,
            0.001,
            'Σ řádkové daně = daň z celkového brutta dané sazby (3,47, ne 3,48).',
        );
        self::assertEqualsWithDelta(20.00, $sumAll, 0.001, 'Σ(základ+daň) zůstává rovno celkové částce.');
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function makeRegister(): int
    {
        return $this->registers->create($this->supplierId, [
            'name'          => 'Test pokladna DPH ' . uniqid(),
            'account_code'  => '211',
            'currency_code' => 'CZK',
            'is_default'    => true,
        ]);
    }

    /**
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function doc(array $over, int $registerId): array
    {
        return array_merge([
            'register_id' => $registerId,
            'issue_date'  => self::YEAR . '-06-15',
            'description' => 'Pokladní pohyb',
            'post'        => true,
        ], $over);
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
