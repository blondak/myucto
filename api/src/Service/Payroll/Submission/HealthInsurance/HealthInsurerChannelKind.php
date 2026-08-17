<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Cesty, kterými se podání dostane ke zdravotní pojišťovně.
 *
 * Datová věta je od 1. 1. 2026 společná, kanál NENÍ — zákon nechává formát
 * a způsob zaručené identity na jednotlivé pojišťovně a ta si ho určuje sama.
 */
enum HealthInsurerChannelKind: string
{
    /** Portál ZP (Asseco), sdružuje VoZP, ČPZP, OZP, ZPŠ a RBP. */
    case SharedPortal = 'shared_portal';

    /** Vlastní portál pojišťovny mimo Portál ZP (VZP Point, eKomunikace). */
    case OwnPortal = 'own_portal';

    /** Datová schránka pojišťovny. */
    case DataBox = 'data_box';

    /** Strojové rozhraní na smlouvu (SOAP/AS2 B2B, komunikační brána). */
    case MachineInterface = 'machine_interface';
}
