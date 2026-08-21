<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

/**
 * Stav zaplnění diskové kvóty instance (H-10).
 *
 * ⚠️ `UNKNOWN` a `OK` jsou dva RŮZNÉ stavy a nikdy se nesmí slít. `OK` říká
 * „změřeno, místa je dost"; `UNKNOWN` říká „nevím, protože se ještě neměřilo".
 * Nezměřená instance nesmí vypadat jako prázdná — jinak by se na dashboardu
 * hlásilo uklidňující „0 %" o instanci, o které nevíme vůbec nic.
 *
 * `DISABLED` je třetí, samostatný případ: režim se na téhle instalaci
 * neuplatňuje (self-hosted bez kvóty, nebo vypnuto konfigurací). Tam nemá
 * smysl hlásit ani procenta.
 */
enum StorageQuotaState: string
{
    /** Režim se na téhle instalaci neuplatňuje (self-hosted / vypnuto / bez kvóty). */
    case DISABLED = 'disabled';

    /** Kvóta je nastavená, ale spotřeba ještě NEBYLA změřena. Nikdy ≠ nula. */
    case UNKNOWN = 'unknown';

    /** Změřeno, pod varovným prahem. */
    case OK = 'ok';

    /** Změřeno, nad varovným prahem (default 90 %) — zapisovat se dál smí. */
    case WARNING = 'warning';

    /** Změřeno, kvóta vyčerpaná (default 100 %) — zápisy se odmítají. */
    case EXHAUSTED = 'exhausted';

    /** Má se adminovi ukázat upozornění? */
    public function warns(): bool
    {
        return $this === self::WARNING || $this === self::EXHAUSTED;
    }

    /** Mají se odmítat zápisové operace? */
    public function blocksWrites(): bool
    {
        return $this === self::EXHAUSTED;
    }

    /**
     * Je stav založený na skutečném měření?
     *
     * Existuje proto, aby se nikde nemuselo psát `!== UNKNOWN && !== DISABLED`
     * — právě takové podmínky se opisují špatně a nezměřený stav se v nich
     * ztrácí.
     */
    public function isMeasured(): bool
    {
        return $this === self::OK || $this === self::WARNING || $this === self::EXHAUSTED;
    }
}
