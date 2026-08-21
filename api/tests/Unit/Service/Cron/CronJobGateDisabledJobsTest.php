<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Cron\CronCatalog;
use MyInvoice\Service\Cron\CronJobGate;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * `cron.disabled_jobs` (H-04) — explicitní vypnutí úlohy admin/spravovanou
 * instalací. Na rozdíl od ostatních důvodů v {@see CronJobGate::inactiveReason()}
 * jde o vůli operátora, ne o automaticky zjištěnou nerelevanci, takže musí
 * vyhrát nad vším ostatním a nesmí sahat do databáze.
 */
final class CronJobGateDisabledJobsTest extends TestCase
{
    public function testJobListedInConfigIsInactiveWithDisabledReason(): void
    {
        $gate = new CronJobGate(
            new Config(['cron' => ['disabled_jobs' => ['cron-backup-pdf']]]),
            null,
        );

        self::assertSame(
            CronJobGate::INACTIVE_DISABLED_BY_CONFIG,
            $gate->inactiveReason($this->job('cron-backup-pdf')),
        );
        self::assertTrue($gate->isDisabledByConfig('cron-backup-pdf'));
    }

    /** Regrese proti dnešnímu chování: prázdný seznam (i default z baselineDefaults) nic nemění. */
    public function testEmptyDisabledJobsListChangesNothing(): void
    {
        $gate = new CronJobGate(new Config(['cron' => ['disabled_jobs' => []]]), null);

        foreach (CronCatalog::all() as $job) {
            self::assertFalse($gate->isDisabledByConfig((string) $job['script']));
        }
        // Úloha bez žádné jiné podmínky zůstává aktivní jako dřív.
        self::assertNull($gate->inactiveReason($this->job('cron-backup')));
    }

    /** Chybějící klíč `cron.disabled_jobs` (starší cfg.php bez baselineDefaults) se chová stejně jako prázdný seznam. */
    public function testMissingConfigKeyChangesNothing(): void
    {
        $gate = new CronJobGate(new Config([]), null);

        self::assertFalse($gate->isDisabledByConfig('cron-backup-pdf'));
        self::assertNull($gate->inactiveReason($this->job('cron-backup')));
    }

    /**
     * Vypnutí konfigurací musí přebít i jiný důvod, který by úlohu stejně
     * udělal neaktivní — a nesmí kvůli tomu vůbec sáhnout do databáze
     * (feature probe u `requires_feature`).
     */
    public function testDisabledByConfigOverridesOtherReasonsWithoutTouchingDatabase(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('query');

        $gate = new CronJobGate(
            new Config(['cron' => ['disabled_jobs' => ['cron-payroll-post']]]),
            $pdo,
        );

        // cron-payroll-post má requires_feature => FEATURE_DOUBLE_ENTRY; bez
        // vypnutí konfigurací by se to musela zeptat DB a bez podvojného
        // účetnictví vrátit INACTIVE_FEATURE_OFF.
        self::assertSame(
            CronJobGate::INACTIVE_DISABLED_BY_CONFIG,
            $gate->inactiveReason($this->job('cron-payroll-post')),
        );
    }

    /** Vypnutí konfigurací přebije i dispatcher/mode logiku. */
    public function testDisabledByConfigOverridesDispatcherOnlyCheck(): void
    {
        $gate = new CronJobGate(
            new Config(['cron' => ['disabled_jobs' => [CronCatalog::DISPATCHER_SCRIPT]]]),
            null,
        );

        self::assertSame(
            CronJobGate::INACTIVE_DISABLED_BY_CONFIG,
            $gate->inactiveReason($this->job(CronCatalog::DISPATCHER_SCRIPT), \MyInvoice\Service\Cron\CronScheduleMode::DISPATCHER),
        );
    }

    /**
     * Jméno mimo katalog je pravděpodobný překlep v konfiguraci — nesmí se
     * tiše ignorovat (zaloguje se varování), ale hlavně nesmí shodit ani
     * ovlivnit vyhodnocení ostatních, správně napsaných jmen v seznamu.
     */
    public function testUnknownJobNameLogsWarningAndDoesNotBreakOtherJobs(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'cron_gate_disabled_jobs_test_');
        self::assertIsString($logFile);
        $previousErrorLog = ini_set('error_log', $logFile);
        self::assertIsString($previousErrorLog);

        try {
            $gate = new CronJobGate(
                new Config(['cron' => ['disabled_jobs' => ['cron-backup-pdf', 'cron-tohle-v-katalogu-neni']]]),
                null,
            );

            // Správně napsané jméno vedle překlepu se pořád vypne...
            self::assertSame(
                CronJobGate::INACTIVE_DISABLED_BY_CONFIG,
                $gate->inactiveReason($this->job('cron-backup-pdf')),
            );
            // ...a jiná úloha v katalogu zůstává úplně neovlivněná.
            self::assertNull($gate->inactiveReason($this->job('cron-backup')));

            self::assertSame(['cron-tohle-v-katalogu-neni'], $gate->unknownDisabledJobNames());

            $logged = (string) file_get_contents($logFile);
            self::assertStringContainsString('cron-tohle-v-katalogu-neni', $logged);
        } finally {
            ini_set('error_log', $previousErrorLog);
            @unlink($logFile);
        }
    }

    /** @return array<string,mixed> */
    private function job(string $script): array
    {
        foreach (CronCatalog::all() as $job) {
            if ($job['script'] === $script) {
                return $job;
            }
        }
        self::fail("Katalog neobsahuje úlohu {$script}.");
    }
}
