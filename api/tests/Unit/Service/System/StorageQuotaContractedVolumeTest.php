<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\System\StorageQuotaPolicy;
use MyInvoice\Service\System\StorageQuotaState;
use MyInvoice\Service\System\StorageUsageMeter;
use MyInvoice\Service\System\StorageUsageSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Zaplacený objem instalace (`instance.quota_gb`) — SMLUVNÍ rozsah, který
 * si aplikace neumí odvodit z ničeho jiného.
 *
 * Proč to nejde vzít z diskové kvóty hostingu: ta je „zaplacený objem
 * + rezerva na dumpy", takže by instalace zákazníkovi hlásila víc, než si
 * koupil. Provisioning proto zapisuje smluvní číslo zvlášť.
 *
 * Testuje se bez databáze — pravidlo je čistá funkce nad konfigurací
 * a měřením. `Connection` se konstruuje jen kvůli typu.
 */
final class StorageQuotaContractedVolumeTest extends TestCase
{
    private const MB = 1024 * 1024;
    private const GB = 1024 * 1024 * 1024;

    /**
     * @param array<string,mixed> $quota
     * @param array<string,mixed> $instance
     */
    private function policy(array $quota = [], array $instance = [], bool $managed = true): StorageQuotaPolicy
    {
        $config = new Config([
            'app'           => ['managed' => $managed],
            'storage_quota' => $quota,
            'instance'      => $instance,
        ]);

        return new StorageQuotaPolicy(
            $config,
            new ManagedModeGuard($config),
            new StorageUsageMeter(new Connection($config), $config),
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
    //  Odvození limitu ze zaplaceného objemu
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ PROČ BY TENHLE TEST BEZ OPRAVY PADAL: `quotaBytes()` četlo VÝHRADNĚ
     * `storage_quota.limit_mb`. Instance, které provisioning zapsal jen smluvní
     * objem, tak vracely `null` → `isEnforceable() === false` → kvóta se
     * nevyhodnocovala vůbec, přestože zaplacený rozsah byl známý.
     */
    public function testContractedVolumeAloneIsEnoughToDeriveTheLimit(): void
    {
        $policy = $this->policy(instance: ['quota_gb' => 10]);

        self::assertSame(10 * self::GB, $policy->quotaBytes());
        self::assertSame(StorageQuotaPolicy::SOURCE_CONTRACTED, $policy->quotaSource());
        self::assertTrue($policy->isEnforceable());
        self::assertSame(
            StorageQuotaState::OK,
            $policy->evaluateSnapshot($this->measured(1 * self::GB))->state,
        );
    }

    /**
     * Provozní nastavení vyhrává nad smluvním objemem — ale NE tiše.
     *
     * Zámek se musí opírat o číslo, které provozovatel vědomě zapsal; kdyby
     * vyhrával smluvní objem, jeden provozní přepis (dočasná rezerva při
     * migraci, oprava rozbitého měření) by přestal platit a instalace by
     * odmítala zápisy, které platforma dovolí. Že je přepis v platnosti, musí
     * jít zjistit z {@see StorageQuotaPolicy::quotaSource()}, ne až z chování.
     */
    public function testOperationalLimitWinsOverContractedVolumeAndSaysSo(): void
    {
        $policy = $this->policy(['limit_mb' => 20480], ['quota_gb' => 10]);

        self::assertSame(20480 * self::MB, $policy->quotaBytes(), 'Provozní limit má přednost.');
        self::assertSame(StorageQuotaPolicy::SOURCE_LIMIT_MB, $policy->quotaSource());
        // Smluvní objem se přepisem NEZTRÁCÍ — obrazovka zákazníka z něj pořád
        // počítá „obsazeno z kolika zaplacených".
        self::assertSame(10 * self::GB, $policy->contractedBytes());
    }

    /** Bez obou zdrojů není proti čemu poměřovat a režim se nezapne. */
    public function testWithoutBothSourcesThereIsNoQuotaAtAll(): void
    {
        $policy = $this->policy();

        self::assertNull($policy->quotaBytes());
        self::assertNull($policy->quotaSource());
        self::assertNull($policy->contractedBytes());
        self::assertFalse($policy->isEnforceable());
    }

    /** Prázdné, nulové i nesmyslné hodnoty smluvního objemu = „neznámý objem". */
    public function testBlankOrNonPositiveContractedVolumeReadsAsUnknown(): void
    {
        foreach ([[], ['quota_gb' => ''], ['quota_gb' => 0], ['quota_gb' => '  '], ['quota_gb' => -5], ['quota_gb' => 'abc']] as $instance) {
            $policy = $this->policy(instance: $instance);

            self::assertNull($policy->contractedBytes(), 'Neznámý objem se nesmí stát nulou ani číslem.');
            self::assertNull($policy->contractedPercent(1024));
        }
    }

    /** Objem smí být i desetinný (0,5 GB) — nesmí se uříznout na nulu. */
    public function testFractionalContractedVolumeIsNotTruncatedToZero(): void
    {
        $policy = $this->policy(instance: ['quota_gb' => '0.5']);

        self::assertSame((int) (0.5 * self::GB), $policy->contractedBytes());
        self::assertTrue($policy->isEnforceable());
    }

    /** Smluvní objem nesmí zapnout kvótu na self-hosted instalaci. */
    public function testContractedVolumeNeverEnablesTheModeOnSelfHosted(): void
    {
        $policy = $this->policy(instance: ['quota_gb' => 10], managed: false);

        self::assertSame(10 * self::GB, $policy->quotaBytes());
        self::assertFalse($policy->isEnforceable(), 'Cizí server se nezamyká.');
        self::assertSame(
            StorageQuotaState::DISABLED,
            $policy->evaluateSnapshot($this->measured(500 * self::GB))->state,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Poměr k zaplacenému objemu — dva různé důvody pro `null`
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ⚠️ Nezměřeno není nula. Bez měření nemá poměr co vyjádřit — a `(int) null`
     * by z „nevím" udělalo uklidňující „0 %".
     */
    public function testPercentOfContractedVolumeIsNullWhenNothingWasMeasured(): void
    {
        $policy = $this->policy(instance: ['quota_gb' => 10]);

        self::assertNull($policy->contractedPercent(null));
        // Kontrola předpokladu: naivní cast by dal právě tu nulu.
        self::assertSame(0.0, ((int) null / (10 * self::GB)) * 100.0);
        // A prázdná instance opravdu 0 % JE — obojí se nesmí slít.
        self::assertSame(0.0, $policy->contractedPercent(0));
    }

    /**
     * ⚠️ Neznámý zaplacený objem → žádná procenta. Dělit něčím, co neznáme,
     * znamená vymyslet si číslo; obrazovka pak ukáže jen absolutní obsazení.
     */
    public function testPercentIsNullWhenContractedVolumeIsUnknown(): void
    {
        // Provozní limit je nastavený, smluvní objem NE. Kdyby se poměr počítal
        // z provozního limitu, zákazník by viděl procenta z „objem + rezerva na
        // dumpy", tedy míň, než kolik doopravdy vyčerpal ze zaplaceného.
        $policy = $this->policy(['limit_mb' => 20480]);

        self::assertNull($policy->contractedBytes());
        self::assertNull($policy->contractedPercent(9 * self::GB));
        self::assertNotNull($policy->quotaBytes(), 'Vynucení limitu tím ale nemizí.');
    }

    /** 90 % zaplaceného objemu je 90 %, ne poměr k provoznímu limitu. */
    public function testPercentIsAlwaysMeasuredAgainstTheContractedVolume(): void
    {
        $policy = $this->policy(['limit_mb' => 20480], ['quota_gb' => 10]);

        self::assertSame(90.0, $policy->contractedPercent(9 * self::GB));
        self::assertSame(100.0, $policy->contractedPercent(10 * self::GB));
        // Přes 100 % se poměr NEOŘEZÁVÁ — jen pruh v UI se nepřetáčí.
        self::assertSame(150.0, $policy->contractedPercent(15 * self::GB));
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Prahy nad odvozeným limitem
    // ─────────────────────────────────────────────────────────────────────────

    /** Nad limitem odvozeným ze smluvního objemu platí stejné prahy. */
    public function testThresholdsApplyToTheDerivedLimitToo(): void
    {
        $policy = $this->policy(instance: ['quota_gb' => 10]);

        self::assertSame(StorageQuotaState::OK, $policy->evaluateSnapshot($this->measured(8 * self::GB))->state);
        self::assertSame(StorageQuotaState::WARNING, $policy->evaluateSnapshot($this->measured(9 * self::GB))->state);
        self::assertSame(StorageQuotaState::EXHAUSTED, $policy->evaluateSnapshot($this->measured(10 * self::GB))->state);
    }

    /** Nezměřená instance se ani s odvozeným limitem nezamyká a nevaruje. */
    public function testUnmeasuredStaysUnknownEvenWithDerivedLimit(): void
    {
        $status = $this->policy(instance: ['quota_gb' => 10])
            ->evaluateSnapshot(StorageUsageSnapshot::unmeasured());

        self::assertSame(StorageQuotaState::UNKNOWN, $status->state);
        self::assertNull($status->percent);
        self::assertFalse($status->warns());
        self::assertFalse($status->blocksWrites());
    }
}
