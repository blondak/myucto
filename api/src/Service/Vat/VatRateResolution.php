<?php

declare(strict_types=1);

namespace MyInvoice\Service\Vat;

/**
 * Jak dopadlo hledání sazby DPH pro řádek dokladu.
 *
 * Stav je oddělený od výsledku schválně: „nenašlo se" a „našlo se, ale mimo platnost"
 * vedou k odlišné reakci volajícího (odmítnout doklad vs. pustit ho s varováním) a
 * v reportu importu se musí číst jinak. Booleovské `found` by ten rozdíl slilo.
 *
 * Stav „sazba byla založena" tu ZÁMĚRNĚ není: `vat_rates` je globální tabulka bez
 * `supplier_id`, takže zápis z importu jednoho nájemníka měnil číselník celé instalaci
 * a `UNIQUE uq_vat_code` navíc kolidoval s kódem založeným ručně. Resolver proto sazby
 * nezakládá a nenalezená sazba je vždy chyba dokladu.
 */
enum VatRateResolution: string
{
    /** Přesná shoda (země + procento) platná k datu plnění. */
    case Matched = 'matched';

    /** Země i procento sedí, ale řádek číselníku k datu plnění neplatí — varování. */
    case MatchedOutsideValidity = 'matched_outside_validity';

    /** V dané zemi taková sazba není — položka je neplatná a doklad se odmítne. */
    case NoRateInCountry = 'no_rate_in_country';
}
