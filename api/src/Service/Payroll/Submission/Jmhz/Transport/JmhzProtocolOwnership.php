<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Rozhodnutí, jestli načtený protokol patří téhle firmě.
 *
 * Je to vlastní třída, a ne pár řádků v importní službě, ze dvou důvodů:
 * je to jediné místo, kde se rozhoduje o tom, jestli se cizí úřední doklad
 * dostane do tenanta, a jako čistá funkce se dá otestovat bez databáze.
 *
 * Porovnává se na číslicích bez vedoucích nul: registrační číslo si lidé
 * zapisují jednou s nulou vpředu, jindy bez ní, a vedoucí nula identitu
 * symbolu nemění. Jinou firmu tím potkat nelze.
 */
final readonly class JmhzProtocolOwnership
{
    /** @param list<string> $knownSymbols */
    public static function assert(string $variableSymbol, array $knownSymbols): void
    {
        $known = [];
        foreach ($knownSymbols as $symbol) {
            $normalized = self::normalize($symbol);
            if ($normalized !== '') {
                $known[] = $normalized;
            }
        }
        if ($known === []) {
            // Nemít s čím porovnat NENÍ důvod pustit doklad dál. Uložit ho
            // „zatím" by znamenalo, že ověření nikdy neproběhne.
            throw new JmhzTransportException(
                'jmhz_protocol_tenant_unverifiable',
                'V nastavení zaměstnavatele není vyplněné registrační číslo ani'
                    . ' variabilní symbol pracoviště, takže nelze ověřit, že'
                    . ' protokol patří této firmě. Doplňte je v nastavení mezd.',
            );
        }
        if (!in_array(self::normalize($variableSymbol), $known, true)) {
            throw new JmhzTransportException(
                'jmhz_protocol_tenant_mismatch',
                "Protokol je vystavený na variabilní symbol {$variableSymbol},"
                    . ' který této firmě nepatří. Načíst cizí protokol nelze.',
            );
        }
    }

    private static function normalize(string $value): string
    {
        return ltrim(preg_replace('/\D+/', '', $value) ?? '', '0');
    }
}
