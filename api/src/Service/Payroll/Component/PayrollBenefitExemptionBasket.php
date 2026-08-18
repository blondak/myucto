<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

/**
 * Roční koš osvobození nepeněžních benefitů.
 *
 * § 6 odst. 9 ZDP nelimituje jednotlivou mzdovou složku, ale ÚHRN plnění za dané
 * ustanovení. Doslovné znění účinné pro rok 2026 (ověřeno proti verzím
 * 1. 1. 2026 i 1. 8. 2026, obě znějí stejně):
 *
 *   písm. d) bod 1 — „…; tato plnění jsou osvobozena v úhrnu do výše průměrné
 *                     mzdy za zdaňovací období,"
 *   písm. d) bod 2 — „…; tato plnění jsou osvobozena v úhrnu do výše poloviny
 *                     průměrné mzdy za zdaňovací období,"
 *   písm. m)       — „…do úhrnné výše 50000 Kč ročně,"
 *
 * Tři důsledky, které z toho plynou a které složkový `annual_limit_minor`
 * vyjádřit neumí:
 *
 *  1. **Koš je společný.** Dvě různé mzdové složky téhož bodu se sčítají. Strop
 *     jedné složky je proto nutná, ne postačující podmínka.
 *  2. **Nerovnost je NEOSTRÁ.** „do výše X" znamená, že částka rovna X je ještě
 *     osvobozená; zdaňuje se až to, co X převyšuje. Srovnej § 6 odst. 4 ZDP, kde
 *     zákon říká „nedosáhne částky", a test je proto ostrý — tady takové slovo
 *     není.
 *  3. **Osvobozeno je plnění „do výše", ne plnění jako celek.** Překročení
 *     nezruší osvobození už poskytnutého úhrnu; zdanitelný je jen přebytek.
 *
 * Průměrná mzda je podle § 21g odst. 2 ZDP „průměrná mzda stanovená podle zákona
 * upravujícího pojistné na sociální zabezpečení", tedy MĚSÍČNÍ částka — roční
 * strop bodu 1 je jeden její násobek, ne dvanáct. Hodnoty proto nesedí v tomhle
 * enumu, ale v rulesetu pod {@see rulesetKey()}: nový rok = nový ruleset.
 *
 * Koš se kumuluje za KALENDÁŘNÍ ROK (zdaňovací období poplatníka podle § 16b ZDP)
 * a za OSOBU U ZAMĚSTNAVATELE — zákon mluví o plnění poskytovaném zaměstnavatelem
 * zaměstnanci, ne o plnění z jednoho pracovního vztahu. Souběžné vztahy téže osoby
 * u téže firmy proto sdílí jeden koš.
 */
enum PayrollBenefitExemptionBasket: string
{
    /** § 6 odst. 9 písm. d) bod 1 ZDP — zdravotní plnění, úhrn do průměrné mzdy. */
    case NonCashHealth = 'non_cash_health';

    /**
     * § 6 odst. 9 písm. d) bod 2 ZDP — rekreace a zájezd, sport, kultura, tištěné
     * knihy, vzdělávací a předškolní zařízení; úhrn do poloviny průměrné mzdy.
     */
    case NonCashLeisure = 'non_cash_leisure';

    /**
     * § 6 odst. 9 písm. m) ZDP — příspěvek na daňově podporované produkty spoření
     * na stáří a na pojištění dlouhodobé péče, úhrn 50 000 Kč ročně. Písmeno se
     * konsolidačním balíčkem posunulo; ve znění účinném pro rok 2026 je to m).
     */
    case OldAgeSavings = 'old_age_savings';

    /** Kanonický klíč ročního limitu v rulesetu daně z příjmů. */
    public function rulesetKey(): string
    {
        return match ($this) {
            self::NonCashHealth => 'benefit_exemption.non_cash_health.yearly',
            self::NonCashLeisure => 'benefit_exemption.non_cash_leisure.yearly',
            self::OldAgeSavings => 'benefit_exemption.old_age_savings.yearly',
        };
    }

    public function statute(): string
    {
        return match ($this) {
            self::NonCashHealth => '§ 6 odst. 9 písm. d) bod 1 ZDP',
            self::NonCashLeisure => '§ 6 odst. 9 písm. d) bod 2 ZDP',
            self::OldAgeSavings => '§ 6 odst. 9 písm. m) ZDP',
        };
    }
}
