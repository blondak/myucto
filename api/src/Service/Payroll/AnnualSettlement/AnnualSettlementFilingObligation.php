<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

/**
 * Zda poplatník podá nebo je povinen podat daňové přiznání (§ 38g).
 *
 * § 38ch odst. 1 věta druhá: „Roční zúčtování záloh a daňového zvýhodnění
 * NEPROVEDE plátce u poplatníka, který podá nebo je povinen podat přiznání
 * k dani." Není to doporučení — plátce zúčtování provést nesmí.
 *
 * Povinnost plyne mimo jiné z toho, že poplatník má jiné příjmy podle § 7 až 10
 * nad 20 000 Kč, není daňovým rezidentem ČR a uplatňuje slevy podle
 * § 35ba odst. 1 písm. b) až e), dostal doplatky mezd za minulá léta, nebo mu
 * plátce oznámil dlužnou částku podle § 38i odst. 5 písm. b). Aplikace to
 * NEODVOZUJE — o většině těch skutečností nic neví. Odpovídá na to člověk.
 */
enum AnnualSettlementFilingObligation: string
{
    case Unknown = 'unknown';
    case None = 'none';
    case Required = 'required';
}
