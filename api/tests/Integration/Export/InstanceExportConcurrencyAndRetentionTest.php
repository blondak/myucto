<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Export;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Export\Instance\InstanceExportException;
use MyInvoice\Service\Export\Instance\InstanceExportJobStore;
use MyInvoice\Service\Export\Instance\InstanceExportLock;
use MyInvoice\Service\Export\Instance\InstanceExportService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * H-14 — souběh a expirace.
 *
 * Dvě vlastnosti, které drží export mimo roli DoS vektoru a mimo roli žrouta kvóty:
 *   • jeden běžící export na firmu (DB UNIQUE + souborový zámek),
 *   • hotový archiv se po expiraci uklidí sám.
 *
 * K tomu ještě `reapStale()`: bez něj by jeden spadlý proces zablokoval firmě
 * spouštění exportů navždy, protože by v DB navěky visel jako `running`.
 */
#[Group('integration')]
final class InstanceExportConcurrencyAndRetentionTest extends TestCase
{
    private Connection $db;
    private InstanceExportJobStore $jobs;
    private InstanceExportService $export;

    private int $supplierId = 0;
    private int $otherSupplierId = 0;
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
            $this->jobs = $container->get(InstanceExportJobStore::class);
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

        $create = static function (string $name, string $email) use ($pdo, $czId, $currencyId, $vatRateId): int {
            $stmt = $pdo->prepare(
                'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
                 VALUES (?, "Testovaci 1", "Praha", "11000", ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $czId, $email, $currencyId, $vatRateId]);
            return (int) $pdo->lastInsertId();
        };
        $this->supplierId = $create('H14 souběh s.r.o.', 'h14-soubeh@example.com');
        $this->otherSupplierId = $create('H14 souběh druhá s.r.o.', 'h14-soubeh-b@example.com');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        foreach ([$this->supplierId, $this->otherSupplierId] as $sid) {
            if ($sid === 0) {
                continue;
            }
            $dir = RuntimePaths::storage('instance-exports') . DIRECTORY_SEPARATOR . 'sup-' . $sid;
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    is_dir($file) ? @rmdir($file) : @unlink($file);
                }
                @rmdir($dir);
            }
            @unlink(RuntimePaths::storage('locks') . '/instance-export-sup' . $sid . '.lock');
        }
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    // ── souběh ────────────────────────────────────────────────────────────────

    public function testSecondConcurrentExportIsRefused(): void
    {
        $first = $this->jobs->create($this->supplierId, [InstanceExportService::PART_DATA], null, null, null);
        self::assertGreaterThan(0, $first);

        try {
            $this->jobs->create($this->supplierId, [InstanceExportService::PART_DATA], null, null, null);
            self::fail('Druhý souběžný export téže firmy se měl odmítnout.');
        } catch (InstanceExportException $e) {
            self::assertSame('already_running', $e->errorCode);
            self::assertSame(409, $e->httpStatus, 'Odmítnutí je konflikt, ne serverová chyba.');
        }

        // Odmítá se souběh JEDNÉ firmy, ne provoz účtárny — druhá firma smí paralelně.
        $other = $this->jobs->create($this->otherSupplierId, [InstanceExportService::PART_DATA], null, null, null);
        self::assertGreaterThan(0, $other, 'Jiná firma smí exportovat souběžně.');
    }

    public function testFinishedExportUnblocksTheNextOne(): void
    {
        $first = $this->jobs->create($this->supplierId, [InstanceExportService::PART_DATA], null, null, null);
        $this->jobs->markRunning($first);
        $this->jobs->markCompleted($first);

        $second = $this->jobs->create($this->supplierId, [InstanceExportService::PART_DATA], null, null, null);
        self::assertGreaterThan($first, $second, 'Po dokončení jde spustit další export.');
    }

    /** Mrtvý worker nesmí firmě zablokovat exporty navždy. */
    public function testStaleRunningExportIsReaped(): void
    {
        $jobId = $this->jobs->create($this->supplierId, [InstanceExportService::PART_DATA], null, null, null);
        $this->jobs->markRunning($jobId);
        $this->db->pdo()
            ->prepare('UPDATE instance_exports SET updated_at = (NOW() - INTERVAL 5 HOUR) WHERE id = ?')
            ->execute([$jobId]);

        // create() sám reapuje — jinak by uživatel musel čekat na cron.
        $next = $this->jobs->create($this->supplierId, [InstanceExportService::PART_DATA], null, null, null);
        self::assertGreaterThan(0, $next, 'Po úklidu zaseknutého běhu jde spustit další.');

        $stale = $this->jobs->find($jobId, $this->supplierId);
        self::assertSame('failed', $stale['status'] ?? null, 'Zaseknutý běh skončil jako failed.');
        self::assertStringContainsString('neaktivní', (string) ($stale['last_error'] ?? ''));
    }

    /** Souborový zámek je druhá vrstva — chytí i běh z CLI, který o DB jobu neví. */
    public function testFileLockRefusesSecondRunnerAndReleases(): void
    {
        $first = InstanceExportLock::tryAcquire($this->supplierId);
        self::assertNotNull($first, 'První běh zámek dostane.');
        $this->tempPaths[] = $first->path();

        self::assertNull(
            InstanceExportLock::tryAcquire($this->supplierId),
            'Druhý souběžný běh téže firmy zámek NEDOSTANE (a nečeká ve frontě).',
        );
        $otherLock = InstanceExportLock::tryAcquire($this->otherSupplierId);
        self::assertNotNull($otherLock, 'Zámek je per firma, ne globální.');
        $otherLock->release();

        $first->release();
        $again = InstanceExportLock::tryAcquire($this->supplierId);
        self::assertNotNull($again, 'Po uvolnění jde zámek získat znovu.');
        $again->release();
    }

    // ── expirace ──────────────────────────────────────────────────────────────

    public function testExpiredArchivesAreDeletedAndFreshOnesSurvive(): void
    {
        $expired = $this->seedCompletedExport($this->supplierId, 'expired.zip', '-2 days');
        $fresh = $this->seedCompletedExport($this->otherSupplierId, 'fresh.zip', '+7 days');

        self::assertFileExists($expired['abs'], 'Před úklidem expirovaný archiv existuje.');
        self::assertFileExists($fresh['abs']);

        $removed = $this->export->cleanupExpired();
        self::assertGreaterThanOrEqual(1, $removed, 'Úklid smazal aspoň expirovaný archiv.');

        self::assertFileDoesNotExist($expired['abs'], 'Expirovaný archiv se smazal ze storage (kvóta!).');
        self::assertFileDoesNotExist($expired['abs'] . '.sha256', 'Smazal se i sidecar se součtem.');
        self::assertFileExists($fresh['abs'], 'Nevypršelý archiv zůstal.');

        // Řádek zůstává jako stopa v historii, ale už neslibuje soubor ke stažení.
        $row = $this->jobs->find($expired['id'], $this->supplierId);
        self::assertNotNull($row, 'Historie běhu zůstává.');
        self::assertNull($row['result_path'], 'Po úklidu už řádek neukazuje na soubor.');
        self::assertSame('completed', $row['status'], 'Expirace není selhání běhu.');
    }

    /**
     * @return array{id:int, abs:string}
     */
    private function seedCompletedExport(int $supplierId, string $fileName, string $expiresModifier): array
    {
        $jobId = $this->jobs->create($supplierId, [InstanceExportService::PART_DATA], null, null, null);
        $this->jobs->markRunning($jobId);

        $dir = RuntimePaths::storage('instance-exports') . DIRECTORY_SEPARATOR . 'sup-' . $supplierId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $abs = $dir . DIRECTORY_SEPARATOR . $jobId . '-' . $fileName;
        file_put_contents($abs, 'PK-fake-archive');
        file_put_contents($abs . '.sha256', hash('sha256', 'PK-fake-archive') . '  ' . basename($abs) . "\n");
        $this->tempPaths[] = $abs;
        $this->tempPaths[] = $abs . '.sha256';

        $this->jobs->setResult(
            $jobId,
            'sup-' . $supplierId . '/' . $jobId . '-' . $fileName,
            basename($abs),
            (int) filesize($abs),
            (string) hash_file('sha256', $abs),
            false,
            ['totals' => ['entries' => 0]],
            date('Y-m-d H:i:s', (int) strtotime($expiresModifier)),
        );
        $this->jobs->markCompleted($jobId);

        return ['id' => $jobId, 'abs' => $abs];
    }
}
