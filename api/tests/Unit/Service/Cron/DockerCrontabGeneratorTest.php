<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use MyInvoice\Service\Cron\CronCatalog;
use MyInvoice\Service\Cron\DockerCrontabGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Pojistka, že vestavěný cron v Docker image zůstane v souladu s CronCatalog —
 * tj. obsahuje VŠECHNY úlohy + frekvence (akceptační kritérium issue #64).
 * Kdyby někdo přidal úlohu do katalogu a zapomněl ji v Dockeru, test spadne.
 */
final class DockerCrontabGeneratorTest extends TestCase
{
    public function testCrontabCoversEveryCatalogJob(): void
    {
        $crontab = DockerCrontabGenerator::generate();
        foreach (CronCatalog::all() as $job) {
            $expected = sprintf(
                '%s www-data %s api/bin/%s.php',
                $job['linux_cron'],
                DockerCrontabGenerator::WRAPPER,
                $job['script'],
            );
            self::assertStringContainsString(
                $expected,
                $crontab,
                "crontab musí obsahovat úlohu {$job['script']} s frekvencí {$job['linux_cron']}",
            );
        }
    }

    public function testCrontabIsWellFormed(): void
    {
        $crontab = DockerCrontabGenerator::generate();
        // /etc/cron.d soubor MUSÍ končit novým řádkem, jinak ho cron ignoruje.
        self::assertStringEndsWith("\n", $crontab);
        self::assertStringContainsString('CRON_TZ=Europe/Prague', $crontab);
        // Žádná úloha navíc ani chybějící proti katalogu.
        $jobLines = array_filter(
            explode("\n", $crontab),
            static fn (string $l): bool => str_contains($l, DockerCrontabGenerator::WRAPPER),
        );
        self::assertCount(count(CronCatalog::all()), $jobLines);
    }

    public function testAiJobsUseCanonicalCronEntrypointsAndExpectedSchedules(): void
    {
        $jobs = [];
        foreach (CronCatalog::all() as $job) {
            $jobs[$job['script']] = $job;
        }

        self::assertSame('*/10 * * * *', $jobs['cron-ai-worker']['linux_cron']);
        self::assertSame('every_10_min', $jobs['cron-ai-worker']['recommended']);
        self::assertTrue($jobs['cron-ai-worker']['requires_ai_opt_in']);
        self::assertSame('0 4 * * *', $jobs['cron-ai-rule-miner']['linux_cron']);
        self::assertSame('daily_0400', $jobs['cron-ai-rule-miner']['recommended']);

        $root = dirname(__DIR__, 5);
        foreach ([
            'api/bin/cron-ai-worker.php',
            'api/bin/cron-ai-rule-miner.php',
            'cmd/cron-ai-worker.cmd',
            'cmd/cron-ai-worker.sh',
            'cmd/cron-ai-rule-miner.cmd',
            'cmd/cron-ai-rule-miner.sh',
        ] as $path) {
            self::assertFileExists($root . '/' . $path);
        }
    }
}
