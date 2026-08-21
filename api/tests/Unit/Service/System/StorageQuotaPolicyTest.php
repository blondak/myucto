<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\System\InstanceEntitlement;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\System\StorageQuotaPolicy;
use MyInvoice\Service\System\StorageQuotaState;
use MyInvoice\Service\System\StorageUsageMeter;
use MyInvoice\Service\System\StorageUsageSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * H-10 — vyhodnocení diskové kvóty.
 *
 * Testuje se přes {@see StorageQuotaPolicy::evaluateSnapshot()}, takže bez
 * databáze: pravidlo je čistá funkce (měření + konfigurace) → (stav).
 * `Connection` se konstruuje jen kvůli typu — spojení navazuje až `pdo()`,
 * které se tady nikdy nezavolá.
 */
final class StorageQuotaPolicyTest extends TestCase
{
    private const MB = 1024 * 1024;

    /**
     * @param array<string,mixed> $quota
     */
    private function policy(array $quota, bool $managed = true): StorageQuotaPolicy
    {
        $config = new Config([
            'app'           => ['managed' => $managed],
            'storage_quota' => $quota,
        ]);

        return new StorageQuotaPolicy(
            $config,
            new ManagedModeGuard($config),
            new StorageUsageMeter(new Connection($config), $config),
            new InstanceEntitlement(new Connection($config), $config),
        );
    }

    private function measured(int $usageBytes): StorageUsageSnapshot
    {
        return new StorageUsageSnapshot(
            measuredAt:    new DateTimeImmutable('2026-08-21 10:00:00'),
            databaseBytes: (int) round($usageBytes / 4),
            filesBytes:    $usageBytes - (int) round($usageBytes / 4),
            usageBytes:    $usageBytes,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ⚠️ Jádro položky: NEZMĚŘENO (null) NENÍ NULA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Nezměřená spotřeba nesmí spustit ani upozornění, ani režim jen pro čtení
     * — a hlavně se nesmí tvářit jako „0 %, vše v pořádku".
     *
     * ⚠️ PROČ BY TENHLE TEST BEZ OPRAVY PADAL: naivní implementace čte
     * `usage` jako `(int) $snapshot->usageBytes` nebo `$usage ?? 0`. `null`
     * se tím změní na `0`, `percent` vyjde `0.0` a stav `OK`. První dvě
     * assertions (`assertNull` na percent, `UNKNOWN` na stav) na takové
     * implementaci selžou. Prázdná instance a nezměřená instance vypadají
     * v datech skoro stejně, ale znamenají opak — u prázdné víme, že je místa
     * dost, u nezměřené nevíme nic.
     */
    public function testUnmeasuredUsageIsNeitherWarningNorReadOnly(): void
    {
        $status = $this->policy(['limit_mb' => 1000])
            ->evaluateSnapshot(StorageUsageSnapshot::unmeasured());

        self::assertNull($status->percent, 'Nezměřeno musí být null, ne 0 %.');
        self::assertSame(StorageQuotaState::UNKNOWN, $status->state);
        self::assertNull($status->usageBytes);
        self::assertFalse($status->warns(), 'Nezměřená instance nesmí varovat.');
        self::assertFalse($status->blocksWrites(), 'Nezměřená instance se nesmí zamknout.');
        self::assertFalse($status->state->isMeasured());
    }

    /**
     * Doslovná kontrola té záměny: kdyby se `null` castoval na int, vyšlo by
     * z něj TOTÉŽ číslo jako u opravdu prázdné instance. Test proto porovnává
     * obě cesty vedle sebe — musí se rozejít.
     */
    public function testUnmeasuredAndGenuinelyEmptyInstanceDoNotLookAlike(): void
    {
        $policy = $this->policy(['limit_mb' => 1000]);

        $unmeasured = $policy->evaluateSnapshot(StorageUsageSnapshot::unmeasured());
        $empty      = $policy->evaluateSnapshot($this->measured(0));

        // Naivní implementace: `(int) null === 0` → obojí by skončilo tady.
        $naivePercentForUnmeasured = ((int) StorageUsageSnapshot::unmeasured()->usageBytes / (1000 * self::MB)) * 100.0;
        self::assertSame(0.0, $naivePercentForUnmeasured, 'Kontrola předpokladu: cast null→0 dá 0 %.');

        self::assertSame(0.0, $empty->percent, 'Skutečně prázdná instance JE 0 %.');
        self::assertNull($unmeasured->percent, 'Nezměřená instance NENÍ 0 % — je to null.');
        self::assertNotSame($empty->state, $unmeasured->state);
        self::assertSame(StorageQuotaState::OK, $empty->state);
        self::assertSame(StorageQuotaState::UNKNOWN, $unmeasured->state);
    }

    /**
     * Půlka měření není měření. Řádek s časem, ale bez čísla (nebo naopak) je
     * rozpracovaný zápis, ne výsledek — a nesmí se z něj počítat kvóta.
     */
    public function testHalfWrittenMeasurementCountsAsUnmeasured(): void
    {
        $policy = $this->policy(['limit_mb' => 1000]);

        $timeOnly = new StorageUsageSnapshot(measuredAt: new DateTimeImmutable('2026-08-21 10:00:00'));
        $sizeOnly = new StorageUsageSnapshot(usageBytes: 999 * self::MB);

        foreach ([$timeOnly, $sizeOnly] as $snapshot) {
            $status = $policy->evaluateSnapshot($snapshot);
            self::assertSame(StorageQuotaState::UNKNOWN, $status->state);
            self::assertNull($status->percent);
            self::assertFalse($status->blocksWrites());
        }
    }

    /** Stejné pravidlo musí platit i po cestě z databáze (prázdný seed z migrace). */
    public function testSeededRowFromMigrationReadsAsUnmeasured(): void
    {
        $snapshot = StorageUsageSnapshot::fromRow([
            'measured_at'    => null,
            'database_bytes' => null,
            'files_bytes'    => null,
            'usage_bytes'    => null,
            'backup_bytes'   => null,
            'file_count'     => null,
            'duration_ms'    => null,
            'truncated'      => 0,
            'breakdown'      => null,
        ]);

        self::assertFalse($snapshot->isMeasured());
        self::assertNull($snapshot->usageBytes);
        self::assertFalse($snapshot->toArray()['measured']);
        self::assertSame(
            StorageQuotaState::UNKNOWN,
            $this->policy(['limit_mb' => 1000])->evaluateSnapshot($snapshot)->state,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Prahy
    // ─────────────────────────────────────────────────────────────────────────

    /** 90 % varuje, ale zapisovat se dál smí. */
    public function testNinetyPercentWarnsButKeepsWriting(): void
    {
        $status = $this->policy(['limit_mb' => 1000])->evaluateSnapshot($this->measured(900 * self::MB));

        self::assertSame(StorageQuotaState::WARNING, $status->state);
        self::assertSame(90.0, $status->percent);
        self::assertTrue($status->warns());
        self::assertFalse($status->blocksWrites(), 'Na 90 % se zapisovat nepřestává.');
        self::assertSame(100 * self::MB, $status->remainingBytes());
    }

    /** Těsně pod prahem se ještě nevaruje. */
    public function testJustBelowWarningThresholdIsQuiet(): void
    {
        $status = $this->policy(['limit_mb' => 1000])->evaluateSnapshot($this->measured(899 * self::MB));

        self::assertSame(StorageQuotaState::OK, $status->state);
        self::assertFalse($status->warns());
        self::assertFalse($status->blocksWrites());
    }

    /** 100 % zamyká zápisy. */
    public function testHundredPercentBlocksWrites(): void
    {
        $status = $this->policy(['limit_mb' => 1000])->evaluateSnapshot($this->measured(1000 * self::MB));

        self::assertSame(StorageQuotaState::EXHAUSTED, $status->state);
        self::assertSame(100.0, $status->percent);
        self::assertTrue($status->blocksWrites());
        self::assertTrue($status->warns(), 'Vyčerpaná kvóta je taky důvod k upozornění.');
        self::assertSame(0, $status->remainingBytes());
    }

    /** Překročená kvóta se nesmí „přetočit" na zápornou rezervu. */
    public function testOverQuotaStaysExhaustedWithZeroRemaining(): void
    {
        $status = $this->policy(['limit_mb' => 1000])->evaluateSnapshot($this->measured(1500 * self::MB));

        self::assertSame(StorageQuotaState::EXHAUSTED, $status->state);
        self::assertSame(150.0, $status->percent);
        self::assertSame(0, $status->remainingBytes());
    }

    /** Prahy se dají posunout konfigurací. */
    public function testThresholdsAreConfigurable(): void
    {
        $policy = $this->policy(['limit_mb' => 1000, 'warn_percent' => 75, 'read_only_percent' => 95]);

        self::assertSame(StorageQuotaState::WARNING, $policy->evaluateSnapshot($this->measured(800 * self::MB))->state);
        self::assertSame(StorageQuotaState::EXHAUSTED, $policy->evaluateSnapshot($this->measured(950 * self::MB))->state);
    }

    /**
     * Read-only práh pod varovným by znamenal „zamkni dřív, než varuješ" —
     * admin by se o zámku dozvěděl až tím, že mu přestalo jít uložit doklad.
     */
    public function testReadOnlyThresholdNeverFallsBelowWarning(): void
    {
        $policy = $this->policy(['limit_mb' => 1000, 'warn_percent' => 90, 'read_only_percent' => 50]);

        self::assertSame(90, $policy->readOnlyPercent(), 'Read-only práh se zvedne na varovný.');
        // 60 % by při doslovném čtení překlepu (50) instalaci zamklo, aniž by
        // předtím cokoli varovalo. Musí zůstat v pořádku.
        $status = $policy->evaluateSnapshot($this->measured(600 * self::MB));
        self::assertSame(StorageQuotaState::OK, $status->state);
        self::assertFalse($status->blocksWrites());
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Kde se režim NESMÍ zapnout
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Self-hosted instalace (`app.managed` není) — kvótu tam nikdo nenastavil
     * a zamykat cizímu člověku jeho vlastní server je nepřijatelné.
     */
    public function testSelfHostedInstallationNeverEnablesTheMode(): void
    {
        $policy = $this->policy(['limit_mb' => 1000], managed: false);

        self::assertFalse($policy->isEnforceable());

        $status = $policy->evaluateSnapshot($this->measured(5000 * self::MB));
        self::assertSame(StorageQuotaState::DISABLED, $status->state);
        self::assertFalse($status->warns());
        self::assertFalse($status->blocksWrites());
        self::assertNull($status->percent);
    }

    /** Spravovaná instalace BEZ nastavené kvóty taky nic nezamyká. */
    public function testManagedWithoutQuotaNeverEnablesTheMode(): void
    {
        foreach ([[], ['limit_mb' => 0], ['limit_mb' => '']] as $quota) {
            $policy = $this->policy($quota);

            self::assertNull($policy->quotaBytes());
            self::assertFalse($policy->isEnforceable());
            self::assertSame(
                StorageQuotaState::DISABLED,
                $policy->evaluateSnapshot($this->measured(999_999 * self::MB))->state,
            );
        }
    }

    /** Vypínač musí fungovat i na spravované instalaci s nastavenou kvótou. */
    public function testExplicitDisableTurnsTheModeOffAnywhere(): void
    {
        $policy = $this->policy(['enabled' => false, 'limit_mb' => 1000]);

        self::assertFalse($policy->isEnabled());
        self::assertFalse($policy->isEnforceable());
        self::assertFalse($policy->evaluateSnapshot($this->measured(1000 * self::MB))->blocksWrites());
    }

    /** `app.managed` může přijít z ENV jako řetězec — `(bool) "false"` by byl true. */
    public function testManagedFlagFromEnvStringIsParsedNotCast(): void
    {
        $config = new Config([
            'app'           => ['managed' => 'false'],
            'storage_quota' => ['limit_mb' => 1000],
        ]);
        $policy = new StorageQuotaPolicy(
            $config,
            new ManagedModeGuard($config),
            new StorageUsageMeter(new Connection($config), $config),
            new InstanceEntitlement(new Connection($config), $config),
        );

        self::assertFalse($policy->isEnforceable());
    }
}
