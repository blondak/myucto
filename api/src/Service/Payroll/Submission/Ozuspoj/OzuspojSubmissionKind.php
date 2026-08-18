<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Ozuspoj;

/**
 * Druh oznámení záměru, tedy `zamer/typPodani` datové věty OZUSPOJ23.
 *
 * Hodnota enumu je slovo, ne číslo z XSD. Číslo zná jen {@see self::formCode()}:
 * kdyby bylo hodnotou, prosakovalo by přes HTTP hranici až do klienta a `2`
 * v požadavku by nikdo nepřečetl jako „ukončit záměr". Číselník je v XSD
 * zapsaný jako rozsah 1..3 s dokumentací „1 - uplatneni zameru, 2 - skonceni
 * zameru, 3 - storno".
 *
 * Popis datové věty k němu přidává pravidla, která XSD nevyjadřuje: `datumOd`
 * je povinné pro 1 a 3 a NESMÍ být vyplněné pro 2, `datumDo` je povinné pro 2.
 *
 * Storno je vědomě odlišné od skončení. Skončení podle § 23e odst. 2 záměr
 * UKONČÍ k datu a je zároveň jeho zrušením do budoucna; storno bere zpět celé
 * chybně podané oznámení, jako by nebylo. Sloučit je pod jednu akci by
 * znamenalo, že se omylem podaný záměr nedá odklidit jinak než tvrzením, že
 * sleva nějakou dobu platila.
 */
enum OzuspojSubmissionKind: string
{
    case Start = 'start';
    case End = 'end';
    case Cancellation = 'cancellation';

    /** Hodnota `zamer/typPodani` podle OZUSPOJ23.xsd. */
    public function formCode(): int
    {
        return match ($this) {
            self::Start => 1,
            self::End => 2,
            self::Cancellation => 3,
        };
    }

    public function requiresIntentFrom(): bool
    {
        return $this !== self::End;
    }

    public function requiresIntentTo(): bool
    {
        return $this === self::End;
    }
}
