<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Abra;

use MyInvoice\Service\Migration\Abra\AbraSnapshotStore;
use PHPUnit\Framework\TestCase;

final class AbraSnapshotStoreTest extends TestCase
{
    private string $root;
    private string|false $previous;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'abra-store-' . bin2hex(random_bytes(6));
        $this->previous = getenv('MYINVOICE_DATA_DIR');
        putenv('MYINVOICE_DATA_DIR=' . $this->root);
    }

    protected function tearDown(): void
    {
        putenv($this->previous === false ? 'MYINVOICE_DATA_DIR' : 'MYINVOICE_DATA_DIR=' . $this->previous);
        if (is_dir($this->root)) {
            $this->removeTree($this->root);
        }
    }

    public function testSnapshotIsTenantBoundAndPurged(): void
    {
        $store = new AbraSnapshotStore();
        $snapshot = ['adresar' => [['id' => 1]], '_meta' => ['mode' => 'initial']];

        $store->write(7, 11, $snapshot);

        self::assertSame($snapshot, $store->read(7, 11));
        self::assertStringContainsString('sup-7' . DIRECTORY_SEPARATOR . 'job-11', $store->path(7, 11));
        self::assertFileDoesNotExist($store->path(8, 11));

        $store->purge(7, 11);
        self::assertFileDoesNotExist($store->path(7, 11));
    }

    public function testRejectsNonPositiveScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AbraSnapshotStore())->path(0, 1);
    }

    public function testInterruptedPageExportIsTenantScopedAndExpires(): void
    {
        $store = new AbraSnapshotStore();
        $store->writePage(7, 11, 'adresar', [], 0, ['winstrom' => ['adresar' => [['id' => 1]]]]);
        $dir = dirname($store->path(7, 11));
        self::assertCount(1, glob($dir . DIRECTORY_SEPARATOR . 'page-*.json'));
        self::assertDirectoryDoesNotExist(dirname($store->path(8, 11)));
        touch($dir, time() - 120);
        $store->pruneStale(7, 60);
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testPrunesOnlyStaleSnapshotsOfSelectedTenant(): void
    {
        $store = new AbraSnapshotStore();
        $store->write(7, 11, ['_meta' => ['mode' => 'initial']]);
        $store->write(7, 12, ['_meta' => ['mode' => 'sync']]);
        $store->write(8, 11, ['_meta' => ['mode' => 'initial']]);
        touch($store->path(7, 11), time() - 120);

        $store->pruneStale(7, 60);

        self::assertFileDoesNotExist($store->path(7, 11));
        self::assertFileExists($store->path(7, 12));
        self::assertFileExists($store->path(8, 11));
    }

    private function removeTree(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    public function testDailyCleanupPrunesAcrossTenantsAndLeavesFreshSnapshot(): void
    {
        $store = new AbraSnapshotStore();
        $store->write(7, 11, ['_meta' => ['mode' => 'initial']]);
        $store->write(8, 12, ['_meta' => ['mode' => 'sync']]);
        touch($store->path(7, 11), time() - 90000);
        self::assertSame(2, $store->pruneAll());
        self::assertFileDoesNotExist($store->path(7, 11));
        self::assertFileExists($store->path(8, 12));
    }
}
