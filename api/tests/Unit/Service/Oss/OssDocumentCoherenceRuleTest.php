<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Oss;

use MyInvoice\Service\Oss\OssDocumentCoherence;
use PHPUnit\Framework\TestCase;

/**
 * SOUDRŽNOST DOKLADU nad položkami tak, jak je posílá EDITOR / API.
 *
 * Pravidlo samo (a jeho naměřený původ) je popsané u
 * {@see \MyInvoice\Tests\Unit\Service\Import\InvoiceImportDocumentCoherenceTest}, který
 * hlídá tutéž věc na plánu importu. Tenhle soubor je jeho protějšek pro druhou vstupní
 * cestu: dokud pravidlo bydlelo jako privátní metoda uvnitř `InvoiceImportService`,
 * ruční zadání a API vytvořily rozpadlý doklad úplně tiše — a právě to, že obě cesty
 * odpovídají STEJNĚ, je tu tvrzením.
 *
 * Testuje se `flagItems()`, ne jen `detect()`: označení řádků je součástí téhož volání
 * schválně (viz docblock metody), takže test, který by ověřoval jen detekci, by mlčel
 * nad tou polovinou, kvůli které příznak vůbec existuje.
 */
final class OssDocumentCoherenceRuleTest extends TestCase
{
    private const RATES = [1 => 21.0, 2 => 12.0, 3 => 23.0, 4 => 0.0];

    /**
     * @param  list<array<string,mixed>> $items
     * @return array{items: list<array<string,mixed>>, found: ?OssDocumentCoherence}
     */
    private function flag(array $items): array
    {
        $found = OssDocumentCoherence::flagItems($items, self::RATES);

        return ['items' => $items, 'found' => $found];
    }

    /** @param list<array<string,mixed>> $items */
    private function flags(array $items): array
    {
        return array_map(static fn (array $item): int => !empty($item['oss_needs_manual_review']) ? 1 : 0, $items);
    }

    /** @return array<string,mixed> */
    private function ossItem(int $vatRateId, string $country = 'PL'): array
    {
        return [
            'description' => 'OSS řádek',
            'quantity' => 1,
            'unit_price_without_vat' => 1000,
            'vat_rate_id' => $vatRateId,
            'oss_applicable' => true,
            'oss_consumer_country' => $country,
            'oss_rate_type' => 'standard',
            'oss_supply_type' => 'goods',
            'oss_needs_manual_review' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function domesticItem(int $vatRateId): array
    {
        return [
            'description' => 'Tuzemský řádek',
            'quantity' => 1,
            'unit_price_without_vat' => 1000,
            'vat_rate_id' => $vatRateId,
            'oss_applicable' => false,
            'oss_needs_manual_review' => false,
        ];
    }

    /**
     * NAMĚŘENÝ PŘÍPAD, jen zadaný rukou: 23 % do PL v režimu OSS + 12 % tuzemsky na jedné
     * faktuře. Import na tenhle doklad varuje od začátku, editor o něm do teď mlčel.
     */
    public function testDocumentSplitBetweenOssAndDomesticIsFlaggedOnBothSides(): void
    {
        $result = $this->flag([$this->ossItem(3), $this->domesticItem(2)]);

        self::assertNotNull($result['found'], 'Rozpor se nenašel — editor by doklad uložil tiše.');
        self::assertSame([1, 1], $this->flags($result['items']),
            'Označit se musí OBĚ strany: náhled OSS podání čte jen řádky s oss_applicable = 1, '
                . 'takže příznak jen na tuzemské straně by nikde nesvítil.');

        $warning = $result['found']->warning('CZ');
        self::assertStringContainsString('Doklad si protiřečí', $warning);
        self::assertStringContainsString('stát spotřeby PL', $warning);
        self::assertStringContainsString('sazbou 12 %', $warning);
        self::assertStringContainsString('země dodavatele CZ', $warning);
        self::assertStringContainsString('K RUČNÍMU POSOUZENÍ', $warning);
    }

    /** Víc států spotřeby i víc tuzemských sazeb — hláška je musí vyjmenovat všechny. */
    public function testWarningNamesEveryConsumptionCountryAndEveryDomesticRate(): void
    {
        $result = $this->flag([
            $this->ossItem(3, 'SK'),
            $this->ossItem(3, 'PL'),
            $this->domesticItem(1),
            $this->domesticItem(2),
        ]);

        $warning = $result['found']?->warning('CZ') ?? '';
        self::assertStringContainsString('stát spotřeby PL, SK', $warning);
        self::assertStringContainsString('sazbou 12, 21 %', $warning);
        self::assertSame([1, 1, 1, 1], $this->flags($result['items']));
    }

    /**
     * Detail pro `_warning_meta` musí popisovat TÝŽ nález jako věta — jinak by API klient
     * a UI mluvily o jiném dokladu.
     */
    public function testMetaDescribesTheSameFinding(): void
    {
        $meta = $this->flag([$this->ossItem(3), $this->domesticItem(1)])['found']?->meta('CZ');

        self::assertSame(['PL'], $meta['consumer_countries'] ?? null);
        self::assertSame(['21'], $meta['domestic_rates'] ?? null);
        self::assertSame('CZ', $meta['domestic_country'] ?? null);
        self::assertSame(2, $meta['affected_items'] ?? null);
        self::assertStringContainsString('Doklad si protiřečí', (string) ($meta['message'] ?? ''));
    }

    /**
     * PROTIPÓL: nulový řádek (poštovné, zaokrouhlení, osvobozené plnění) druhé přiznání
     * netvoří. Kdyby ho tvořil, dostal by varování skoro každý OSS doklad a hláška by
     * zevšedněla dřív, než dojde na tu jednu, kde o něco jde.
     */
    public function testZeroRatedLineIsNotAContradiction(): void
    {
        $result = $this->flag([$this->ossItem(3), $this->domesticItem(4)]);

        self::assertNull($result['found']);
        self::assertSame([0, 0], $this->flags($result['items']));
    }

    /** Regrese běžného provozu: čistě tuzemský doklad se nesmí hnout. */
    public function testPurelyDomesticDocumentIsUntouched(): void
    {
        $result = $this->flag([$this->domesticItem(1), $this->domesticItem(2), $this->domesticItem(4)]);

        self::assertNull($result['found']);
        self::assertSame([0, 0, 0], $this->flags($result['items']));
    }

    /** A druhá strana téhož: doklad celý v OSS je soudržný. */
    public function testPurelyOssDocumentIsUntouched(): void
    {
        $result = $this->flag([$this->ossItem(3), $this->ossItem(1)]);

        self::assertNull($result['found']);
        self::assertSame([0, 0], $this->flags($result['items']));
    }

    /**
     * Systémový slevový řádek se přeskakuje: `replaceItems()` ho zahazuje a generuje znovu
     * z hlavičky, takže by do hlášky přidal sazbu své vlastní skupiny a příznak by na něm
     * stejně neskončil.
     */
    public function testSystemDiscountRowIsNotASecondReturn(): void
    {
        $result = $this->flag([
            $this->ossItem(3),
            ['item_kind' => 'discount', 'vat_rate_id' => 1, 'oss_applicable' => false],
        ]);

        self::assertNull($result['found'], 'Slevový řádek k OSS skupině není tuzemské plnění.');
    }

    /**
     * Neznámé `vat_rate_id` (v mapě sazeb není) se chová jako nezdaněný řádek — rozpor
     * z něj vzniknout nesmí. Takový doklad stejně neprojde validací sazby a hlásit na něj
     * OSS rozpor by uživatele poslalo hledat úplně jinam, než kde je chyba.
     */
    public function testUnknownVatRateIdDoesNotFabricateAContradiction(): void
    {
        $result = $this->flag([$this->ossItem(3), $this->domesticItem(999)]);

        self::assertNull($result['found']);
    }

    /** Doklad o jediné položce nemá s čím být v rozporu. */
    public function testSingleRowDocumentCannotContradictItself(): void
    {
        self::assertNull($this->flag([$this->ossItem(3)])['found']);
        self::assertNull($this->flag([$this->domesticItem(1)])['found']);
        self::assertNull($this->flag([])['found']);
    }

    /**
     * Příznak, který na řádku už je (odvození ho dalo kvůli spornému místu plnění), se
     * nesmí ztratit jen proto, že doklad jako celek soudržný je.
     */
    public function testExistingFlagIsNeverClearedByTheCheck(): void
    {
        $item = $this->ossItem(3);
        $item['oss_needs_manual_review'] = true;

        $result = $this->flag([$item, $this->ossItem(1)]);

        self::assertNull($result['found']);
        self::assertSame([1, 0], $this->flags($result['items']));
    }

    /**
     * TUZEMSKÁ strana rozporu se musí označit i klíčem `oss_document_contradiction`.
     *
     * Bez něj by se do databáze nedostala: zápis položky u ne-OSS řádku
     * `oss_needs_manual_review` z payloadu IGNORUJE, protože vypnutí OSS je rozhodnutí
     * člověka, kterým nejistota končí. Rozpor dokladu je jediná výjimka a jezdí vlastním
     * klíčem — ten dosazuje server, ne payload, takže z něj nemůže vzniknout příznak,
     * který nejde zhasnout.
     */
    public function testDomesticSideIsMarkedWithTheServerDerivedKeyToo(): void
    {
        $items = $this->flag([$this->ossItem(3), $this->domesticItem(1)])['items'];

        self::assertTrue($items[1]['oss_document_contradiction'] ?? false,
            'Tuzemský řádek by se do DB uložil s nulou — příznak by nesl jen OSS řádek.');
        self::assertTrue($items[0]['oss_document_contradiction'] ?? false);
    }

    /**
     * Klíče vstupu se zachovávají: volající označuje tytéž řádky, které poslal. Kdyby se
     * přečíslovaly, příznak by u dokladu s vynechaným indexem sedl na cizí řádek.
     */
    public function testAffectedKeysAreTheCallersKeys(): void
    {
        $items = [7 => $this->ossItem(3), 9 => $this->domesticItem(1)];
        $found = OssDocumentCoherence::flagItems($items, self::RATES);

        self::assertSame([7, 9], $found?->affectedKeys);
        self::assertTrue($items[7]['oss_needs_manual_review']);
        self::assertTrue($items[9]['oss_needs_manual_review']);
    }
}
