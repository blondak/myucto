<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Service\Payroll\Submission\CsszEmployerVariableSymbol;

/**
 * Jediný zdroj identifikátorů zaměstnavatele pro podání ČSSZ k pracovnímu
 * vztahu: variabilní symbol zaměstnavatele z mzdové účtárny vztahu a kód OSSZ
 * z nastavení zaměstnavatele.
 *
 * Staré sloupce `supplier.cssz_vsdp` a `supplier.cssz_ossz_code` jsou u
 * zaměstnavatele jen dědictví, které přenáší
 * {@see \MyInvoice\Service\Payroll\PayrollEmployerLegacyIdentifierCarryOver} a
 * po přenosu je smaže; u OSVČ nesou její osobní symbol OSVČ, ne symbol
 * zaměstnavatele. Registrace (PREZEC/REGZEC) proto četla účtárnu, kdežto
 * nemocenská (NEMPRI/HZUPN) a oznámení slevy (OZUSPOJ) firmu, a pro tentýž
 * vztah tak podávaly pod dvěma různými symboly, nebo pod žádným.
 *
 * Výběr symbolu podle prostředí (testovací VS účtárny do testu ČSSZ) dělá
 * {@see self::resolveVariableSymbol()} přes {@see CsszEmployerVariableSymbol};
 * řádek z {@see self::SELECT} se bez něj nesmí použít.
 *
 * Fragmenty předpokládají alias `employment` pro `payroll_employments`.
 */
final class PayrollEmployerIdentifierSql
{
    public const JOINS = '
          LEFT JOIN payroll_offices employer_office
                 ON employer_office.supplier_id = employment.supplier_id
                AND employer_office.id = employment.office_id
          LEFT JOIN payroll_employer_settings employer_settings
                 ON employer_settings.supplier_id = employment.supplier_id';

    public const SELECT = '
                    employer_office.social_security_variable_symbol AS employer_variable_symbol,
                    employer_office.test_social_security_variable_symbol AS employer_test_variable_symbol,
                    employer_settings.social_security_office_code AS employer_ossz_code';

    /**
     * `employer_variable_symbol` řádku přepíše na symbol platný pro prostředí
     * a pomocný testovací sloupec odstraní, aby ho nikdo nečetl mimo pravidlo.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function resolveVariableSymbol(array $row, string $environment): array
    {
        $production = $row['employer_variable_symbol'] ?? null;
        $test = $row['employer_test_variable_symbol'] ?? null;
        $row['employer_variable_symbol'] = CsszEmployerVariableSymbol::forEnvironment(
            $environment,
            is_string($production) ? $production : null,
            is_string($test) ? $test : null,
        );
        unset($row['employer_test_variable_symbol']);

        return $row;
    }
}
