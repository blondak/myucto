<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Export\ClosingPackageService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Uzávěrkový balíček nese i pracovní PDF sestavu přiznání k dani z příjmů (DPPO/DPFO)
 * a vedle ní XML, pokud ho balíček nemá z části `income_tax`. Když přiznání za rok
 * sestavit nejde, sestava se vynechá s upozorněním v README a balíček doběhne.
 *
 * Syntetické firmy v transakci s rollbackem, soft-skip bez cfg.php.
 */
#[Group('integration')]
final class ClosingPackageIncomeTaxReportTest extends TestCase
{
    private const YEAR = 2047;
    /** Rok bez daňových konstant: přiznání za něj sestavit nejde. */
    private const YEAR_WITHOUT_CONSTANTS = 2048;

    private Connection $db;
    private ClosingPackageService $package;
    private ImportJobRepository $jobs;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;
    private int $userId = 0;
    private int $czId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private bool $inTx = false;
    /** @var list<string> */
    private array $zipFiles = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->package = $container->get(ClosingPackageService::class);
            $this->jobs = $container->get(ImportJobRepository::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->czId === 0 || $this->currencyId === 0 || $this->vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $constants = \MyInvoice\Service\Tax\TaxConstants::forYear(2026);
        $constants['year'] = self::YEAR;
        $pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')
            ->execute([self::YEAR, json_encode($constants, JSON_UNESCAPED_UNICODE)]);
        $pdo->prepare('DELETE FROM tax_constants WHERE year = ?')->execute([self::YEAR_WITHOUT_CONSTANTS]);
    }

    protected function tearDown(): void
    {
        foreach ($this->zipFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testPackageOffersTheReportForATaxpayer(): void
    {
        [$supplierId, $periodId] = $this->createPoSupplier(self::YEAR);

        self::assertContains('income_tax_report', ClosingPackageService::ALL_PARTS);
        self::assertSame(1, $this->package->previewCounts($supplierId, $periodId)['income_tax_report'] ?? null);
    }

    public function testPackageContainsDppoReportPdfAndXmlNextToIt(): void
    {
        [$supplierId, $periodId] = $this->createPoSupplier(self::YEAR);

        [$job, $entries] = $this->runPackage($supplierId, $periodId, ['income_tax_report']);

        // XML samo nese upozornění k obsahu přiznání, stav proto smí být „s upozorněními";
        // sestava sama ale selhat nesmí.
        $this->assertReportPartSucceeded($job, $entries);
        $pdf = $entries['Dan-z-prijmu/dppdp9-2047-sestava.pdf'] ?? null;
        self::assertNotNull($pdf, 'Balíček musí nést PDF sestavu DPPO. Obsah: ' . implode(', ', array_keys($entries)));
        self::assertStringStartsWith('%PDF', $pdf);
        $xml = $entries['Dan-z-prijmu/dppdp9-2047.xml'] ?? null;
        self::assertNotNull($xml, 'Bez části income_tax musí XML jet vedle sestavy.');
        self::assertStringContainsString('<DPPDP9', $xml);

        $manifest = json_decode($entries['manifest.json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $manifest['parts']['income_tax_report'] ?? null);
        self::assertStringContainsString('Sestava přiznání k dani z příjmů', $entries['README.txt']);
    }

    public function testXmlIsNotDuplicatedWhenTheIncomeTaxPartIsRequested(): void
    {
        [$supplierId, $periodId] = $this->createPoSupplier(self::YEAR);

        [$job, $entries] = $this->runPackage($supplierId, $periodId, ['income_tax', 'income_tax_report']);

        $this->assertReportPartSucceeded($job, $entries);
        $taxFiles = array_values(array_filter(array_keys($entries), static fn (string $p): bool => str_starts_with($p, 'Dan-z-prijmu/')));
        sort($taxFiles);
        self::assertSame(['Dan-z-prijmu/dppdp9-2047-sestava.pdf', 'Dan-z-prijmu/dppdp9-2047.xml'], $taxFiles);
        $manifest = json_decode($entries['manifest.json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $manifest['parts']['income_tax_report'] ?? null);
    }

    public function testReturnThatCannotBeBuiltDoesNotBreakThePackage(): void
    {
        [$supplierId, $periodId] = $this->createPoSupplier(self::YEAR_WITHOUT_CONSTANTS);

        [$job, $entries] = $this->runPackage($supplierId, $periodId, ['balance_sheet', 'income_tax_report']);

        self::assertSame('completed_with_warnings', $job['status'], (string) ($job['last_error'] ?? ''));
        self::assertArrayHasKey('Rozvaha/rozvaha-2048.pdf', $entries, 'Ostatní sestavy musí vzniknout i bez přiznání.');
        self::assertSame([], array_values(array_filter(array_keys($entries), static fn (string $p): bool => str_starts_with($p, 'Dan-z-prijmu/'))));
        self::assertStringContainsString('Sestava přiznání k dani z příjmů:', $entries['README.txt']);
        $manifest = json_decode($entries['manifest.json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('income_tax_report', $manifest['parts']);
        self::assertNotEmpty(array_filter($manifest['warnings'], static fn (string $w): bool => str_starts_with($w, 'Sestava přiznání k dani z příjmů:')));
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,string> $entries
     */
    private function assertReportPartSucceeded(array $job, array $entries): void
    {
        self::assertContains($job['status'], ['completed', 'completed_with_warnings'], (string) ($job['last_error'] ?? ''));
        self::assertArrayHasKey('manifest.json', $entries);
        $manifest = json_decode($entries['manifest.json'], true, 512, JSON_THROW_ON_ERROR);
        $own = array_filter($manifest['warnings'], static fn (string $w): bool => str_starts_with($w, 'Sestava přiznání'));
        self::assertSame([], array_values($own), 'Sestava přiznání nesmí v balíčku selhat.');
    }

    /**
     * @param list<string> $parts
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    private function runPackage(int $supplierId, int $periodId, array $parts): array
    {
        $jobId = $this->jobs->create($supplierId, 'closing_package', [
            'period_id' => $periodId, 'parts' => $parts, 'include_xlsx' => false,
        ], $this->userId);
        $this->package->run($jobId);

        $job = $this->jobs->findById($jobId);
        self::assertNotNull($job);
        $entries = [];
        if (!empty($job['result_path'])) {
            $file = $this->package->resolveResultPath((string) $job['result_path']);
            $this->zipFiles[] = $file;
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($file) === true, 'ZIP balíčku nejde otevřít.');
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $entries[$name] = (string) $zip->getFromIndex($i);
            }
            $zip->close();
        }
        return [$job, $entries];
    }

    /** @return array{0:int,1:int} */
    private function createPoSupplier(int $year): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id,
                                   taxpayer_type, accounting_mode, ic, dic, financial_office_code, cz_nace_code, opr_jmeno, opr_prijmeni, opr_postaveni)
             VALUES (?, "Zkušební 123/4", "Vzorov", "10000", ?, "balik-po@example.com", ?, ?,
                     "po", "double_entry", "12345678", "CZ12345678", "451", "62020", "Jan", "Novák", "jednatel")'
        )->execute(['Syntetický balík s.r.o.', $this->czId, $this->currencyId, $this->vatRateId]);
        $supplierId = (int) $pdo->lastInsertId();

        $this->seeder->seedForSupplier($supplierId);
        $this->periods->create($supplierId, $year, $year . '-01-01', $year . '-12-31');
        $periodId = (int) $this->periods->findByYear($supplierId, $year)['id'];
        $accounts = [];
        foreach ($pdo->query("SELECT account_code, id FROM chart_of_accounts WHERE supplier_id = {$supplierId}")->fetchAll(\PDO::FETCH_KEY_PAIR) as $code => $id) {
            $accounts[(string) $code] = (int) $id;
        }
        $post = function (string $date, array $lines) use ($pdo, $supplierId, $periodId, $accounts): void {
            $pdo->prepare('INSERT INTO journal_entries (supplier_id, period_id, entry_date, posted_at, source_type) VALUES (?, ?, ?, NOW(), ?)')
                ->execute([$supplierId, $periodId, $date, 'manual']);
            $entryId = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount) VALUES (?, ?, ?, ?, ?)');
            foreach ($lines as [$code, $side, $amount]) {
                $ins->execute([$entryId, $supplierId, $accounts[$code], $side, $amount]);
            }
        };
        $post($year . '-03-15', [['311', 'debit', 1000000], ['602', 'credit', 1000000]]);
        $post($year . '-04-10', [['518', 'debit', 300000], ['321', 'credit', 300000]]);
        return [$supplierId, $periodId];
    }
}
