<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Druh mzdového běhu — odkud pochází jeho výsledek.
 *
 * `CALCULATED` je všechno, co mzdový modul spočítal sám: prochází workflow,
 * validacemi, účetním můstkem i platebním ledgerem. `TAKEOVER` je zrcadlo
 * výpočtu, který udělal předchozí mzdový program; nepočítá se, neschvaluje se
 * a nezaúčtovává se podruhé.
 *
 * Rozdíl je vázaný na TENHLE druh, ne na výjimku pro soubor: každá brána, která
 * se převzatého běhu týká, se ptá `$run['run_kind']`, takže nové cesty do kódu
 * dopadnou stejně jako ty dnešní.
 */
enum PayrollRunKind: string
{
    case CALCULATED = 'calculated';
    case TAKEOVER = 'takeover';

    /**
     * Druh běhu z databázového řádku; neznámá hodnota je `CALCULATED`.
     *
     * Výchozí větev je vědomá: řádek bez sloupce (starší dotaz, který si
     * `run_kind` nevybral) je běžný běh, protože takový byl každý běh před
     * zavedením převzatých. Kdyby se neznámá hodnota mapovala na `TAKEOVER`,
     * jedno opomenutí ve výběru sloupců by vypnulo celé workflow.
     *
     * @param array<string,mixed> $run
     */
    public static function fromRun(array $run): self
    {
        $value = $run['run_kind'] ?? null;

        return is_string($value)
            ? (self::tryFrom($value) ?? self::CALCULATED)
            : self::CALCULATED;
    }

    /** @param array<string,mixed> $run */
    public static function isTakeover(array $run): bool
    {
        return self::fromRun($run) === self::TAKEOVER;
    }
}
