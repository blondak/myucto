<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use MyInvoice\Service\Cron\CronDispatcher;
use MyInvoice\Service\Cron\CronHealth;
use PHPUnit\Framework\TestCase;

/**
 * Vyhodnocení stavu úlohy — hlavně relaxace na IDLE v režimu dispatcheru.
 */
final class CronHealthTest extends TestCase
{
    private const HOUR = 3600;

    public function testFreshRunIsOk(): void
    {
        self::assertSame(
            [CronHealth::OK, CronHealth::SOURCE_SELF],
            CronHealth::evaluate(60, 'ok', self::HOUR)
        );
    }

    public function testStaleRunIsOverdue(): void
    {
        self::assertSame(
            [CronHealth::OVERDUE, CronHealth::SOURCE_SELF],
            CronHealth::evaluate(2 * self::HOUR, 'ok', self::HOUR)
        );
    }

    public function testNoHeartbeatIsNeverRan(): void
    {
        self::assertSame(
            [CronHealth::NEVER_RAN, CronHealth::SOURCE_SELF],
            CronHealth::evaluate(null, null, self::HOUR)
        );
    }

    /**
     * Jádro issue #6: čerstvá instalace, kde úloha ještě neměla ani jednu
     * periodu na to, aby proběhla, nesmí svítit jako "nikdy neběželo" — to by
     * z varování udělalo šum, který každý začne ignorovat.
     */
    public function testFreshInstallWithNoHeartbeatIsPendingNotNeverRan(): void
    {
        self::assertSame(
            [CronHealth::PENDING, CronHealth::SOURCE_SELF],
            CronHealth::evaluate(null, null, self::HOUR, false, false, 30 * 60)
        );
    }

    /** Přesně na hranici periody se instalace ještě bere jako "měla šanci, ale ne víc". */
    public function testInstallExactlyAtOnePeriodIsStillPending(): void
    {
        self::assertSame(
            [CronHealth::PENDING, CronHealth::SOURCE_SELF],
            CronHealth::evaluate(null, null, self::HOUR, false, false, self::HOUR)
        );
    }

    /**
     * Opačný scénář (issue #6 samo): instalace už úlohu měla stihnout aspoň
     * jednou, heartbeat pořád chybí — tohle MUSÍ zůstat NEVER_RAN, ne PENDING.
     */
    public function testInstallOlderThanPeriodWithNoHeartbeatIsNeverRan(): void
    {
        self::assertSame(
            [CronHealth::NEVER_RAN, CronHealth::SOURCE_SELF],
            CronHealth::evaluate(null, null, self::HOUR, false, false, 2 * self::HOUR)
        );
    }

    /** Bez znalosti stáří instalace se chová jako dřív — žádná relaxace. */
    public function testUnknownInstallAgeKeepsLegacyNeverRanBehaviour(): void
    {
        self::assertSame(
            [CronHealth::NEVER_RAN, CronHealth::SOURCE_SELF],
            CronHealth::evaluate(null, null, self::HOUR, false, false, null)
        );
    }

    /** Chyba na čerstvé instalaci je pořád chyba, ne "overdue_and_failing". */
    public function testFailingOnFreshInstallIsFailingNotOverdueAndFailing(): void
    {
        self::assertSame(
            [CronHealth::FAILING, CronHealth::SOURCE_SELF],
            CronHealth::evaluate(null, 'error', self::HOUR, false, false, 30 * 60)
        );
    }

    public function testInstallAgeSecComputesFromFirstMigrationTimestamp(): void
    {
        $now = 1_700_000_000;
        self::assertSame(3600, CronHealth::installAgeSec(date('Y-m-d H:i:s', $now - 3600), $now));
        self::assertNull(CronHealth::installAgeSec(null, $now));
        self::assertNull(CronHealth::installAgeSec('not-a-date', $now));
    }

    public function testGatedJobWithLiveDispatcherIsIdleInsteadOfOverdue(): void
    {
        self::assertSame(
            [CronHealth::IDLE, CronHealth::SOURCE_DISPATCHER],
            CronHealth::evaluate(5 * self::HOUR, 'noop', self::HOUR, true, true)
        );
    }

    public function testGatedJobThatNeverRanIsIdleTooWhenDispatcherLives(): void
    {
        self::assertSame(
            [CronHealth::IDLE, CronHealth::SOURCE_DISPATCHER],
            CronHealth::evaluate(null, null, self::HOUR, true, true)
        );
    }

    /** Bez živého dispatcheru se nic nemaskuje — ticho je zpátky poplach. */
    public function testGatedJobStaysOverdueWhenDispatcherIsDead(): void
    {
        self::assertSame(
            [CronHealth::OVERDUE, CronHealth::SOURCE_SELF],
            CronHealth::evaluate(5 * self::HOUR, 'noop', self::HOUR, true, false)
        );
    }

    /** Negatovanou úlohu dispatcher spouští vždy — její ticho je pořád chyba. */
    public function testUngatedJobIsNotRelaxed(): void
    {
        self::assertSame(
            [CronHealth::OVERDUE, CronHealth::SOURCE_SELF],
            CronHealth::evaluate(5 * self::HOUR, 'ok', self::HOUR, false, true)
        );
    }

    public function testErrorBeatsIdleRelaxation(): void
    {
        self::assertSame(
            [CronHealth::OVERDUE_AND_FAILING, CronHealth::SOURCE_SELF],
            CronHealth::evaluate(5 * self::HOUR, 'error', self::HOUR, true, true)
        );
    }

    public function testFreshRunThatFailedIsFailing(): void
    {
        self::assertSame(
            [CronHealth::FAILING, CronHealth::SOURCE_SELF],
            CronHealth::evaluate(60, 'error', self::HOUR)
        );
    }

    public function testDispatcherAliveNeedsFreshOkHeartbeat(): void
    {
        $now = 1_700_000_000;
        $fresh = date('Y-m-d H:i:s', $now - 30);
        $stale = date('Y-m-d H:i:s', $now - 3 * self::HOUR);

        self::assertTrue(CronHealth::isDispatcherAlive(
            ['last_ok_at' => $fresh, 'last_status' => 'noop'],
            self::HOUR,
            $now
        ));
        self::assertFalse(CronHealth::isDispatcherAlive(
            ['last_ok_at' => $stale, 'last_status' => 'noop'],
            self::HOUR,
            $now
        ));
        // Selhaný tick může být právě příčinou ticha podřízené úlohy.
        self::assertFalse(CronHealth::isDispatcherAlive(
            ['last_ok_at' => $fresh, 'last_status' => 'error'],
            self::HOUR,
            $now
        ));
        self::assertFalse(CronHealth::isDispatcherAlive(null, self::HOUR, $now));
    }

    /** Seznam gatovaných skriptů musí zůstat napojený na dispatcher, ne opsaný. */
    public function testGatedScriptsComeFromDispatcher(): void
    {
        $gated = CronDispatcher::gatedScripts();
        self::assertContains('cron-epo-status', $gated);
        self::assertContains('cron-ai-worker', $gated);
    }
}
