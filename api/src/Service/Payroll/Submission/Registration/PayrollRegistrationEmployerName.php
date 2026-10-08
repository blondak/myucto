<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Název zaměstnavatele do registrace ČSSZ (REGZEC `comp/@nam`, ID 10120).
 *
 * Všeobecné zásady REGZEC chtějí „celý název dle rejstříku a obec sídla"
 * (matice REGZEC25-comp.nam-04); Premier i ostatní programy píšou
 * „Název s.r.o., Obec". Obec se nepřidává, když jí název už končí, a ani
 * tehdy, kdyby se spojení nevešlo do 150 znaků schématu: ČSSZ zaměstnavatele
 * páruje podle variabilního symbolu, oříznutý název by byl horší než bez obce.
 */
final class PayrollRegistrationEmployerName
{
    private const MAX_LENGTH = 150;

    public static function forSubmission(string $companyName, ?string $city): string
    {
        $name = trim($companyName);
        $city = trim((string) $city);
        if ($name === '' || $city === '') {
            return $name;
        }
        if (str_ends_with(mb_strtolower($name, 'UTF-8'), mb_strtolower($city, 'UTF-8'))) {
            return $name;
        }
        $composed = $name . ', ' . $city;

        return mb_strlen($composed, 'UTF-8') > self::MAX_LENGTH ? $name : $composed;
    }
}
