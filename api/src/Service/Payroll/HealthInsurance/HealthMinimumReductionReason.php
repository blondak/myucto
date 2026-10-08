<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

enum HealthMinimumReductionReason: string
{
    case StateInsured = 'state_insured';
    case ZtpOrZtpP = 'ztp_or_ztp_p';
    case PensionAgeWithoutPension = 'pension_age_without_pension';
    case SicknessCareOrQuarantine = 'sickness_care_or_quarantine';
    case OsvcMinimumAdvance = 'osvc_minimum_advance';
    case FosterRewardOnly = 'foster_reward_only';
    /**
     * Zaměstnanec osobně a řádně pečující alespoň o jedno dítě do 7 let
     * (§ 7 odst. 1 písm. k) zákona č. 48/1997 Sb. ve znění zákona
     * č. 289/2025 Sb., od 1. 1. 2026). Je tím osobou, za kterou platí
     * pojistné i stát, takže se na něj minimum nevztahuje (§ 3 odst. 8
     * písm. d) zákona č. 592/1992 Sb.) a v části měsíce se poměrně snižuje
     * (odst. 9 písm. c). Platí ode dne uvedeného v oznámení pojišťovně,
     * nejdříve den po jeho doručení; dokladem je potvrzení pojišťovny.
     */
    case ChildUnder7Care = 'child_under_7_care';
    case Unverified = 'unverified';

    /** Den, od kterého zákon č. 289/2025 Sb. výjimku pro péči o dítě do 7 let zná. */
    public const CHILD_UNDER_7_CARE_EFFECTIVE_FROM = '2026-01-01';

    public function requiresWholeMonth(): bool
    {
        return $this === self::OsvcMinimumAdvance || $this === self::FosterRewardOnly;
    }
}
