<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

/**
 * Vrácená náhrada mzdy za dovolenou jako záporná částka běžného měsíce.
 *
 * Vyčerpal-li zaměstnanec dovolenou, na kterou mu právo nevzniklo, srazí mu
 * zaměstnavatel náhradu mzdy za ni (§ 147 odst. 1 písm. e) ZP). Srážka se
 * provádí v měsíci, kdy se na přečerpání přijde (typicky skončení poměru),
 * snižuje zúčtovanou náhradu za dovolenou TOHO měsíce a s ní i základ daně
 * a obou pojistných. Není to oprava minulého období, proto ji nesmí zastavit
 * pojistka proti záporným vstupům, která jinak posílá zápornou částku do
 * opravné revize původního běhu.
 *
 * Tak ji zakládá vyrovnání dovolené při skončení poměru
 * ({@see \MyInvoice\Service\Payroll\Termination\PayrollEmploymentTerminationService::settleLeave()},
 * záporný vstup `NAHRADA_MZDY_DOVOLENA`) a tak ji vedou i jiné mzdové programy
 * (PAMICA J07/J10 „Proplacená / vrácená dovolená" se zápornou částkou), odkud
 * ji převod přebírá na složku {@see SETTLEMENT_CODE}.
 *
 * Výjimka platí jen pro tyto složky a jen tehdy, když úhrn vztahu v dané doméně
 * zůstane nezáporný — záporný základ by byl opravdu věcí opravy
 * ({@see \MyInvoice\Service\Payroll\Run\PayrollRunStatutoryInputAssembler}).
 */
final class VacationCompensationReturn
{
    /** Proplacená / vrácená náhrada za dovolenou mimo čerpání z knihy dovolené (JMHZ 10338). */
    public const SETTLEMENT_CODE = 'NAHRADA_MZDY_DOVOLENA_VYROVNANI';

    public const CODES = [PayrollLeaveInputMaterializer::COMPONENT_CODE, self::SETTLEMENT_CODE];

    public static function mayBeNegative(mixed $code): bool
    {
        return is_string($code) && in_array(strtoupper($code), self::CODES, true);
    }
}
