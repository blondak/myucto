<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Číselník okresů ČSSZ (C_COKR, 89 hodnot): kódy okresních a pražských správ
 * sociálního zabezpečení.
 *
 * EDV 1.4.0.6 u `employee/@dep` (ID 10004) a u logické kontroly variabilního
 * symbolu zaměstnavatele odkazuje na C_COKR; hodnoty v EDV nejsou, vzaty jsou
 * z datového slovníku MH 1.4.1.6, list CIS Okresy (zdroj ČSSZ). Kód mimo
 * číselník ČSSZ odmítne, takže se hlídá už při nastavení zaměstnavatele.
 */
final class PayrollCsszDistrictCodebook
{
    /** @var list<string> */
    public const CODES = [
        '110', '111', '112', '113', '114', '115', '116', '117', '118', '119', '121', '122',
        '123', '220', '221', '222', '223', '224', '225', '226', '227', '228', '229', '230',
        '231', '332', '333', '334', '335', '336', '337', '338', '339', '440', '441', '442',
        '443', '444', '445', '446', '447', '448', '449', '550', '551', '552', '553', '554',
        '555', '556', '557', '558', '559', '660', '661', '662', '663', '664', '665', '666',
        '667', '668', '669', '670', '771', '772', '773', '774', '775', '776', '777', '778',
        '779', '780', '781', '782', '783', '784', '884', '885', '886', '887', '888', '889',
        '890', '891', '892', '893', '894',
    ];

    public static function contains(string $code): bool
    {
        return in_array($code, self::CODES, true);
    }
}
