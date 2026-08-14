<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

/**
 * Výsledek jedné kontroly katalogu nad konkrétním podáním.
 *
 * `Unimplemented` je záměrně oddělené od `NotEvaluable`. První znamená
 * „tuhle kontrolu ještě neumíme, ale umět ji můžeme" a je to mezera v pokrytí,
 * která musí být vidět. Druhé znamená „vyhodnotit ji lokálně nelze, protože
 * ověřuje stav v registru ČSSZ" — tam žádná mezera není, rozhodne až protokol.
 */
enum JmhzControlOutcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case NotApplicable = 'not_applicable';
    case NotEvaluable = 'not_evaluable';
    case Unimplemented = 'unimplemented';
}
