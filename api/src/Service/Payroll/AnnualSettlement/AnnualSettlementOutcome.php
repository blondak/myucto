<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

/**
 * Jak roční zúčtování dopadlo.
 *
 * Tři stavy, protože zákon má tři: přeplatek se vrací (§ 38ch odst. 5),
 * nedoplatek se NESRÁŽÍ (tamtéž, věta poslední; § 35d odst. 8 věta druhá),
 * a nulový rozdíl je legitimní výsledek, ne selhání.
 *
 * `OverpaymentBelowThreshold` je čtvrtý, protože přeplatek do 50 Kč se
 * nevyplácí, ale zúčtování PROBĚHLO — a to je něco jiného než že přeplatek
 * nevznikl.
 */
enum AnnualSettlementOutcome: string
{
    case Overpayment = 'overpayment';
    case OverpaymentBelowThreshold = 'overpayment_below_threshold';
    case NoDifference = 'no_difference';
    case UnderpaymentNotWithheld = 'underpayment_not_withheld';
}
