<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

enum SocialIncomeAttribution: string
{
    case CurrentEmploymentMonth = 'current_employment_month';
    case PostTerminationEndMonthVerified = 'post_termination_end_month_verified';
    /**
     * Odložený příjem po skončení pracovního poměru (JMHZ scénář 8, typ 1):
     * pojistné se platí za měsíc zúčtování. Jen u vztahu, jehož účast nestojí
     * na výši příjmu; u dohod a zaměstnání malého rozsahu by příjem mohl
     * zpětně založit účast v posledním měsíci trvání a to vyžaduje opravu
     * hlášení za ten měsíc.
     */
    case PostTerminationPaymentMonthVerified = 'post_termination_payment_month_verified';
    case Unverified = 'unverified';
}
