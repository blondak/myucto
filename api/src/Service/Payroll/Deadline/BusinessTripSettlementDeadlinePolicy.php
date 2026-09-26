<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Deadline;

use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Lhůty vyúčtování pracovní cesty (§ 183 odst. 1 zákoníku práce).
 *
 * „Zaměstnanec je povinen do 10 pracovních dnů po ukončení pracovní cesty
 * předložit zaměstnavateli písemné doklady potřebné k vyúčtování cestovních
 * náhrad … Zaměstnavatel je povinen vyúčtovat cestovní náhrady do 10
 * pracovních dnů ode dne předložení písemných dokladů …"
 *
 * Konec cesty je MÍSTNÍ den příjezdu (v zóně cesty), vyúčtováním se rozumí
 * schválení cesty v aplikaci.
 */
final class BusinessTripSettlementDeadlinePolicy
{
    public const WORKING_DAYS = 10;

    public const SOURCE_DOCUMENTS =
        '§ 183 odst. 1 zákona č. 262/2006 Sb. — doklady do 10 pracovních dnů po skončení cesty';

    public const SOURCE_SETTLEMENT =
        '§ 183 odst. 1 zákona č. 262/2006 Sb. — vyúčtování do 10 pracovních dnů od předložení dokladů';

    /** Poslední den, kdy má zaměstnanec předložit doklady. */
    public static function documentsDueOn(string $arrivalLocalDate): string
    {
        return CzechWorkingDays::addWorkingDays(substr($arrivalLocalDate, 0, 10), self::WORKING_DAYS);
    }

    /** Poslední den, kdy má zaměstnavatel cestu vyúčtovat. */
    public static function settlementDueOn(string $documentsSubmittedOn): string
    {
        return CzechWorkingDays::addWorkingDays($documentsSubmittedOn, self::WORKING_DAYS);
    }
}
