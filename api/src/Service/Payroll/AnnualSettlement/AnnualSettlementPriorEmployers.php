<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

/**
 * Stav dokladů od předchozích plátců daně za uplynulé zdaňovací období
 * (§ 38ch odst. 3).
 *
 * `None` znamená, že poplatník v roce žádného jiného plátce neměl — tedy že
 * není co dokládat. To je jiná informace než `AllDocumented`, kde doklady
 * existují a došly.
 */
enum AnnualSettlementPriorEmployers: string
{
    case Unknown = 'unknown';
    case None = 'none';
    case AllDocumented = 'all_documented';
    case Missing = 'missing';
}
