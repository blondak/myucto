<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

enum SocialEmployerRateCategory: string
{
    case Ordinary = 'ordinary';
    case RescueAndCompanyFireService = 'rescue_and_company_fire_service';
    case RiskEmployment = 'risk_employment';
    case Unverified = 'unverified';
}
