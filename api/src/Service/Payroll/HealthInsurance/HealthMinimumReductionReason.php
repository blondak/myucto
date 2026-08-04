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
    case Unverified = 'unverified';

    public function requiresWholeMonth(): bool
    {
        return $this === self::OsvcMinimumAdvance || $this === self::FosterRewardOnly;
    }
}
