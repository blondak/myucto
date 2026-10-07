<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Adresa bydliště ve státě daňové rezidence (`rdr`, atributy 10519 až 10524).
 *
 * Zásady REGZEC ji vyžadují u daňového rezidenta jiného státu než ČR. Pravidlo
 * platí stejně pro přihlášku A1 i pro změnu A3, proto má jedno místo: kdyby
 * ho každá cesta počítala po svém, A3 by nahlásila změnu rezidence na jiný
 * stát bez adresy a ČSSZ by ji odmítla.
 */
final class PayrollRegistrationTaxResidencyRule
{
    public static function requiresResidenceAddress(?string $countryCode): bool
    {
        return $countryCode !== null
            && $countryCode !== ''
            && $countryCode !== 'CZ';
    }
}
