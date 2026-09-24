<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

/**
 * Částka na řádku přiznání k dani z příjmů v celých korunách.
 *
 * Pokyny k přiznání DPPO (25 5404) i DPFO (25 5405) chtějí částky v celých korunách
 * a XSD (dppdp9, dpfdp7) má u řádků `fractionDigits=0`. Součtové řádky (DPPO ř. 70,
 * 170, 200, 250, 270; DPFO ř. 41, 42, 45, 54, 55, Příloha 1 ř. 104 a 113, Příloha 2
 * ř. 203) EPO kontroluje jako součet UVEDENÝCH řádků. Proto se každý řádek zaokrouhlí
 * jednou z haléřové hodnoty a mezisoučty se skládají až ze zaokrouhlených řádků:
 * součet v haléřích zaokrouhlený až na konci se od součtu řádků může lišit o korunu
 * a EPO pak řádek odmítne.
 *
 * Zaokrouhluje se matematicky (polovina od nuly). Pokyny směr neurčují; pokyny k DPFO
 * výslovně zakazují postupné zaokrouhlování ve více stupních, takže se zaokrouhluje
 * vždy přímo haléřová hodnota, nikdy už jednou zaokrouhlené číslo.
 */
final class TaxFormAmount
{
    public static function kc(float $amount): float
    {
        $rounded = round($amount, 0);

        return $rounded == 0.0 ? 0.0 : $rounded;
    }
}
