<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Číslo popisné a orientační v adresách datové věty REGZEC.
 *
 * EDV 1.4.0.6: u české adresy (stát CZ, a vždy u pobytu v ČR `fdr`) je číslo
 * popisné jen číselné, nejvýš čtyřmístné, bez písmene a lomítka (datový typ N);
 * u cizích států platí obecné 1 až 12 znaků. Orientační číslo pobytu v ČR
 * má podle EDV nejvýš 4 znaky. Jednu implementaci mají sdílet přihláška A1
 * i události A3 a A4.
 */
final class PayrollRegistrationHouseNumber
{
    public const CZECH_PATTERN = '/^\d{1,4}$/D';

    /** `fdr/@num` je v XSD celé číslo 1 až 9999. */
    public const CZECH_RESIDENCE_PATTERN = '/^[1-9]\d{0,3}$/D';

    public const CZECH_RESIDENCE_ORIENTATION_MAX = 4;

    public static function validDescriptive(string $value, bool $czechResidence = false): bool
    {
        return preg_match(
            $czechResidence ? self::CZECH_RESIDENCE_PATTERN : self::CZECH_PATTERN,
            $value,
        ) === 1;
    }

    public static function validCzechResidenceOrientation(string $value): bool
    {
        return mb_strlen($value, 'UTF-8') <= self::CZECH_RESIDENCE_ORIENTATION_MAX;
    }
}
