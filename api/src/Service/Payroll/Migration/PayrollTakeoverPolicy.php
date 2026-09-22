<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Pravidla zápisu převzatých osob a vztahů, ve kterých se zdroje dnes liší.
 *
 * Zápis ({@see PayrollTakeoverPersonWriter} a spol.) je společný, ale převody vznikaly
 * zvlášť a na několika místech se rozhodly jinak. Rozdíly se tu NESJEDNOCUJÍ: každý
 * zdroj si drží své chování a volba má jméno, aby bylo vidět, kde se liší. Sjednocení
 * je rozhodnutí o chování převodu a patří do samostatné změny.
 *
 * Výchozí hodnoty odpovídají převodu z PAMICA (nejširší zdroj).
 */
final readonly class PayrollTakeoverPolicy
{
    public function __construct(
        /** Klíč zdroje do metadat záznamů (`pamica`, `premier`). */
        public string $sourceKey,
        /** Název programu do poznámek a hlášek („Převzato z PAMICA: …"). */
        public string $label,
        /**
         * Chybí-li v MyÚčtu identita osoby, karta osoby, zákonná evidence nebo vztah,
         * `true` to ohlásí výjimkou (údaj se nepřevezme a protokol řekne proč), `false`
         * údaj tiše přeskočí.
         */
        public bool $strict = true,
        /**
         * `true`: trvalý pobyt i kontaktní adresa se doplní každá zvlášť, pokud karta
         * adresu toho druhu nemá. `false`: adresa se zapíše jen do karty bez jakékoli adresy.
         */
        public bool $addressesPerType = true,
        /**
         * `true`: rodné příjmení se zapíše do verze identity platné dnes (jinak první);
         * `false`: vždy do první verze v historii.
         */
        public bool $birthSurnameOnCurrentVersion = true,
        /**
         * `true`: účet, na který zdroj vyplácel mzdu, se ověří dnem poslední výplaty
         * (i u účtů založených dřívějším převodem). `false`: účet zůstane k ověření.
         */
        public bool $verifyPayoutAccounts = true,
        /** Budoucí skončení vztahu se spočítá do protokolu (`end_planned`). */
        public bool $countPlannedTermination = true,
        /** Skončení dřív než nástup se ignoruje (vadný údaj zdroje). */
        public bool $ignoreEndBeforeStart = false,
        /**
         * `false`: počáteční stavy kumulací, které už existují, převod nikdy nepřepíše.
         * `true`: stavy, které zapsal dřívější převod téhož zdroje (odkaz začíná
         * „{label}:"), uloží znovu; zadané jinak (ručně, z hlášení) nepřepíše.
         */
        public bool $rewriteOwnOpenings = false,
        /**
         * `true`: položku Zákonných termínů, kterou nejde odškrtnout kvůli jakékoli
         * RuntimeException (včetně chyby databáze), přeskočí. `false`: přeskočí jen
         * konflikt verze, chybějící vztah a zamítnutí kontrolou; chyba databáze projde výš.
         */
        public bool $checklistToleratesRuntime = false,
    ) {}

    /** Začátek poznámky u převzatého údaje. */
    public function note(string $text): string
    {
        return 'Převzato z ' . $this->label . ': ' . $text;
    }
}
