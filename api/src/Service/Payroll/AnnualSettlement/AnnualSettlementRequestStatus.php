<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

/**
 * Zda poplatník o roční zúčtování požádal (§ 38ch odst. 1).
 *
 * `Unknown` je výchozí stav a NENÍ totéž co `NotRequested`: nepožádal je závěr,
 * nevíme je otázka. Zúčtování se neprovede ani v jednom případě, ale uživatel
 * musí vidět, který z nich to je.
 */
enum AnnualSettlementRequestStatus: string
{
    case Unknown = 'unknown';
    case Requested = 'requested';
    case NotRequested = 'not_requested';
    case Withdrawn = 'withdrawn';
}
