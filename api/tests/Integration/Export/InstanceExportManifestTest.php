<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Export;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Export\Instance\InstanceExportService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * H-14 — manifest a kontrolní součty.
 *
 * Bez manifestu se po roce nikdo nedopočítá, jestli je archiv úplný, a bez součtů
 * nejde ověřit, že se stáhl celý. Proto se tu kontroluje obojí PROTI SKUTEČNOSTI:
 * počty řádků v manifestu proti `COUNT(*)` v databázi a součty proti obsahu archivu,
 * ne jen samo se sebou.
 */
#[Group('integration')]
final class InstanceExportManifestTest extends TestCase
{
    private Connection $db;
    private InstanceExportService $export;

    private int $supplierId = 0;
    private bool $inTx = false;

    /** @var list<string> */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->export = $container->get(InstanceExportService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovaci 1", "Praha", "11000", ?, ?, ?, ?)'
        );
        $stmt->execute(['H14 manifest s.r.o.', $czId, 'h14-manifest@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        for ($i = 1; $i <= 3; $i++) {
            $c = $pdo->prepare(
                'INSERT INTO clients (supplier_id, company_name, street, city, zip, email)
                 VALUES (?, ?, "Testovaci 2", "Brno", "60200", ?)'
            );
            $c->execute([$this->supplierId, 'H14 odberatel ' . $i, "h14-odberatel-{$i}@example.com"]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if ($this->supplierId !== 0) {
            $dir = RuntimePaths::storage('instance-exports') . DIRECTORY_SEPARATOR . 'sup-' . $this->supplierId;
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    is_dir($file) ? @rmdir($file) : @unlink($file);
                }
                @rmdir($dir);
            }
            @unlink(RuntimePaths::storage('locks') . '/instance-export-sup' . $this->supplierId . '.lock');
        }
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testManifestDescribesArchiveAndCountsMatchDatabase(): void
    {
        $result = $this->exportData();
        $zip = new ZipArchive();
        self::assertTrue($zip->open((string) $result['abs_path']) === true);

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        self::assertIsArray($manifest, 'manifest.json je parsovatelný JSON.');
        self::assertSame('myucto-instance-export', $manifest['format']);
        self::assertSame($this->supplierId, (int) $manifest['supplier']['id'], 'Manifest jmenuje exportovanou firmu.');
        self::assertNotSame('unknown', (string) $manifest['schema_version'], 'Manifest nese verzi schématu.');
        self::assertArrayHasKey('read_started_at', $manifest, 'Manifest nese okno, ve kterém se data četla.');
        self::assertArrayHasKey('read_finished_at', $manifest);
        self::assertSame('non-atomic', $manifest['data_snapshot'], 'Manifest přiznává, že snapshot není atomický.');

        // Počty v manifestu vs. COUNT(*) v DB — ne manifest proti sobě samému.
        $clientsInDb = (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM clients WHERE supplier_id = ' . $this->supplierId
        )->fetchColumn();
        self::assertSame(
            $clientsInDb,
            (int) $manifest['sections']['data']['tables']['clients']['rows'],
            'Počet klientů v manifestu sedí s databází.',
        );
        self::assertSame(1, (int) $manifest['sections']['data']['tables']['supplier']['rows'], 'Master řádek firmy je právě jeden.');

        // Vynechané tabulky jsou v manifestu vidět i s důvodem.
        self::assertArrayHasKey('skipped_tables', $manifest['sections']['data']);
        self::assertArrayHasKey('users', $manifest['sections']['data']['skipped_tables']);

        $zip->close();
    }

    public function testEveryEntryChecksumMatchesItsContent(): void
    {
        $result = $this->exportData();
        $manifestChecksums = (array) ($result['manifest']['checksums'] ?? []);
        self::assertNotSame([], $manifestChecksums, 'Manifest nese kontrolní součty položek.');

        $zip = new ZipArchive();
        self::assertTrue($zip->open((string) $result['abs_path']) === true);

        $verified = 0;
        foreach ($manifestChecksums as $entryName => $meta) {
            $content = $zip->getFromName((string) $entryName);
            self::assertNotFalse($content, "Položka {$entryName} v archivu je.");
            self::assertSame(
                $meta['sha256'],
                hash('sha256', $content),
                "SHA-256 položky {$entryName} nesedí s manifestem.",
            );
            self::assertSame((int) $meta['size'], strlen($content), "Velikost {$entryName} nesedí s manifestem.");
            $verified++;
        }
        self::assertGreaterThan(3, $verified, 'Ověřilo se víc než pár položek.');

        // Řádky JSONL musí odpovídat počtu z manifestu — součet sám o sobě neřekne,
        // že se neztratil celý blok dat.
        foreach ((array) $result['manifest']['sections']['data']['tables'] as $table => $info) {
            if (($info['entry'] ?? null) === null) {
                continue;
            }
            $content = (string) $zip->getFromName((string) $info['entry']);
            $lines = array_values(array_filter(explode("\n", $content), static fn (string $l): bool => trim($l) !== ''));
            self::assertCount((int) $info['rows'], $lines, "Počet řádků {$table} sedí s manifestem.");
        }
        $zip->close();
    }

    /** Součet celého archivu je vedle něj i v návratové hodnotě — podle něj se pozná useknuté stažení. */
    public function testWholeArchiveChecksumIsRecordedNextToIt(): void
    {
        $result = $this->exportData();
        $absPath = (string) $result['abs_path'];

        self::assertSame(hash_file('sha256', $absPath), $result['sha256'], 'Vrácený součet sedí se souborem.');
        self::assertFileExists($absPath . '.sha256', 'Vedle archivu leží sidecar se součtem.');
        self::assertStringContainsString(
            (string) $result['sha256'],
            (string) file_get_contents($absPath . '.sha256'),
            'Sidecar obsahuje součet archivu.',
        );
    }

    /** Archiv musí být čitelný bez naší aplikace — návod a strojový popis jsou uvnitř. */
    public function testArchiveIsSelfDescribing(): void
    {
        $result = $this->exportData();
        $zip = new ZipArchive();
        self::assertTrue($zip->open((string) $result['abs_path']) === true);

        foreach (['manifest.json', 'CHECKSUMS.txt', 'CTI-MNE.txt'] as $entry) {
            self::assertNotFalse($zip->getFromName($entry), "Archiv obsahuje {$entry}.");
        }
        $readme = (string) $zip->getFromName('CTI-MNE.txt');
        self::assertStringContainsString('JSON Lines', $readme, 'Návod říká, v jakém formátu data jsou.');
        self::assertStringContainsString('sha256sum', $readme, 'Návod říká, jak ověřit úplnost.');

        $checksums = (string) $zip->getFromName('CHECKSUMS.txt');
        self::assertStringContainsString('data/clients.jsonl', $checksums, 'CHECKSUMS.txt vyjmenovává položky.');
        $zip->close();
    }

    /**
     * @return array<string,mixed>
     */
    private function exportData(): array
    {
        $result = $this->export->runForSupplier($this->supplierId, [InstanceExportService::PART_DATA]);
        $this->tempPaths[] = (string) $result['abs_path'];
        $this->tempPaths[] = (string) $result['abs_path'] . '.sha256';
        return $result;
    }
}
