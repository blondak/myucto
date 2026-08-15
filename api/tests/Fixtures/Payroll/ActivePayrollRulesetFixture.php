<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Registry s jedinou doménou, účinnou hned po startu.
 *
 * Dřív tady fixture protahovala dodanou sadu přechody `reviewed → approved →
 * active` se syntetickým schválením, protože jinak z ní výpočet nečerpal.
 * Od chvíle, kdy je dodaná sada účinná rovnou ({@see CzechPayrollRulesets2026}),
 * je to zbytečné — a hlavně škodlivé: přejmenovaná kopie už není dodaná sada
 * a testy by běžely proti obsahu, který zákazníkovi nikdy nedodáme.
 */
final class ActivePayrollRulesetFixture
{
    public static function provider(PayrollRulesetDomain $domain): PayrollRulesetProvider
    {
        return new PayrollRulesetProvider([
            CzechPayrollRulesets2026::provider()->forDate($domain, '2026-08-03'),
        ]);
    }
}
