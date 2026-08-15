<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

/**
 * Kdo za hodnoty verze rulesetu ručí.
 *
 * NENÍ to příznak, který by šlo předat konstruktoru — {@see PayrollRulesetVersion}
 * si ho ODVOZUJE z otisku obsahu proti {@see VendorRulesetManifest}. Dodanou sadu
 * tedy nelze předstírat: buď je obsah bajt po bajtu ten, co je zkompilovaný
 * v aplikaci, nebo jde o zákaznický přepis se všemi jeho povinnostmi.
 */
enum PayrollRulesetOrigin: string
{
    /** Ověřená sada dodaná s aplikací — ručí za ni dodavatel. */
    case Vendor = 'vendor';

    /** Obsah, který se od dodané sady liší — ručí za něj zákazník. */
    case CustomerOverride = 'customer_override';
}
