<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Od zaúčtování po přílohu přiznání DPPO: firma s prodaným zbožím (504), prodejem majetku
 * (641/541), jinými provozními výnosy (648), úroky (562/662) a výnosy z podílů (665) musí
 * mít v příloze stejné tisíce, jaké ukazuje výkaz zisku a ztráty. Finanční položky se
 * podle vyhlášky 500/2002 Sb. (příloha 2) člení na „ovládaná nebo ovládající osoba"
 * a „ostatní" (migrace 1824); výchozí mapa účtů vede vše do „ostatních".
 * Syntetická data v izolovaném dodavateli, vše v transakci s rollbackem.
 */
#[Group('integration')]
final class DppoAppendixStatementRowsTest extends TestCase
{
    private const YEAR = 2099;
    private const AS_OF = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private FinancialStatementService $statements;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->statements = $container->get(FinancialStatementService::class);
            $periods = $container->get(AccountingPeriodRepository::class);
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
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "dppo-priloha@example.com", ?, ?)'
        )->execute(['Příloha DPPO test s.r.o.', $czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::AS_OF);
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

    public function testAppendixCarriesEveryIncomeStatementRowTheStatementHas(): void
    {
        $this->post('311', '604', 3000000.00, '03-01');
        $this->post('504', '321', 1028742.00, '03-01');
        $this->post('311', '641', 2370000.00, '04-01');
        $this->post('541', '321', 1290000.00, '04-01');
        $this->post('311', '648', 275000.00, '05-01');
        $this->post('562', '321', 21000.00, '06-30');
        $this->post('221', '662', 5000.00, '06-30');
        $this->post('221', '665', 10000.00, '09-30');

        $vzz = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $amount = fn (string $code): ?int => $this->cents($vzz['rows'], $code);

        self::assertSame(2100000, $amount('J.2.'), 'Úroky 562 jsou ve výchozí mapě „ostatní" (J.2.).');
        self::assertSame(0, $amount('J.1.'));
        self::assertSame(2100000, $amount('J.'), 'J. je mezisoučet J.1.+J.2.');
        self::assertSame(500000, $amount('VI.2.'));
        self::assertSame(1000000, $amount('IV.2.'));
        self::assertSame(-600000, $amount('FVH'), 'FVH = IV. + VI. − J. = 10 000 + 5 000 − 21 000.');

        $xml = $this->appendixXml($vzz);
        foreach ([
            2 => 3000, 3 => 1029, 4 => 1029, 20 => 2645, 21 => 2370, 23 => 275, 24 => 1290, 25 => 1290,
            31 => 10, 33 => 10, 39 => 5, 41 => 5, 43 => 21, 45 => 21,
        ] as $cRadku => $thousands) {
            self::assertStringContainsString(
                sprintf('<VetaUB c_radku="%d" kc_min="0" kc_sled="%d"/>', $cRadku, $thousands),
                $xml,
                "ř. {$cRadku}",
            );
        }
        foreach ([32, 40, 44] as $cRadku) {
            self::assertStringNotContainsString(sprintf('<VetaUB c_radku="%d"', $cRadku), $xml, "ř. {$cRadku} (spřízněná osoba) má zůstat prázdný");
        }
    }

    /** Zkrácený výkaz (úroveň 1) ukazuje finanční položky jako dřív, jen jako součet podřádků. */
    public function testShortIncomeStatementKeepsFinancialTotals(): void
    {
        $this->post('562', '321', 21000.00, '06-30');
        $this->post('221', '662', 5000.00, '06-30');

        $vzz = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'small');

        self::assertSame(2100000, $this->cents($vzz['rows'], 'J.'));
        self::assertSame(500000, $this->cents($vzz['rows'], 'VI.'));
        self::assertNull($this->cents($vzz['rows'], 'J.2.'), 'Zkrácený výkaz podřádky neukazuje.');
    }

    /** Řádky rozpadu stojí ve stromu výkazu hned pod rodičem a výchozí mapa vede vše do „ostatních". */
    public function testRelatedPartySplitRowsAndDefaultMapping(): void
    {
        $pdo = $this->db->pdo();
        $rows = $pdo->query(
            "SELECT sr.row_code, sr.parent_row_code, sr.level, sr.row_type, sr.position
               FROM statement_rows sr
               JOIN statement_versions sv ON sv.id = sr.version_id
              WHERE sv.statement_type = 'income_statement' AND sv.version_code = 'vyhl500-2002/2024'
              ORDER BY sr.position"
        )->fetchAll(PDO::FETCH_ASSOC);
        $order = array_column($rows, 'row_code');
        $byCode = array_column($rows, null, 'row_code');

        self::assertSame(count($order), count(array_unique(array_column($rows, 'position'))), 'Pozice řádků jsou jedinečné.');
        foreach (['IV.', 'V.', 'VI.', 'J.'] as $parent) {
            self::assertSame('subtotal', $byCode[$parent]['row_type'], "{$parent} je mezisoučet");
            $at = array_search($parent, $order, true);
            self::assertSame([$parent . '1.', $parent . '2.'], array_slice($order, (int) $at + 1, 2), "podřádky {$parent} hned pod rodičem");
            foreach (['1.', '2.'] as $suffix) {
                self::assertSame($parent, $byCode[$parent . $suffix]['parent_row_code']);
                self::assertSame(2, (int) $byCode[$parent . $suffix]['level']);
                self::assertSame('detail', $byCode[$parent . $suffix]['row_type']);
            }
        }

        $map = [];
        $stmt = $pdo->query(
            "SELECT m.account_prefix, m.row_code
               FROM statement_account_map m
               JOIN statement_versions sv ON sv.id = m.version_id
              WHERE sv.statement_type = 'income_statement' AND sv.version_code = 'vyhl500-2002/2024'
                AND m.row_code IN ('IV.', 'V.', 'VI.', 'J.', 'IV.1.', 'V.1.', 'VI.1.', 'J.1.', 'IV.2.', 'V.2.', 'VI.2.', 'J.2.')
              ORDER BY m.account_prefix"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(string) $r['account_prefix']] = (string) $r['row_code'];
        }
        self::assertSame(['562' => 'J.2.', '661' => 'IV.2.', '662' => 'VI.2.', '665' => 'IV.2.', '666' => 'VI.2.'], $map);
    }

    /** Účelové členění (verze 3) má vlastní mapu, rozpad druhového členění se ho netýká. */
    public function testPurposeVersionMappingIsUntouched(): void
    {
        $map = [];
        $stmt = $this->db->pdo()->query(
            "SELECT m.account_prefix, m.row_code
               FROM statement_account_map m
               JOIN statement_versions sv ON sv.id = m.version_id
              WHERE sv.statement_type = 'income_statement_purpose'
                AND m.account_prefix IN ('562', '662', '665', '666')
              ORDER BY m.account_prefix"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(string) $r['account_prefix']] = (string) $r['row_code'];
        }
        self::assertSame(['562' => 'H.', '662' => 'V.', '665' => 'III.', '666' => 'V.'], $map);
    }

    /** @param array<string,mixed> $incomeStatement */
    private function appendixXml(array $incomeStatement): string
    {
        $calc = (new DppoReturnCalculator())->compute(
            ['vh' => 0, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            ['tax_paid_advances' => 0],
            TaxConstants::forYear(2025)
        );
        $supplier = [
            'company_name' => 'Příloha DPPO test s.r.o.', 'street' => 'Testovací 1', 'city' => 'Praha',
            'zip' => '11000', 'country_iso2' => 'CZ', 'ic' => '12345678', 'dic' => 'CZ12345678',
            'taxpayer_type' => 'po', 'financial_office_code' => '451', 'cz_nace_code' => '62020',
        ];
        return (new DppoXmlBuilder())->build($supplier, self::YEAR, $calc, [], [
            'balance_sheet' => $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full'),
            'income_statement' => $incomeStatement,
        ])['xml'];
    }

    private function post(string $debit, string $credit, float $amount, string $monthDay): void
    {
        $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            [
                ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
                ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
            ],
            ['entry_date' => self::YEAR . '-' . $monthDay, 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );
    }

    /** @param list<array<string,mixed>> $rows */
    private function cents(array $rows, string $code): ?int
    {
        foreach ($rows as $row) {
            if ((string) $row['row_code'] === $code) {
                return (int) round(((float) $row['amount']) * 100.0);
            }
        }
        return null;
    }
}
