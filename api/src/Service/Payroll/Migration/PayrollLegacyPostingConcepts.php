<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Service\Payroll\PayrollAccountingDefaults;

/**
 * Slovník mzdových VÝZNAMŮ, na které se dá převzaté zaúčtování přeložit.
 *
 * Tohle je hranice mezi obecnou vrstvou a konkrétním zdrojem. Zdrojová čtečka
 * (PAMICA, Money S3, cokoliv dalšího) umí jediné: ke svému řádku zaúčtování
 * přiřadit jeden z těchhle významů. Co z toho plyne pro nastavení mezd MyÚčta,
 * rozhoduje výhradně tahle tabulka a {@see PayrollPostingMapProposalBuilder} -
 * druhý feeder se proto přidává bez zásahu do vyhodnocení.
 *
 * Klíče vpravo jsou předkontace `payroll_employer_settings`
 * ({@see PayrollAccountingDefaults::ACCOUNTS}). `null` znamená, že ta strana
 * zápisu žádnou vlastní předkontaci nemá a nesmí se z ní nic odvozovat.
 *
 * **Proč má srážka i daň zapsanou i STRANU MD.** Původní program účtuje srážku
 * jako 331 MD / 379 D - a ta 331 je tentýž závazkový účet mzdy, který MyÚčto
 * drží pod `employment_gross_credit`. Je to další doklad o témže významu, takže
 * se počítá. Kdyby se převzatý program rozešel (srážka proti jinému účtu než
 * hrubá mzda), vyjde z toho rozpor - a ten má účetní vidět, ne ho zdědit tiše.
 *
 * **Proč jsou zvlášť varianty společníka.** Závazek vůči společníkovi je 366, ne
 * 331. Bez vlastního významu by se 366 z jeho srážky přilepilo k
 * `employment_gross_credit` a vyrobilo rozpor, který ve skutečnosti žádný není.
 */
final class PayrollLegacyPostingConcepts
{
    /**
     * Význam => předkontace strany MD a strany D.
     *
     * @var array<string,array{debit:?string,credit:?string}>
     */
    public const CONCEPTS = [
        'employment_gross' => ['debit' => 'employment_gross_debit', 'credit' => 'employment_gross_credit'],
        'partner_gross' => ['debit' => 'partner_gross_debit', 'credit' => 'partner_gross_credit'],
        'statutory_gross' => ['debit' => 'statutory_gross_debit', 'credit' => 'statutory_gross_credit'],

        'employee_social' => ['debit' => 'employment_gross_credit', 'credit' => 'social_insurance_credit'],
        'employee_health' => ['debit' => 'employment_gross_credit', 'credit' => 'health_insurance_credit'],
        'partner_employee_social' => ['debit' => 'partner_gross_credit', 'credit' => 'social_insurance_credit'],
        'partner_employee_health' => ['debit' => 'partner_gross_credit', 'credit' => 'health_insurance_credit'],

        'employer_social' => ['debit' => 'employer_insurance_debit', 'credit' => 'social_insurance_credit'],
        'employer_health' => ['debit' => 'employer_insurance_debit', 'credit' => 'health_insurance_credit'],

        'advance_tax' => ['debit' => 'employment_gross_credit', 'credit' => 'income_tax_credit'],
        'partner_advance_tax' => ['debit' => 'partner_gross_credit', 'credit' => 'income_tax_credit'],
        'withholding_tax' => ['debit' => 'employment_gross_credit', 'credit' => 'withholding_tax_credit'],
        'partner_withholding_tax' => ['debit' => 'partner_gross_credit', 'credit' => 'withholding_tax_credit'],

        'other_deductions' => ['debit' => 'employment_gross_credit', 'credit' => 'other_deductions_credit'],
        'partner_other_deductions' => ['debit' => 'partner_gross_credit', 'credit' => 'other_deductions_credit'],
        'enforcement_deductions' => ['debit' => 'employment_gross_credit', 'credit' => 'enforcement_deductions_credit'],

        'risky_savings' => ['debit' => 'risky_savings_debit', 'credit' => 'risky_savings_credit'],
        'partner_settlement' => ['debit' => null, 'credit' => 'partner_settlement_credit'],
        'travel_expense' => ['debit' => 'travel_expense_debit', 'credit' => null],
        'non_deductible_benefit' => ['debit' => 'non_deductible_benefit_debit', 'credit' => null],
        'employee_receivable' => ['debit' => 'employee_receivable_debit', 'credit' => null],
    ];

    public static function isKnown(string $concept): bool
    {
        return array_key_exists($concept, self::CONCEPTS);
    }

    /** Předkontace, na kterou míří strana MD daného významu. */
    public static function debitKey(string $concept): ?string
    {
        return self::CONCEPTS[$concept]['debit'] ?? null;
    }

    /** Předkontace, na kterou míří strana D daného významu. */
    public static function creditKey(string $concept): ?string
    {
        return self::CONCEPTS[$concept]['credit'] ?? null;
    }

    /**
     * Předkontace v pořadí, v jakém je drží {@see PayrollAccountingDefaults}.
     *
     * Návrh se staví nad KOMPLETNÍ sadou, ne jen nad tím, co se odvodilo -
     * význam bez opory v převzatých datech musí být vidět jako „zůstává na
     * výchozí hodnotě", ne chybět.
     *
     * @return list<string>
     */
    public static function settingsKeys(): array
    {
        return array_keys(PayrollAccountingDefaults::ACCOUNTS);
    }

    /** Je klíč v sadě vůbec odvoditelný, nebo ho žádný význam netrefuje? */
    public static function isDerivable(string $settingsKey): bool
    {
        foreach (self::CONCEPTS as $sides) {
            if ($sides['debit'] === $settingsKey || $sides['credit'] === $settingsKey) {
                return true;
            }
        }

        return false;
    }
}
