<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Vyhodnocení diskové kvóty spravované instalace (H-10) — jediné místo, které
 * rozhoduje, jestli se má varovat a jestli se má přestat zapisovat.
 *
 * Middleware, cron i UI se ptají tady. Kdyby si podmínku skládala každá vrstva
 * sama, jedna z nich by práh nebo `null` vyhodnotila jinak a instalace by se
 * chovala podle toho, kudy request přišel.
 *
 * ── ⚠️ Na co je to navázané (a na co ne) ──────────────────────────────────
 * Režim se zapíná JEN tehdy, když platí všechno tohle:
 *
 *   1. `storage_quota.enabled` (default true) — vypínač, který funguje na
 *      KTERÉKOLI instalaci, včetně spravované. Provozovatel musí mít čím to
 *      vypnout, když se měření splete.
 *   2. `app.managed` — spravovaná instalace ({@see ManagedModeGuard}). Na
 *      self-hosted instalaci žádnou kvótu nikdo nenastavil a zamykat cizímu
 *      člověku jeho vlastní server je nepřijatelné.
 *   3. `storage_quota.limit_mb > 0` — nastavená kvóta. Bez čísla není proti
 *      čemu poměřovat.
 *
 * NENÍ to navázané na volné místo na disku. Filesystémová kvóta hostingu je
 * „zaplacený objem + rezerva na dumpy" a dumpy z ní technicky vyjmout nejde;
 * práh 90 % odvozený z volného místa by tedy vycházel vždycky nízko a instance
 * by se zamykaly kvůli rezervě, kterou zákazník nespotřeboval.
 *
 * ── ⚠️ null ≠ nula ────────────────────────────────────────────────────────
 * Nezměřená spotřeba ({@see StorageUsageSnapshot::isMeasured()} = false) dává
 * {@see StorageQuotaState::UNKNOWN}: žádné upozornění, žádný read-only, a
 * `percent` zůstává `null`. Je to nejsnáz udělaná chyba celé položky — prázdná
 * instance a nezměřená instance vypadají v datech skoro stejně, ale znamenají
 * opak. Jediný `(int)` cast nad `usage` by z „nevím" udělal „0 %, vše
 * v pořádku".
 */
class StorageQuotaPolicy
{
    // Není `final` schválně: middleware ji dostává autowiringem přes typ třídy
    // (bez definice v kontejneru by rozhraní nešlo zaregistrovat) a test si
    // potřebuje podstrčit konkrétní stav kvóty, aniž by k tomu potřeboval
    // databázi. Jiný důvod k dědění tahle třída nemá — pravidlo zůstává tady.

    public const DEFAULT_WARN_PERCENT      = 90;
    public const DEFAULT_READ_ONLY_PERCENT = 100;

    /** Strojový kód pro odmítnutý zápis. Frontend podle něj pozná důvod. */
    public const ERROR_CODE = 'storage_quota_exhausted';

    /**
     * 507 Insufficient Storage. Ne 403 (to je o oprávnění) ani 409 (to je
     * o stavu instalace) — tohle je doslova „na cílovém úložišti není místo".
     */
    public const HTTP_STATUS = 507;

    public function __construct(
        private readonly Config $config,
        private readonly ManagedModeGuard $managed,
        private readonly StorageUsageMeter $meter,
    ) {}

    /**
     * Uplatňuje se režim na téhle instalaci?
     *
     * Čistě konfigurační dotaz — nesahá do databáze ani na disk, takže se jím
     * dá levně odbavit každý request na self-hosted instalaci.
     */
    public function isEnforceable(): bool
    {
        return $this->isEnabled()
            && $this->managed->isManaged()
            && $this->quotaBytes() !== null;
    }

    /** Vypínač. Default zapnuto, ale bez `app.managed` a kvóty stejně nic nedělá. */
    public function isEnabled(): bool
    {
        return filter_var(
            $this->config->get('storage_quota.enabled', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) !== false;
    }

    /** Kvóta v bajtech, nebo null když není nastavená. Nula = nenastaveno. */
    public function quotaBytes(): ?int
    {
        $mb = filter_var($this->config->get('storage_quota.limit_mb', 0), FILTER_VALIDATE_INT);
        if ($mb === false || $mb <= 0) {
            return null;
        }

        return $mb * 1024 * 1024;
    }

    public function warnPercent(): int
    {
        return $this->percentSetting('storage_quota.warn_percent', self::DEFAULT_WARN_PERCENT);
    }

    public function readOnlyPercent(): int
    {
        $value = $this->percentSetting('storage_quota.read_only_percent', self::DEFAULT_READ_ONLY_PERCENT);

        // Read-only práh pod varovným by znamenal „zamkni dřív, než varuješ".
        // To není konfigurace, to je překlep — a admin by se o zámku dozvěděl
        // až tím, že mu přestalo jít uložit doklad.
        return max($value, $this->warnPercent());
    }

    /**
     * Vyhodnocení proti poslednímu ULOŽENÉMU měření. Nic se tu nepočítá znovu:
     * strom souborů prochází jen cron ({@see StorageUsageMeter::measure()}).
     */
    public function evaluate(): StorageQuotaStatus
    {
        return $this->evaluateSnapshot($this->meter->latest());
    }

    /**
     * Vyhodnocení konkrétního měření. Vyčleněné, aby šlo zavolat i s čerstvě
     * změřeným snapshotem (cron) bez druhého čtení z databáze — a aby bylo
     * pravidlo TESTOVATELNÉ bez databáze vůbec.
     */
    public function evaluateSnapshot(StorageUsageSnapshot $snapshot): StorageQuotaStatus
    {
        $quota           = $this->quotaBytes();
        $warnPercent     = $this->warnPercent();
        $readOnlyPercent = $this->readOnlyPercent();
        $enforceable     = $this->isEnforceable();

        if (!$enforceable) {
            return new StorageQuotaStatus(
                state:           StorageQuotaState::DISABLED,
                percent:         null,
                usageBytes:      $snapshot->isMeasured() ? $snapshot->usageBytes : null,
                quotaBytes:      $quota,
                snapshot:        $snapshot,
                enforceable:     false,
                warnPercent:     $warnPercent,
                readOnlyPercent: $readOnlyPercent,
            );
        }

        // ⚠️ TADY je ta záměna, o kterou jde. `$snapshot->usageBytes` může být
        // null = „ještě se neměřilo". Ne nula. Žádný cast, žádné `?? 0`:
        // nezměřeno → UNKNOWN → nevaruje se a nezamyká se.
        if (!$snapshot->isMeasured()) {
            return new StorageQuotaStatus(
                state:           StorageQuotaState::UNKNOWN,
                percent:         null,
                usageBytes:      null,
                quotaBytes:      $quota,
                snapshot:        $snapshot,
                enforceable:     true,
                warnPercent:     $warnPercent,
                readOnlyPercent: $readOnlyPercent,
            );
        }

        $usage   = (int) $snapshot->usageBytes;
        $percent = $quota === null || $quota <= 0 ? null : ($usage / $quota) * 100.0;

        $state = match (true) {
            $percent === null                 => StorageQuotaState::UNKNOWN,
            $percent >= (float) $readOnlyPercent => StorageQuotaState::EXHAUSTED,
            $percent >= (float) $warnPercent    => StorageQuotaState::WARNING,
            default                           => StorageQuotaState::OK,
        };

        return new StorageQuotaStatus(
            state:           $state,
            percent:         $percent === null ? null : round($percent, 2),
            usageBytes:      $usage,
            quotaBytes:      $quota,
            snapshot:        $snapshot,
            enforceable:     true,
            warnPercent:     $warnPercent,
            readOnlyPercent: $readOnlyPercent,
        );
    }

    /**
     * Lidské vysvětlení pro odmítnutý zápis. Musí říct, co se stalo, co s tím
     * a co pořád jde — zákazník nesmí mít dojem, že přišel o data.
     */
    public function readOnlyMessage(): string
    {
        return 'Vyčerpali jste přidělený prostor instalace, takže se nové zápisy neukládají. '
            . 'Doklady zůstávají dostupné ke čtení, tisku i exportu. Uvolněte místo smazáním '
            . 'nepotřebných dat, nebo si objednejte větší prostor.';
    }

    /** Text upozornění na 90 %. */
    public function warningMessage(?float $percent): string
    {
        $shown = $percent === null ? '' : ' (' . number_format($percent, 1, ',', ' ') . ' %)';

        return 'Přidělený prostor instalace se blíží ke konci' . $shown . '. Až se vyčerpá, '
            . 'přejde instalace do režimu jen pro čtení. Uvolněte místo, nebo si objednejte '
            . 'větší prostor.';
    }

    private function percentSetting(string $key, int $default): int
    {
        $value = filter_var($this->config->get($key, $default), FILTER_VALIDATE_INT);
        if ($value === false) {
            return $default;
        }

        // 0 % by zamklo instalaci hned po prvním měření, nad 100 % by práh
        // nikdy nesepnul. Obojí je překlep, ne konfigurace.
        return max(1, min(100, $value));
    }
}
