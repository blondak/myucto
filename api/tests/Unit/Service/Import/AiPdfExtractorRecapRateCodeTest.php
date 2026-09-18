<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\AiPdfExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Velkoobchodní účtenka tiskne daňovou rekapitulaci s interním KÓDEM sazby před
 * procentem („23= 12,0"). Model opíše první číslo ve sloupci, takže do `vat_recap`
 * dorazí sazba, která v tuzemsku neexistuje; čtenáři rekapitulace takový řádek zahodí
 * a doklad s cenami bez daně se založí úplně bez DPH.
 *
 * Sazba je v rekapitulaci i podruhé — jako podíl daně k základu. Test hlídá, že se
 * použije právě tehdy, když popisek neplatí, a že se jinak nesahá na nic.
 *
 * Všechna čísla jsou syntetická; odpovídá si jen jejich vnitřní poměr, na kterém
 * oprava stojí.
 */
final class AiPdfExtractorRecapRateCodeTest extends TestCase
{
    /** Číselník tuzemských sazeb: 21 %, 12 % a osvobozeno. */
    private function resolver(): callable
    {
        return static fn (float $percent): ?float => match (true) {
            abs($percent - 21.0) < 0.001 => 21.0,
            abs($percent - 12.0) < 0.001 => 12.0,
            default => null,
        };
    }

    /** @param list<array<string,mixed>> $recap @return list<array<string,mixed>> */
    private function repair(array $recap): array
    {
        $out = AiPdfExtractor::repairedRateCodes(['vat_recap' => $recap], $this->resolver());

        return $out['vat_recap'];
    }

    public function testKodSazbySeNahradiProcentemZPodilu(): void
    {
        $repaired = $this->repair([
            ['rate' => 23, 'base' => 3663.21, 'vat' => 439.59],
            ['rate' => 6,  'base' => 1757.00, 'vat' => 368.97],
        ]);

        self::assertSame(12.0, $repaired[0]['rate'], 'kód 23 je ve skutečnosti 12 %');
        self::assertSame(21.0, $repaired[1]['rate'], 'kód 6 je ve skutečnosti 21 %');
        // Základ ani daň se oprava nesmí dotknout — opisují se z dokladu.
        self::assertSame(3663.21, $repaired[0]['base']);
        self::assertSame(439.59, $repaired[0]['vat']);
    }

    public function testPlatnaSazbaZustaneBezeZmeny(): void
    {
        // Zaokrouhlení daně na dokladu dává podíl 20,97 %, ale popisek je platný,
        // takže se nepřepočítává (jinak by se sazba rozešla s dokladem).
        $repaired = $this->repair([['rate' => 21, 'base' => 100.00, 'vat' => 20.97]]);

        self::assertSame(21, $repaired[0]['rate']);
    }

    public function testCiziSazbaSeNeopravuje(): void
    {
        // Německých 19 % v tuzemském číselníku není a podíl vrátí zase 19 % —
        // řádek musí propadnout do kontroly, ne se tiše přepsat na 21 %.
        $repaired = $this->repair([['rate' => 19, 'base' => 1000.00, 'vat' => 190.00]]);

        self::assertSame(19, $repaired[0]['rate']);
    }

    public function testPodilMimoCiselnikNechavaRadekBezeZmeny(): void
    {
        // Drobný základ: zaokrouhlená daň dá podíl 22 %, což žádná sazba není.
        $repaired = $this->repair([['rate' => 23, 'base' => 0.50, 'vat' => 0.11]]);

        self::assertSame(23, $repaired[0]['rate']);
    }

    public function testOsvobozenyRadekAPrazdnaRekapitulaceZustanou(): void
    {
        $repaired = $this->repair([
            ['rate' => 0,  'base' => 500.00, 'vat' => 0.00],
            ['rate' => 23, 'base' => 0.00,   'vat' => 0.00],
        ]);

        self::assertSame(0, $repaired[0]['rate']);
        self::assertSame(23, $repaired[1]['rate']);
    }

    public function testDokladBezRekapitulaceProjdeBezeZmeny(): void
    {
        $data = ['vendor_invoice_number' => 'TEST-0001', 'total_with_vat' => 121.0];

        self::assertSame($data, AiPdfExtractor::repairedRateCodes($data, $this->resolver()));
    }

    /**
     * Týž kód stojí i u položek. Sama oprava rekapitulace doklad nespasí: účtenka
     * jednotkové ceny uvádí (`recapOnlyRates` se nechytí) a sazby jsou dvě
     * (`singleRateConsistentRecap` vrací null), takže řádky spadnou na zástupnou nulu,
     * `PurchaseVatRecapSeeder::computedRecap()` nad nimi vrátí prázdno a seeder daně
     * nemá kam připnout. Dopočtený převod kód→procento proto musí dojít i na `items`.
     */
    public function testKodSazbySePropiseIDoPolozek(): void
    {
        $out = AiPdfExtractor::repairedRateCodes([
            'vat_recap' => [
                ['rate' => 23, 'base' => 3663.21, 'vat' => 439.59],
                ['rate' => 6,  'base' => 1757.00, 'vat' => 368.97],
            ],
            'items' => [
                ['description' => 'Zboží A', 'quantity' => 1, 'unit_price_without_vat' => 3663.21, 'vat_rate' => 23],
                ['description' => 'Zboží B', 'quantity' => 1, 'unit_price_without_vat' => 1757.00, 'vat_rate' => 6],
            ],
        ], $this->resolver());

        self::assertSame(12.0, $out['items'][0]['vat_rate']);
        self::assertSame(21.0, $out['items'][1]['vat_rate']);
        // Položka se smí změnit jen v sazbě — popis ani cena nejsou naše věc.
        self::assertSame(3663.21, $out['items'][0]['unit_price_without_vat']);
        self::assertSame('Zboží A', $out['items'][0]['description']);
    }

    /** Řádek se sazbou, kterou rekapitulace neopravila, se nepřebírá odhadem. */
    public function testPolozkaSKodemMimoRekapitulaciZustane(): void
    {
        $out = AiPdfExtractor::repairedRateCodes([
            'vat_recap' => [['rate' => 23, 'base' => 3663.21, 'vat' => 439.59]],
            'items' => [
                ['description' => 'A', 'vat_rate' => 23],
                ['description' => 'B', 'vat_rate' => 6],   // kód, který v rekapitulaci není
                ['description' => 'C', 'vat_rate' => 21],  // platná sazba
                ['description' => 'D', 'vat_rate' => 0],   // osvobozeno
            ],
        ], $this->resolver());

        self::assertSame(12.0, $out['items'][0]['vat_rate']);
        self::assertSame(6, $out['items'][1]['vat_rate']);
        self::assertSame(21, $out['items'][2]['vat_rate']);
        self::assertSame(0, $out['items'][3]['vat_rate']);
    }

    /** Doklad, kde rekapitulace sedne, ale položky nejsou pole — nesmí spadnout. */
    public function testPolozkyMimoTvarNevadi(): void
    {
        $out = AiPdfExtractor::repairedRateCodes([
            'vat_recap' => [['rate' => 23, 'base' => 3663.21, 'vat' => 439.59]],
            'items' => ['nesmysl', ['description' => 'A']],
        ], $this->resolver());

        self::assertSame(12.0, $out['vat_recap'][0]['rate']);
        self::assertSame(['nesmysl', ['description' => 'A']], $out['items']);
    }
}
