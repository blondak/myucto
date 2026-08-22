<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Archive\ArchiveService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Integrační testy per-firma archivace (Epic F4, §6.2 I23, R15): export ZIP
 * s manifestem (sha256, počty řádků), tenant izolace exportovaných dat a delete.
 *
 * DB část běží v transakci s rollbackem; soubory na disku (storage/archives)
 * uklízí tearDown. Izolovaný supplier, soft-skip bez cfg.php.
 */
#[Group('integration')]
final class ArchiveExportTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private ArchiveService $archive;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $otherSupplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    /** @var list<string> soubory ke smazání v tearDown */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->archive = $container->get(ArchiveService::class);
            $this->posting = $container->get(PostingService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId   = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId    = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId         = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $create = function (string $name, string $email) use ($pdo, $czId, $currencyId, $vatRateId): int {
            $stmt = $pdo->prepare(
                'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
                 VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $czId, $email, $currencyId, $vatRateId]);
            return (int) $pdo->lastInsertId();
        };
        $this->supplierId = $create('F4 archiv test s.r.o.', 'f4-archiv@example.com');
        $this->otherSupplierId = $create('F4 archiv cizí s.r.o.', 'f4-archiv-b@example.com');

        $seeder->seedForSupplier($this->supplierId);
        $seeder->seedForSupplier($this->otherSupplierId);
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->periods->create($this->otherSupplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');

        // Data OBOU tenantů — izolace exportu se musí projevit.
        $this->manual($this->supplierId, 1000.00);
        $this->manual($this->supplierId, 250.00);
        $this->manual($this->otherSupplierId, 999.00);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        foreach ([$this->supplierId, $this->otherSupplierId] as $sid) {
            if ($sid === 0) {
                continue;
            }
            $dir = RuntimePaths::storage('archives/sup-' . $sid);
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($dir);
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

    // ── I23: export — manifest, sha256, počty, tenant izolace, delete ────────

    public function testI23ExportManifestChecksumsAndTenantIsolation(): void
    {
        $meta = $this->archive->export($this->supplierId, $this->userId);
        $path = $this->archive->filePath($this->supplierId, $meta);
        $this->tempFiles[] = $path;

        self::assertFileExists($path, 'ZIP archiv existuje.');
        self::assertSame(hash_file('sha256', $path), $meta['sha256'], 'sha256 celého ZIP v metadatech sedí.');
        self::assertStringStartsWith('myucto-archiv-sup' . $this->supplierId . '-', (string) $meta['filename']);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        self::assertIsArray($manifest, 'manifest.json je parsovatelný.');
        self::assertSame('myucto-archive', $manifest['format']);
        self::assertSame(2, (int) $manifest['version']);
        self::assertArrayHasKey('supplier', $manifest['tables'], 'Archiv obsahuje master řádek firmy (nutný pro obnovu).');
        self::assertSame($this->supplierId, (int) $manifest['supplier']['id']);
        self::assertNotSame('unknown', (string) $manifest['schema_version'], 'schema_version = nejvyšší aplikovaná migrace.');

        // sha256 + počty řádků každé tabulky proti manifestu
        foreach ($manifest['tables'] as $table => $info) {
            $content = $zip->getFromName($table . '.jsonl');
            self::assertNotFalse($content, "{$table}.jsonl v archivu existuje.");
            self::assertSame($info['sha256'], hash('sha256', $content), "sha256 {$table}.jsonl sedí s manifestem.");
            $lines = $content === '' ? [] : explode("\n", trim($content));
            $lines = array_values(array_filter($lines, static fn (string $l): bool => $l !== ''));
            self::assertCount((int) $info['rows'], $lines, "Počet řádků {$table} sedí s manifestem.");
        }

        // Počty řádků == DB (journal_entries, chart_of_accounts, accounting_periods)
        foreach (['journal_entries', 'chart_of_accounts', 'accounting_periods'] as $table) {
            $dbCount = (int) $this->db->pdo()->query(
                "SELECT COUNT(*) FROM {$table} WHERE supplier_id = {$this->supplierId}"
            )->fetchColumn();
            self::assertSame($dbCount, (int) $manifest['tables'][$table]['rows'], "Počet řádků {$table} == DB.");
        }

        // Tenant izolace: každý exportovaný řádek nese supplier_id archivované firmy
        foreach (['journal_entries', 'journal_entry_lines', 'chart_of_accounts', 'invoices'] as $table) {
            $content = (string) $zip->getFromName($table . '.jsonl');
            foreach (explode("\n", trim($content)) as $line) {
                if ($line === '') {
                    continue;
                }
                $row = json_decode($line, true);
                self::assertSame(
                    $this->supplierId,
                    (int) $row['supplier_id'],
                    "Archiv {$table} obsahuje POUZE data archivované firmy (tenant izolace!).",
                );
            }
        }
        // Cizí supplier má vlastní zápis (999) — v našem archivu být nesmí
        $entries = (string) $zip->getFromName('journal_entry_lines.jsonl');
        self::assertStringNotContainsString('999.00', $entries, 'Zápis cizího tenanta v archivu není.');
        $zip->close();

        // Metadata v accounting_archives + delete smaže soubor i řádek
        self::assertNotNull($this->archive->find($this->supplierId, (int) $meta['id']));
        $deleted = $this->archive->delete($this->supplierId, (int) $meta['id']);
        self::assertNotNull($deleted);
        self::assertFileDoesNotExist($path, 'Delete smaže ZIP.');
        self::assertNull($this->archive->find($this->supplierId, (int) $meta['id']), 'Delete smaže řádek metadat.');
    }

    public function testI23CrossTenantAccessDenied(): void
    {
        $meta = $this->archive->export($this->supplierId, $this->userId);
        $this->tempFiles[] = $this->archive->filePath($this->supplierId, $meta);

        self::assertNull($this->archive->find($this->otherSupplierId, (int) $meta['id']), 'Cizí tenant metadata nevidí.');
        self::assertNull($this->archive->delete($this->otherSupplierId, (int) $meta['id']), 'Cizí tenant nesmaže cizí archiv.');
        self::assertNotNull($this->archive->find($this->supplierId, (int) $meta['id']), 'Archiv vlastníka zůstává.');
    }

    // ⚠️ Dřívější test I24 pouštěl `api/bin/archive-restore.php` nad TÍMHLE
    // per-firemním archivem. Skript ale od 5.23.0 obnovuje kompletní export
    // instance (formát `myucto-instance-export`) a per-firemní ZIP odmítá.
    // Jeho exit kódy proto hlídá InstanceExportManifestTest, kde vzniká
    // archiv, kterému skript rozumí.

    // ── helpers ──────────────────────────────────────────────────────────────

    private function manual(int $supplierId, float $amount): void
    {
        $this->posting->postDocument($supplierId, 'manual', null, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => $amount],
            ['account_code' => '602', 'side' => 'credit', 'amount' => $amount],
        ], ['entry_date' => self::YEAR . '-05-01', 'posted_by' => $this->userId]);
    }
}
