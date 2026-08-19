<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Ozuspoj;

/**
 * Proč sleva za měsíc podle záměru náleží, nebo nenáleží.
 *
 * Jediná hodnota, která slevu pouští dál, je {@see self::Evidenced}. Všechny
 * ostatní ji zavírají — § 7c odst. 3 dělá z přeplacené slevy dluh na pojistném,
 * kdežto z neuplatněné žádný nedoplatek nevzniká, takže každý nejasný stav musí
 * skončit neuplatněním.
 */
enum OzuspojEligibilityOutcome: string
{
    case Evidenced = 'evidenced';
    case NotNotified = 'not_notified';
    case NotifiedLate = 'notified_late';
    case OutsideIntentPeriod = 'outside_intent_period';
    case ClaimWindowClosed = 'claim_window_closed';

    public function allowsDiscount(): bool
    {
        return $this === self::Evidenced;
    }
}
