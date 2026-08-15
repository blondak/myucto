<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Výsledek jedné kontroly katalogu nad konkrétním podáním.
 *
 * Tři různé důvody, proč kontrola neskončila verdiktem, se nesmí slít:
 *
 * - `NotEvaluable` — vyhodnotit ji lokálně NELZE, protože ověřuje stav
 *   v registru ČSSZ nebo v historii podání. Žádná mezera to není, rozhodne
 *   až protokol, a odeslání to nebrání.
 * - `Unverifiable` — vyhodnotit ji lokálně LZE, ale zrovna teď chybí
 *   předpoklad: nepřipnutý číselník, neprovedená validace proti schématu,
 *   chybějící obálka. To je provozní mezera a odeslání brání.
 * - `Unimplemented` — kontrolu zatím neumíme, ale umět ji můžeme. Mezera
 *   v pokrytí, která musí být vidět, a odeslání brání taky.
 */
enum JmhzControlOutcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case NotApplicable = 'not_applicable';
    case NotEvaluable = 'not_evaluable';
    case Unverifiable = 'unverifiable';
    case Unimplemented = 'unimplemented';
}
