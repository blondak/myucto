<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use DateTimeImmutable;
use FilesystemIterator;
use MyInvoice\Service\System\StorageRetentionPolicy;
use MyInvoice\Service\System\StorageRetentionSweeper;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class StorageRetentionSweeperTest extends TestCase
{
    private string $root;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/myucto-retention-' . bin2hex(random_bytes(6));
        foreach (['backup', 'log/cron', 'storage/tmp/epo', 'storage/support', 'storage/cache/mpdf/ttfontdata', 'storage/documents'] as $dir) {
            mkdir($this->root . '/' . $dir, 0777, true);
        }
        $this->now = new DateTimeImmutable('2026-10-01 03:15:00');
    }

    protected function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testDatabaseKeepsEverythingInWindowThenLastDumpPerDay(): void
    {
        $files = [];
        for ($d = 0; $d <= 9; $d++) {
            foreach (['20:00', '14:00', '08:00', '02:00'] as $time) {
                $at = $this->now->modify("-{$d} days")->setTime((int) substr($time, 0, 2), 0);
                if ($at <= $this->now) {
                    $files[$at->format('Y-m-d_H-i')] = $at;
                }
            }
        }

        $purge = StorageRetentionSweeper::selectDatabasePurge($files, $this->now, 7, 48);
        $kept = array_diff(array_keys($files), $purge);
        sort($kept);

        self::assertSame([
            '2026-09-24_20-00',
            '2026-09-25_20-00',
            '2026-09-26_20-00',
            '2026-09-27_20-00',
            '2026-09-28_20-00',
            '2026-09-29_08-00', '2026-09-29_14-00', '2026-09-29_20-00',
            '2026-09-30_02-00', '2026-09-30_08-00', '2026-09-30_14-00', '2026-09-30_20-00',
            '2026-10-01_02-00',
        ], $kept);
    }

    public function testNewestBackupSurvivesEvenWhenOlderThanLimit(): void
    {
        $files = [
            'a' => new DateTimeImmutable('2026-08-01 02:00'),
            'b' => new DateTimeImmutable('2026-07-01 02:00'),
        ];

        self::assertSame(['b'], StorageRetentionSweeper::selectDatabasePurge($files, $this->now, 7, 48));
        self::assertSame(['b'], StorageRetentionSweeper::selectSnapshotPurge($files, 1));
    }

    public function testZeroMeansDoNotSweepNotDeleteEverything(): void
    {
        $files = ['a' => new DateTimeImmutable('2026-01-01'), 'b' => new DateTimeImmutable('2025-01-01')];

        self::assertSame([], StorageRetentionSweeper::selectDatabasePurge($files, $this->now, 0, 48));
        self::assertSame([], StorageRetentionSweeper::selectSnapshotPurge($files, 0));
    }

    public function testSweepTouchesOnlyOwnBackupsOfCurrentDatabase(): void
    {
        $backup = $this->root . '/backup';
        foreach (['2026-09-27', '2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01'] as $day) {
            foreach (['pdf', 'documents', 'payroll'] as $kind) {
                touch("$backup/myucto-$kind-{$day}_02-30.zip");
            }
            touch("$backup/myucto-{$day}_02-00.zip");
        }
        touch("$backup/myucto-2026-08-01_02-00.zip");
        touch("$backup/myucto_prod-2026-01-01_02-00.zip");
        touch("$backup/myucto-rucni-kopie.zip");
        touch("$backup/.myucto-2026-09-20_02-00.sql", $this->now->getTimestamp() - 5 * 86400);
        touch("$backup/.dump.cnf", $this->now->getTimestamp() - 5 * 86400);

        $report = $this->sweeper()->run();

        self::assertSame(1, $report['backup_database']);
        self::assertSame(2, $report['backup_pdf']);
        self::assertSame(2, $report['backup_documents']);
        self::assertSame(2, $report['backup_payroll']);
        self::assertSame(1, $report['backup_temp']);
        self::assertFileDoesNotExist("$backup/myucto-2026-08-01_02-00.zip");
        self::assertFileDoesNotExist("$backup/myucto-pdf-2026-09-28_02-30.zip");
        self::assertFileExists("$backup/myucto-pdf-2026-09-29_02-30.zip");
        self::assertFileExists("$backup/myucto-2026-09-27_02-00.zip");
        self::assertFileExists("$backup/myucto_prod-2026-01-01_02-00.zip");
        self::assertFileExists("$backup/myucto-rucni-kopie.zip");
        self::assertFileExists("$backup/.dump.cnf");
    }

    public function testLogsAndTemporaryFilesByAge(): void
    {
        $old = $this->now->getTimestamp() - 20 * 86400;
        $recent = $this->now->getTimestamp() - 3600;
        $files = [
            'log/app-2026-09-01.log' => $old,
            'log/app-2026-09-30.log' => $recent,
            'log/cron/backup-2026-09-01.log' => $old,
            'log/cron/backup-2026-09-30.log' => $recent,
            'log/keep.txt' => $old,
            'storage/tmp/epo/stale.xml' => $old,
            'storage/tmp/epo/fresh.xml' => $recent,
            'storage/support/bundle.zip' => $old,
            'storage/cache/mpdf/mpdf_tmp_123' => $old,
            'storage/cache/mpdf/ttfontdata/dejavu.mtx.php' => $old,
            'storage/documents/faktura.pdf' => $old,
        ];
        foreach ($files as $path => $mtime) {
            touch($this->root . '/' . $path, $mtime);
        }

        $report = $this->sweeper()->run();

        self::assertSame(2, $report['logs']);
        self::assertSame(1, $report['tmp']);
        self::assertSame(1, $report['support']);
        self::assertSame(1, $report['mpdf_temp']);
        foreach (['log/app-2026-09-30.log', 'log/cron/backup-2026-09-30.log', 'log/keep.txt',
            'storage/tmp/epo/fresh.xml', 'storage/cache/mpdf/ttfontdata/dejavu.mtx.php',
            'storage/documents/faktura.pdf'] as $survivor) {
            self::assertFileExists($this->root . '/' . $survivor);
        }
        self::assertFileDoesNotExist($this->root . '/log/app-2026-09-01.log');
        self::assertFileDoesNotExist($this->root . '/storage/tmp/epo/stale.xml');
    }

    public function testOversizedLiveLogIsTrimmedToTail(): void
    {
        $path = $this->root . '/log/cron/cron-backup.log';
        $line = str_repeat('x', 99) . "\n";
        file_put_contents($path, str_repeat($line, 30000) . "POSLEDNI\n");

        $report = $this->sweeper(['log_max_mb' => 1])->run();

        self::assertSame(1, $report['logs_trimmed']);
        $content = (string) file_get_contents($path);
        self::assertLessThan(200_000, strlen($content));
        self::assertStringEndsWith("POSLEDNI\n", $content);
        self::assertStringContainsString('cron-retention: log zkrácen', $content);
    }

    public function testTwigCacheDropsStaleTemplatesAndOldEmptyDirsOnly(): void
    {
        $twig = $this->root . '/storage/cache/twig/invoice';
        mkdir("$twig/ab/cd", 0777, true);
        mkdir("$twig/ef/01", 0777, true);
        mkdir("$twig/77/88", 0777, true);
        $old = $this->now->getTimestamp() - 40 * 86400;
        $recent = $this->now->getTimestamp() - 86400;
        touch("$twig/ab/cd/stara.php", $old);
        touch("$twig/ef/01/cerstva.php", $recent);
        touch("$twig/77/88", $old);
        touch("$twig/77", $old);

        $report = $this->sweeper()->run();

        self::assertSame(1, $report['twig_cache']);
        self::assertFileDoesNotExist("$twig/ab/cd/stara.php");
        self::assertFileExists("$twig/ef/01/cerstva.php");
        self::assertDirectoryDoesNotExist("$twig/77/88");
        // Adresář, ze kterého se právě mazalo, je čerstvý, Twig by do něj mohl zapisovat.
        self::assertDirectoryExists("$twig/ab/cd");
    }

    public function testDryRunDeletesNothing(): void
    {
        touch($this->root . '/log/app-2026-01-01.log', $this->now->getTimestamp() - 100 * 86400);

        $sweeper = $this->sweeper([], true);
        $report = $sweeper->run();

        self::assertSame(1, $report['logs']);
        self::assertCount(1, $sweeper->deletedPaths());
        self::assertFileExists($this->root . '/log/app-2026-01-01.log');
    }

    /** @param array<string,int> $policy */
    private function sweeper(array $policy = [], bool $dryRun = false): StorageRetentionSweeper
    {
        return new StorageRetentionSweeper(
            StorageRetentionPolicy::fromArray($policy),
            $this->root . '/backup',
            'myucto',
            [$this->root . '/log', $this->root . '/log/cron'],
            $this->root . '/storage',
            $this->now,
            $dryRun,
        );
    }
}
