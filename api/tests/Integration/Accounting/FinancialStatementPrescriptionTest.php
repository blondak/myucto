<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Výkazy podle předpisů, které se dřív nepromítaly:
 *   - čistý obrat od 1. 1. 2024 = výnosy z prodeje výrobků, zboží a služeb (§ 1a odst. 2 ZoÚ,
 *     § 35 vyhl. 500/2002 Sb.), dřív součet všech výnosů; za minulé období spočtené po
 *     staru se ve výkazu za první rok nového pojetí neuvádí,
 *   - první účetní období má ve sloupci minulého období rozvahy zahajovací rozvahu
 *     (§ 4 odst. 7 vyhl.), VZZ minulé období nemá,
 *   - oprávky a opravné položky patří k položce, ke které se vztahují (migrace 1826),
 *   - tržby z prodeje podílů (661) jsou výnosy z finančního majetku - podíly (IV.), ne VII.
 * Syntetická data v izolovaném dodavateli, vše v transakci s rollbackem.
 */
#[Group('integration')]
final class FinancialStatementPrescriptionTest extends TestCase
{
    private Connection $db;
    private PostingService $posting;
    private FinancialStatementService $statements;
    private AccountingPeriodRepository $periods;
    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->statements = $container->get(FinancialStatementService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "vykazy-predpisy@example.com", ?, ?)'
        )->execute(['Výkazy podle předpisů s.r.o.', $czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testNetTurnoverFrom2024CountsOnlySalesOfProductsGoodsAndServices(): void
    {
        $period = $this->period(2024);
        $this->post('311', '602', 1000000.00, '2024-03-01');
        $this->post('311', '604', 500000.00, '2024-04-01');
        $this->post('311', '648', 10000.00, '2024-05-01');
        $this->post('221', '662', 30000.00, '2024-06-30');
        $this->post('221', '663', 20000.00, '2024-07-31');

        $vzz = $this->statements->incomeStatement($this->supplierId, $period, '2024-12-31', 'full');

        self::assertSame(150000000, $this->cents($vzz['rows'], 'OBRAT', 'amount'), 'OBRAT = I. + II.');
        self::assertSame(150000000, (int) round($vzz['checks']['net_turnover'] * 100));
    }

    /**
     * Období započaté před 1. 1. 2024 zůstává u původního součtu výnosů (přechodné
     * ustanovení). Ve výkazu za 2024 se čistý obrat minulého období neuvádí, protože jde
     * o jinou veličinu; ostatní řádky minulého období zůstávají.
     */
    public function testNetTurnoverBefore2024KeepsOldSumAndIsNotComparedIn2024(): void
    {
        $p2023 = $this->period(2023);
        $p2024 = $this->period(2024);
        $this->post('311', '602', 100000.00, '2023-05-01');
        $this->post('221', '662', 5000.00, '2023-06-30');
        $this->post('311', '602', 200000.00, '2024-05-01');
        $this->post('221', '662', 7000.00, '2024-06-30');

        $vzz2023 = $this->statements->incomeStatement($this->supplierId, $p2023, '2023-12-31', 'full');
        $vzz2024 = $this->statements->incomeStatement($this->supplierId, $p2024, '2024-12-31', 'full');

        self::assertSame(10500000, $this->cents($vzz2023['rows'], 'OBRAT', 'amount'), '2023: I. až VII.');
        self::assertSame(20000000, $this->cents($vzz2024['rows'], 'OBRAT', 'amount'), '2024: jen I. + II.');
        self::assertSame(0, $this->cents($vzz2024['rows'], 'OBRAT', 'prev_amount'), 'minulé období se neuvádí');
        self::assertSame(10000000, $this->cents($vzz2024['rows'], 'I.', 'prev_amount'), 'ostatní řádky minulého období zůstávají');
    }

    /**
     * § 35 vyhl. 500/2002 Sb. nechává na úsudku firmy, které další výnosy patří k jejímu
     * obchodnímu modelu. Vybrané řádky se přičtou k I. + II., v účelovém členění k I.
     */
    public function testNetTurnoverAddsRowsTheCompanyCountsToItsBusinessModel(): void
    {
        $period = $this->period(2024);
        $this->post('311', '602', 1000000.00, '2024-03-01');
        $this->post('311', '604', 500000.00, '2024-04-01');
        $this->post('311', '641', 200000.00, '2024-05-01');
        $this->post('311', '648', 10000.00, '2024-05-02');
        $this->post('221', '665', 30000.00, '2024-06-30');
        $this->post('221', '662', 20000.00, '2024-06-30');
        $this->post('221', '663', 5000.00, '2024-07-31');
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, net_turnover_extra_rows) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE net_turnover_extra_rows = VALUES(net_turnover_extra_rows)'
        )->execute([$this->supplierId, json_encode([
            'income_statement' => ['III.1.', 'IV.', 'VI.', 'VI.2.'],
            'income_statement_purpose' => ['III.'],
        ])]);

        $vzz = $this->statements->incomeStatement($this->supplierId, $period, '2024-12-31', 'full');
        $purpose = $this->statements->incomeStatementByFunction($this->supplierId, $period, '2024-12-31', 'full');

        // I. + II. + III.1. + IV. + VI. (VI.2. je ve VI. už obsažené, nepočítá se dvakrát)
        self::assertSame(175000000, $this->cents($vzz['rows'], 'OBRAT', 'amount'));
        // účelové: I. + III. (výnosy z podílů 665)
        self::assertSame(153000000, $this->cents($purpose['rows'], 'OBRAT', 'amount'));
    }

    public function testFirstPeriodComparesWithOpeningBalanceSheet(): void
    {
        $period = $this->period(2024);
        $this->post('353', '411', 200000.00, '2024-01-01');
        $this->post('221', '353', 200000.00, '2024-02-15');
        $this->post('311', '602', 50000.00, '2024-06-30');

        $bs = $this->statements->balanceSheet($this->supplierId, $period, '2024-12-31', 'full');
        $vzz = $this->statements->incomeStatement($this->supplierId, $period, '2024-12-31', 'full');

        self::assertSame(20000000, $this->cents($bs['assets'], 'A.', 'prev_net'), 'zahajovací rozvaha: pohledávka za upsaný ZK');
        self::assertSame(20000000, $this->cents($bs['assets'], 'AKTIVA', 'prev_net'));
        self::assertSame(20000000, $this->cents($bs['liabilities'], 'P.A.I.1.', 'prev_amount'));
        self::assertSame(0, $this->cents($bs['assets'], 'A.', 'net'), 'na konci roku splaceno');
        self::assertSame(0, $this->cents($vzz['rows'], 'I.', 'prev_amount'), 'VZZ minulé období nemá');
    }

    public function testCorrectionsBelongToTheirItemsAndReachTheAppendix(): void
    {
        $period = $this->period(2024);
        $this->post('021', '321', 1000000.00, '2024-01-10');
        $this->post('022', '321', 300000.00, '2024-01-10');
        $this->post('551', '081', 100000.00, '2024-12-31');
        $this->post('551', '082', 60000.00, '2024-12-31');
        $this->post('557', '092', 13000.00, '2024-12-31');
        $this->post('311', '602', 100000.00, '2024-06-30');
        $this->post('558', '391', 20000.00, '2024-12-31');

        $bs = $this->statements->balanceSheet($this->supplierId, $period, '2024-12-31', 'full');

        self::assertSame(10000000, $this->cents($bs['assets'], 'B.II.1.2.', 'correction'), 'oprávky 081 u staveb');
        self::assertSame(6000000, $this->cents($bs['assets'], 'B.II.2.', 'correction'), 'oprávky 082 u movitých věcí');
        self::assertSame(17300000, $this->cents($bs['assets'], 'B.II.', 'correction'), 'B.II. = listy + 092 na rodiči');
        self::assertSame(2000000, $this->cents($bs['assets'], 'C.II.2.1.', 'correction'), 'opravná položka 391 u odběratelů');

        $xml = $this->appendixXml($bs, $period);
        // 092 (13 tis.) se rozpočítá poměrem brutta: stavby 10 tis., movité věci 3 tis.
        self::assertStringContainsString('<VetaUA c_radku="14" kc_brutto="1300" kc_korekce="173" kc_netto="1127" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="15" kc_brutto="1000" kc_korekce="110" kc_netto="890" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="17" kc_brutto="1000" kc_korekce="110" kc_netto="890" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="18" kc_brutto="300" kc_korekce="63" kc_netto="237" kc_netto_min="0"/>', $xml);
        self::assertStringContainsString('<VetaUA c_radku="58" kc_brutto="100" kc_korekce="20" kc_netto="80" kc_netto_min="0"/>', $xml);
    }

    public function testSaleOfSharesIsFinancialIncomeFromShares(): void
    {
        $period = $this->period(2024);
        $this->post('221', '661', 40000.00, '2024-05-01');

        $vzz = $this->statements->incomeStatement($this->supplierId, $period, '2024-12-31', 'full');

        self::assertSame(4000000, $this->cents($vzz['rows'], 'IV.2.', 'amount'));
        self::assertSame(4000000, $this->cents($vzz['rows'], 'IV.', 'amount'));
        self::assertSame(0, $this->cents($vzz['rows'], 'VII.', 'amount'));

        $purpose = $this->db->pdo()->query(
            "SELECT m.row_code FROM statement_account_map m
               JOIN statement_versions sv ON sv.id = m.version_id
              WHERE sv.statement_type = 'income_statement_purpose' AND m.account_prefix = '661'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['III.'], $purpose, 'účelové členění: výnosy z dlouhodobého finančního majetku - podíly');
    }

    /** @param array<string,mixed> $balanceSheet */
    private function appendixXml(array $balanceSheet, int $period): string
    {
        $calc = (new DppoReturnCalculator())->compute(
            ['vh' => 0, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            ['tax_paid_advances' => 0],
            TaxConstants::forYear(2024)
        );
        $supplier = [
            'company_name' => 'Výkazy podle předpisů s.r.o.', 'street' => 'Testovací 1', 'city' => 'Praha',
            'zip' => '11000', 'country_iso2' => 'CZ', 'ic' => '12345678', 'dic' => 'CZ12345678',
            'taxpayer_type' => 'po', 'financial_office_code' => '451', 'cz_nace_code' => '62020',
        ];
        return (new DppoXmlBuilder())->build($supplier, 2024, $calc, [], [
            'balance_sheet' => $balanceSheet,
            'income_statement' => $this->statements->incomeStatement($this->supplierId, $period, '2024-12-31', 'full'),
        ])['xml'];
    }

    private function period(int $year): int
    {
        return $this->periods->create($this->supplierId, $year, $year . '-01-01', $year . '-12-31');
    }

    private function post(string $debit, string $credit, float $amount, string $date): void
    {
        $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            [
                ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
                ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
            ],
            ['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );
    }

    /** @param list<array<string,mixed>> $rows */
    private function cents(array $rows, string $code, string $column): ?int
    {
        foreach ($rows as $row) {
            if ((string) $row['row_code'] === $code) {
                return (int) round(((float) $row[$column]) * 100.0);
            }
        }
        return null;
    }
}
