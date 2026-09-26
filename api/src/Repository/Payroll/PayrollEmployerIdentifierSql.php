<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

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
                    employer_settings.social_security_office_code AS employer_ossz_code';
}
