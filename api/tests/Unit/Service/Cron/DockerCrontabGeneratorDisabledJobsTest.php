<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Cron\CronCatalog;
use MyInvoice\Service\Cron\CronJobGate;
use MyInvoice\Service\Cron\CronScheduleMode;
use MyInvoice\Service\Cron\DockerCrontabGenerator;
use PHPUnit\Framework\TestCase;

/**
 * `cron.disabled_jobs` musí vypnutou úlohu vynechat i z vygenerovaného
 * `/etc/cron.d/myucto` — jinak by ji spravovaná instalace vypnula v UI a
 * v Dockeru by ji cron přesto po startu kontejneru spustil.
 */
final class DockerCrontabGeneratorDisabledJobsTest extends TestCase
{
    public function testDisabledJobIsNotScheduledInIndividualMode(): void
    {
        $gate = new CronJobGate(new Config(['cron' => ['disabled_jobs' => ['cron-backup-pdf']]]), null);
        $crontab = DockerCrontabGenerator::generate($gate);

        self::assertStringNotContainsString('api/bin/cron-backup-pdf.php', $crontab);
        // Ostatní nepodmíněné úlohy zůstávají naplánované.
        self::assertStringContainsString('api/bin/cron-backup.php', $crontab);
        self::assertStringContainsString('api/bin/cron-backup-documents.php', $crontab);
    }

    public function testMultipleDisabledJobsAreBothDropped(): void
    {
        $gate = new CronJobGate(
            new Config(['cron' => ['disabled_jobs' => ['cron-backup-pdf', 'cron-backup-documents']]]),
            null,
        );
        $crontab = DockerCrontabGenerator::generate($gate);

        self::assertStringNotContainsString('api/bin/cron-backup-pdf.php', $crontab);
        self::assertStringNotContainsString('api/bin/cron-backup-documents.php', $crontab);
        self::assertStringContainsString('api/bin/cron-backup.php', $crontab);
    }

    /**
     * Beze zmíněné úlohy v `cron.disabled_jobs` se nic nemění (regrese) —
     * plný katalog (minus `requires_config` bez adresáře) zůstává naplánovaný.
     */
    public function testWithoutDisabledJobsBehaviorIsUnchanged(): void
    {
        $gateWithout = new CronJobGate(new Config([]), null);
        $gateWithEmptyList = new CronJobGate(new Config(['cron' => ['disabled_jobs' => []]]), null);

        self::assertSame(
            DockerCrontabGenerator::generate($gateWithout),
            DockerCrontabGenerator::generate($gateWithEmptyList),
        );

        foreach (CronCatalog::dispatchable() as $job) {
            if (isset($job['requires_config']) || ($job['requires_managed'] ?? false) === true) {
                continue;
            }
            self::assertStringContainsString(
                'api/bin/' . $job['script'] . '.php',
                DockerCrontabGenerator::generate($gateWithout),
            );
        }
    }

    /** Vypnutí musí platit i v dispatcher režimu (položka dispatcheru sama vypnutá být nemůže). */
    public function testDisabledJobIsDroppedInDispatcherModeTooIfNotDispatcherOnly(): void
    {
        // Dispatcher režim plánuje jen dispatcher_only položky (samotný dispatcher),
        // takže vypnutí "cron-backup-pdf" tam nemá co filtrovat — ověřuje se aspoň,
        // že se generování nerozbije a dispatcher zůstane naplánovaný.
        $gate = new CronJobGate(new Config(['cron' => ['disabled_jobs' => ['cron-backup-pdf']]]), null);
        $crontab = DockerCrontabGenerator::generate($gate, CronScheduleMode::DISPATCHER);

        self::assertStringContainsString('api/bin/' . CronCatalog::DISPATCHER_SCRIPT . '.php', $crontab);
    }
}
