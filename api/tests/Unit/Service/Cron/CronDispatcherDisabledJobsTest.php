<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Cron\CronDispatcher;
use MyInvoice\Service\Cron\CronJobGate;
use MyInvoice\Service\Cron\CronProcessLauncher;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * `cron.disabled_jobs` musí dispatcher skutečně respektovat, ne jen umožnit
 * CronJobGate o tom vědět. Smysl položky: spravovaná instalace (hosting si
 * dělá zálohy sám) vypne `cron-backup-pdf` / `cron-backup-documents`, aby
 * neujídaly zaplacenou kvótu — pokud by dispatcher úlohu přesto spustil,
 * vypnutí by bylo jen kosmetické.
 */
final class CronDispatcherDisabledJobsTest extends TestCase
{
    private PDO $pdo;
    private DisabledJobsRecordingLauncher $launcher;

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
        $this->launcher = new DisabledJobsRecordingLauncher();
    }

    private function dispatcher(array $disabledJobs): CronDispatcher
    {
        $config = new Config(['cron' => ['disabled_jobs' => $disabledJobs]]);

        return new CronDispatcher($this->pdo, new CronJobGate($config, null), $this->launcher);
    }

    /** 02:30 = minuta zálohy PDF. */
    public function testDisabledJobIsNeitherLaunchedNorSilentlyDropped(): void
    {
        $report = $this->dispatcher(['cron-backup-pdf'])
            ->tick(new DateTimeImmutable('2026-08-03 02:30:00'));

        self::assertContains('cron-backup-pdf', $report['due'], 'Úloha na řadě zůstává na řadě.');
        self::assertNotContains('cron-backup-pdf', $report['launched']);
        self::assertSame(0, $this->launcher->countOf('cron-backup-pdf'));
        // Musí mít VLASTNÍ důvod, ne zmizet a ne spadnout pod "not_configured" —
        // jinak nejde v přehledu odlišit záměr od poruchy.
        self::assertSame('disabled_by_config', $report['skipped']['cron-backup-pdf'] ?? null);
    }

    public function testDisabledJobWinsOverNotConfiguredReason(): void
    {
        // cron-scan-purchase-inbox má requires_config; prázdný config by ho
        // normálně hlásil jako 'not_configured'. Vypnutí konfigurací musí mít
        // přednost, stejně jako v CronJobGate::inactiveReason().
        $report = $this->dispatcher(['cron-scan-purchase-inbox'])
            ->tick(new DateTimeImmutable('2026-08-03 09:10:00'));

        self::assertSame('disabled_by_config', $report['skipped']['cron-scan-purchase-inbox'] ?? null);
    }

    /** Regrese: úloha MIMO cron.disabled_jobs se chová jako dřív. */
    public function testUnrelatedJobStillLaunchesNormally(): void
    {
        $report = $this->dispatcher(['cron-backup-pdf'])
            ->tick(new DateTimeImmutable('2026-08-03 02:00:00'));

        self::assertContains('cron-backup', $report['launched']);
        self::assertArrayNotHasKey('cron-backup', $report['skipped']);
    }

    public function testJobNotDisabledLaunchesAsUsual(): void
    {
        $report = $this->dispatcher([])
            ->tick(new DateTimeImmutable('2026-08-03 02:30:00'));

        self::assertContains('cron-backup-pdf', $report['launched']);
        self::assertArrayNotHasKey('cron-backup-pdf', $report['skipped']);
    }
}

final class DisabledJobsRecordingLauncher implements CronProcessLauncher
{
    /** @var list<string> */
    public array $launched = [];

    public function launch(string $script, ?string &$error = null): bool
    {
        $this->launched[] = $script;

        return true;
    }

    public function countOf(string $script): int
    {
        return count(array_filter($this->launched, static fn (string $s): bool => $s === $script));
    }
}
