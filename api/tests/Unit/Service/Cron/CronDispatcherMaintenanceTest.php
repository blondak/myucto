<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Cron\CronDispatcher;
use MyInvoice\Service\Cron\CronJobGate;
use MyInvoice\Service\Cron\CronProcessLauncher;
use MyInvoice\Service\System\MaintenanceLock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * H-03 — zámek údržby vůči plánovači.
 *
 * Dohoda s provozovatelem má dvě půlky a obě se dají porušit tiše:
 *   1. při položeném zámku se NESPUSTÍ žádná nová úloha,
 *   2. už běžící se nechají doběhnout — nikdy se nezabíjejí. Záloha spuštěná
 *      ve 02:00 může u velké instance běžet ještě v okamžiku, kdy provozovatel
 *      zámek položí, a údržba nesmí zabít dump uprostřed.
 *
 * Druhou půlku drží struktura kódu: dispatcher zná jen `launch()`, žádnou cestu
 * k ukončení procesu — což tenhle test hlídá tím, že launcher v údržbě nedostane
 * ani jedno volání a nic jiného mu dispatcher poslat nemůže.
 */
final class CronDispatcherMaintenanceTest extends TestCase
{
    private PDO $pdo;
    private MaintenanceRecordingLauncher $launcher;
    private string $dir;
    private string $lockFile;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE cron_dispatch_claims (
                script TEXT NOT NULL,
                minute_bucket TEXT NOT NULL,
                claimed_at TEXT NOT NULL,
                PRIMARY KEY (script, minute_bucket)
            )'
        );
        $this->launcher = new MaintenanceRecordingLauncher();
        $this->dir = sys_get_temp_dir() . '/myucto-dispatch-maint-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
        $this->lockFile = $this->dir . '/maintenance.lock';
    }

    protected function tearDown(): void
    {
        @unlink($this->lockFile);
        @rmdir($this->dir);
    }

    private function dispatcher(): CronDispatcher
    {
        $config = new Config(['maintenance' => ['lock_file' => $this->lockFile]]);

        return new CronDispatcher(
            $this->pdo,
            new CronJobGate(new Config([]), null),
            $this->launcher,
            new MaintenanceLock($config),
        );
    }

    private function claimedScripts(): array
    {
        return $this->pdo->query('SELECT script FROM cron_dispatch_claims')
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /** 02:00 = minuta zálohy; bez zámku se pouští, se zámkem ne. */
    public function testMaintenanceLockStopsEveryLaunch(): void
    {
        touch($this->lockFile);

        $report = $this->dispatcher()->tick(new DateTimeImmutable('2026-08-03 02:00:00'));

        self::assertContains('cron-backup', $report['due'], 'Úloha na řadě zůstává na řadě.');
        self::assertSame([], $report['launched'], 'V údržbě se nesmí spustit ani jedna úloha.');
        self::assertSame([], $report['errors']);
        self::assertSame(0, $this->launcher->total());
        self::assertSame(
            CronDispatcher::SKIP_MAINTENANCE,
            $report['skipped']['cron-backup'] ?? null,
        );
    }

    /**
     * V údržbě se nesmí nárokovat ani minuta — jinak by po zvednutí zámku
     * zůstal claim ležet a úloha by se v téže minutě už nespustila.
     */
    public function testMaintenanceDoesNotClaimTheMinute(): void
    {
        touch($this->lockFile);
        $this->dispatcher()->tick(new DateTimeImmutable('2026-08-03 02:00:00'));

        self::assertSame([], $this->claimedScripts());
    }

    public function testRemovingTheLockResumesDispatchingImmediately(): void
    {
        $dispatcher = $this->dispatcher();

        touch($this->lockFile);
        self::assertSame([], $dispatcher->tick(new DateTimeImmutable('2026-08-03 02:00:00'))['launched']);

        unlink($this->lockFile);
        $resumed = $dispatcher->tick(new DateTimeImmutable('2026-08-03 02:00:00'));

        self::assertContains(
            'cron-backup',
            $resumed['launched'],
            'Odstranění zámku ukončuje údržbu okamžitě, bez restartu dispatcheru.',
        );
    }

    public function testWithoutTheLockNothingChanges(): void
    {
        $report = $this->dispatcher()->tick(new DateTimeImmutable('2026-08-03 02:00:00'));

        self::assertContains('cron-backup', $report['launched']);
        self::assertArrayNotHasKey('cron-backup', $report['skipped']);
    }
}

final class MaintenanceRecordingLauncher implements CronProcessLauncher
{
    /** @var list<string> */
    public array $launched = [];

    public function launch(string $script, ?string &$error = null): bool
    {
        $this->launched[] = $script;

        return true;
    }

    public function total(): int
    {
        return count($this->launched);
    }
}
