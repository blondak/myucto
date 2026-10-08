<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

/**
 * Jediný zdroj "dnešního" data a času vyplnění pro podání ČSSZ a zdravotním
 * pojišťovnám. Kalendář podání je český: lhůty, kontroly "datum není
 * v budoucnosti" i věty REGZEC/PREZEC se čtou českým dnem. UTC by mezi půlnocí
 * a 1. až 2. hodinou ranní vrátilo den předchozí, takže by podání neslo datum
 * z minulosti a lhůtní kontroly by se rozcházely s tím, co vidí účetní.
 *
 * Čas vyplnění (`datumVyplneni`, xs:dateTime) nese český čas s offsetem
 * (`2026-10-08T00:30:00+02:00`): okamžik zůstává jednoznačný a kalendářní den
 * v prvních deseti znacích je český den.
 */
final class PayrollSubmissionCalendar
{
    public const ZONE = 'Europe/Prague';

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::ZONE);
    }

    /** Okamžik v českém čase; bez argumentu skutečné "teď". */
    public static function now(?\DateTimeInterface $at = null): \DateTimeImmutable
    {
        if ($at === null) {
            return new \DateTimeImmutable('now', self::zone());
        }

        return \DateTimeImmutable::createFromInterface($at)->setTimezone(self::zone());
    }

    /** Dnešní den (`RRRR-MM-DD`) podle českého kalendáře. */
    public static function today(?\DateTimeInterface $at = null): string
    {
        return self::now($at)->format('Y-m-d');
    }

    /** Čas vyplnění podání pro `datumVyplneni` (`RRRR-MM-DDTHH:MM:SS+HH:MM`). */
    public static function filledAt(?\DateTimeInterface $at = null): string
    {
        return self::now($at)->format('Y-m-d\TH:i:sP');
    }
}
