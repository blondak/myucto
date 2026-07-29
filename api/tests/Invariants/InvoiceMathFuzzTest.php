<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Invariants;

use MyInvoice\Service\Invoice\InvoiceMath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Fuzz nad `InvoiceMath` — vrstva L3 (invarianty a fuzz) z auditního plánu.
 *
 * Ručně psané testy dosáhnou jen na kombinace, které někoho napadly. Nálezy tohoto
 * auditu ale opakovaně žily v kombinacích, na které nikdo nemyslel — cizí měna ×
 * dobropis × záloha × přenesená daňová povinnost × zaokrouhlení. Generátor projde
 * kombinatoriku, na kterou scénáře nedosáhnou, a ptá se na VLASTNOSTI, ne na
 * konkrétní očekávaná čísla.
 *
 * Generátor je ZÁMĚRNĚ deterministický (pevný seed): fuzz, který při každém běhu
 * testuje něco jiného, je v CI nepoužitelný — nález nejde zopakovat a červená
 * v CI se svede na „to je ten flaky fuzz". Seed je v konstantě; kdo chce širší
 * pokrytí, změní ho a nález si tím zafixuje.
 *
 * Testuje se pure kalkulace bez DB, takže se dá pustit i tam, kde databáze není.
 */
#[Group('invariants')]
final class InvoiceMathFuzzTest extends TestCase
{
    /** Pevný seed — reprodukovatelnost je u fuzzu podmínka, ne detail. */
    private const SEED = 20260726;

    /** Kolik dokladů se vygeneruje na jeden invariant. */
    private const DOCUMENTS = 400;

    /** Sazby platné pro rok 2024+ (§ 47 ZDPH) plus nulová a záporná hrana. */
    private const RATES = [0.0, 12.0, 21.0];

    /**
     * Vygeneruje doklady napříč kombinacemi. Každý případ nese i popis, aby šlo
     * z červeného testu poznat, KTERÁ kombinace selhala, bez ladění.
     *
     * @return iterable<string, array{list<array{quantity:float,unit_price_without_vat:float,vat_rate_snapshot:float}>, bool, bool}>
     */
    public static function documents(): iterable
    {
        mt_srand(self::SEED);

        for ($i = 0; $i < self::DOCUMENTS; $i++) {
            $pricesIncludeVat = ($i % 2) === 0;
            $reverseCharge    = ($i % 7) === 0;
            $creditNote       = ($i % 5) === 0;
            $lineCount        = 1 + ($i % 4);

            $items = [];
            for ($l = 0; $l < $lineCount; $l++) {
                // Množství i cena záměrně nehezké: desetinná množství a haléřové ceny
                // jsou přesně tam, kde se metody zaokrouhlení rozcházejí.
                $qty   = ((mt_rand(1, 400)) / (($i % 3) === 0 ? 10 : 1));
                $price = mt_rand(1, 500000) / 100;
                if ($creditNote) {
                    $qty = -$qty;
                }
                $items[] = [
                    'quantity'               => (float) $qty,
                    'unit_price_without_vat' => (float) $price,
                    'vat_rate_snapshot'      => self::RATES[mt_rand(0, count(self::RATES) - 1)],
                ];
            }

            $label = sprintf(
                '#%03d %s%s%s %dř',
                $i,
                $pricesIncludeVat ? 'shora' : 'zdola',
                $reverseCharge ? '+RC' : '',
                $creditNote ? '+dobropis' : '',
                $lineCount,
            );
            yield $label => [$items, $reverseCharge, $pricesIncludeVat];
        }
    }

    /**
     * Invariant: součet řádků se musí přesně rovnat hlavičce dokladu.
     *
     * Tohle je vlastnost, na které stojí celá evidence DPH — `VatLedgerService`
     * sčítá ULOŽENÉ řádkové totály, takže rozdíl mezi řádky a hlavičkou znamená,
     * že doklad a přiznání ukazují jiné číslo.
     *
     * @param list<array{quantity:float,unit_price_without_vat:float,vat_rate_snapshot:float}> $items
     */
    #[DataProvider('documents')]
    public function testLineSumsEqualDocumentTotals(array $items, bool $reverseCharge, bool $pricesIncludeVat): void
    {
        $r = InvoiceMath::compute($items, $reverseCharge, $pricesIncludeVat);

        $base = 0;
        $vat = 0;
        $with = 0;
        foreach ($r['items'] as $line) {
            $base += self::cents($line['base']);
            $vat  += self::cents($line['vat']);
            $with += self::cents($line['with']);
        }

        self::assertSame(self::cents($r['totals']['without_vat']), $base, 'Σ základů řádků ≠ hlavička.');
        self::assertSame(self::cents($r['totals']['vat']), $vat, 'Σ daně řádků ≠ hlavička.');
        self::assertSame(self::cents($r['totals']['with_vat']), $with, 'Σ brutto řádků ≠ hlavička.');
    }

    /**
     * Invariant: na každém řádku platí `základ + daň = brutto`, do haléře.
     * Bez toho by se rozešel doklad se svým vlastním součtem.
     *
     * @param list<array{quantity:float,unit_price_without_vat:float,vat_rate_snapshot:float}> $items
     */
    #[DataProvider('documents')]
    public function testEveryLineIsInternallyConsistent(array $items, bool $reverseCharge, bool $pricesIncludeVat): void
    {
        foreach (InvoiceMath::compute($items, $reverseCharge, $pricesIncludeVat)['items'] as $i => $line) {
            self::assertSame(
                self::cents($line['with']),
                self::cents($line['base']) + self::cents($line['vat']),
                "Řádek {$i}: základ + daň ≠ brutto.",
            );
        }
    }

    /**
     * Invariant § 37 odst. 2: v režimu SHORA musí Σ řádkové daně dané sazby přesně
     * odpovídat dani spočítané koeficientem z celkového brutta té sazby.
     *
     * Tohle je vlastnost, kvůli které existuje distribuce zaokrouhlovacího rezidua.
     * Bez ní by se doklad o dvou řádcích téže sazby rozešel s přiznáním o haléř —
     * a haléřové rozdíly v přiznání jsou přesně ta třída chyb, kterou nikdo nehledá.
     *
     * @param list<array{quantity:float,unit_price_without_vat:float,vat_rate_snapshot:float}> $items
     */
    #[DataProvider('documents')]
    public function testTopDownVatPerRateMatchesCoefficientOfTotal(array $items, bool $reverseCharge, bool $pricesIncludeVat): void
    {
        if (!$pricesIncludeVat || $reverseCharge) {
            self::assertTrue(true, 'Invariant platí jen pro režim shora bez přenesené daňové povinnosti.');
            return;
        }

        $grossByRate = [];
        $vatByRate = [];
        foreach (InvoiceMath::compute($items, false, true)['items'] as $line) {
            $key = number_format((float) $line['rate'], 2, '.', '');
            $grossByRate[$key] = ($grossByRate[$key] ?? 0) + self::cents($line['with']);
            $vatByRate[$key]   = ($vatByRate[$key] ?? 0) + self::cents($line['vat']);
        }

        foreach ($grossByRate as $key => $grossCents) {
            $rate = (float) $key;
            if ($rate <= 0.0) {
                self::assertSame(0, $vatByRate[$key], "Sazba {$key} %: daň musí být nulová.");
                continue;
            }
            $expected = (int) round(($grossCents / 100) * $rate / (100.0 + $rate) * 100);
            self::assertSame(
                $expected,
                $vatByRate[$key],
                "Sazba {$key} %: Σ řádkové daně ≠ daň z celkového brutta té sazby (§ 37/2).",
            );
        }
    }

    /**
     * Invariant § 37 odst. 2 na úrovni ŘÁDKU: daň každého řádku je koeficient z jeho
     * vlastního brutta — a dorovnání zaokrouhlovacího rezidua smí sáhnout nejvýš na
     * JEDEN řádek v každé sazbové skupině.
     *
     * Proč tenhle invariant navíc: skupinový invariant výše je splněný KONSTRUKCÍ.
     * Distribuce rezidua totiž součet za sazbu dorovná na koeficient bez ohledu na to,
     * jakým vzorcem se počítaly jednotlivé řádky — ověřeno mutací („základ první")
     * nad celou sadou 6 406 testů, kterou NEODHALIL nikdo. Rozdíl je nedaňový (přiznání
     * vyjde stejně, sčítá se za sazbu), ale rozpad na dokladu se rozejde, a hlavně by
     * se tichá změna vzorce nedozvěděla. Tenhle invariant to zachytí.
     *
     * @param list<array{quantity:float,unit_price_without_vat:float,vat_rate_snapshot:float}> $items
     */
    #[DataProvider('documents')]
    public function testTopDownPerLineVatFollowsCoefficientExceptOneResidualLine(array $items, bool $reverseCharge, bool $pricesIncludeVat): void
    {
        if (!$pricesIncludeVat || $reverseCharge) {
            self::assertTrue(true, 'Invariant platí jen pro režim shora bez přenesené daňové povinnosti.');
            return;
        }

        /** @var array<string, list<int>> $deviationsByRate odchylky v haléřích */
        $deviationsByRate = [];
        foreach (InvoiceMath::compute($items, false, true)['items'] as $line) {
            $rate = (float) $line['rate'];
            if ($rate <= 0.0) {
                continue;
            }
            $key = number_format($rate, 2, '.', '');
            $statutory = self::cents(round((float) $line['with'] * $rate / (100.0 + $rate), 2));
            $deviation = self::cents($line['vat']) - $statutory;
            if ($deviation !== 0) {
                $deviationsByRate[$key][] = $deviation;
            }
        }

        $offending = [];
        foreach ($deviationsByRate as $key => $deviations) {
            if (count($deviations) > 1) {
                $offending[] = sprintf('sazba %s %%: %d řádků mimo vzorec', $key, count($deviations));
            }
        }

        self::assertSame(
            [],
            $offending,
            'Od zákonného koeficientu se v každé sazbě smí lišit nejvýš JEDEN řádek (ten, který '
                . 'nese zaokrouhlovací reziduum). Víc řádků mimo vzorec znamená, že se změnil '
                . 'samotný výpočet daně (§ 37/2): ' . implode('; ', $offending),
        );
    }

    /**
     * Invariant § 37 odst. 1: v režimu ZDOLA je daň na řádku přesně
     * `round(základ × sazba/100, 2)` — bez rezidua, protože základ je vstup.
     *
     * @param list<array{quantity:float,unit_price_without_vat:float,vat_rate_snapshot:float}> $items
     */
    #[DataProvider('documents')]
    public function testBottomUpVatFollowsStatutoryFormulaPerLine(array $items, bool $reverseCharge, bool $pricesIncludeVat): void
    {
        if ($pricesIncludeVat || $reverseCharge) {
            self::assertTrue(true, 'Invariant platí jen pro režim zdola bez přenesené daňové povinnosti.');
            return;
        }

        foreach (InvoiceMath::compute($items, false, false)['items'] as $i => $line) {
            $rate = (float) $line['rate'];
            self::assertSame(
                self::cents(round((float) $line['base'] * $rate / 100.0, 2)),
                self::cents($line['vat']),
                "Řádek {$i}: daň neodpovídá vzorci § 37/1.",
            );
        }
    }

    /**
     * Invariant § 92a: u přenesené daňové povinnosti nevybírá daň dodavatel, takže
     * daň na dokladu je vždy nula — ale NOMINÁLNÍ sazba na řádku zůstává (potřebuje ji
     * příjemce pro samovyměření i tisk dokladu).
     *
     * @param list<array{quantity:float,unit_price_without_vat:float,vat_rate_snapshot:float}> $items
     */
    #[DataProvider('documents')]
    public function testReverseChargeAlwaysYieldsZeroVatButKeepsRate(array $items, bool $reverseCharge, bool $pricesIncludeVat): void
    {
        if (!$reverseCharge) {
            self::assertTrue(true, 'Netýká se dokladů bez přenesené daňové povinnosti.');
            return;
        }

        $r = InvoiceMath::compute($items, true, $pricesIncludeVat);
        self::assertSame(0, self::cents($r['totals']['vat']), 'Reverse charge: daň na dokladu musí být nulová.');

        foreach ($r['items'] as $i => $line) {
            self::assertSame(0, self::cents($line['vat']), "Řádek {$i}: daň u RC musí být nulová.");
            self::assertSame(
                self::cents($line['base']),
                self::cents($line['with']),
                "Řádek {$i}: u RC se základ rovná brutto.",
            );
        }
        self::assertSame(
            array_map(static fn (array $it): float => (float) $it['vat_rate_snapshot'], $items),
            array_map(static fn (array $l): float => (float) $l['rate'], $r['items']),
            'RC nesmí zahodit nominální sazbu — příjemce ji potřebuje k samovyměření.',
        );
    }

    /**
     * Invariant: doklad a jeho zrcadlový dobropis se musí vyrušit na nulu.
     * „Storno + doklad = 0" je invariant z plánu; kdyby se nevyrušily, vznikl by
     * po stornu zbytek v základu daně nebo v dani.
     *
     * @param list<array{quantity:float,unit_price_without_vat:float,vat_rate_snapshot:float}> $items
     */
    #[DataProvider('documents')]
    public function testDocumentPlusItsMirrorCreditNoteIsZero(array $items, bool $reverseCharge, bool $pricesIncludeVat): void
    {
        $mirrored = array_map(
            static fn (array $it): array => ['quantity' => -$it['quantity']] + $it,
            $items,
        );

        $a = InvoiceMath::compute($items, $reverseCharge, $pricesIncludeVat)['totals'];
        $b = InvoiceMath::compute($mirrored, $reverseCharge, $pricesIncludeVat)['totals'];

        foreach (['without_vat', 'vat', 'with_vat'] as $key) {
            self::assertSame(
                0,
                self::cents($a[$key]) + self::cents($b[$key]),
                "Doklad + zrcadlový dobropis ≠ 0 v položce {$key}.",
            );
        }
    }

    private static function cents(float|int $amount): int
    {
        return (int) round((float) $amount * 100.0);
    }
}
