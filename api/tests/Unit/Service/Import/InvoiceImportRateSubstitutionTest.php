<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Service\Oss\OssClientContext;
use PHPUnit\Framework\TestCase;

/**
 * G1 KROK 5 — PŘEKLAD ČESKÉ SAZBOVÉ ÚROVNĚ NA PROCENTO.
 *
 * Parsery vrací `vat_rate = null` všude, kde procento ze souboru nejde ani přečíst, ani
 * dopočítat z rekapitulace. Zbývá jim jen enum `<inv:rateVAT>` — a právě jeho dosazení
 * bylo měřeným únikem: za `high` se natvrdo dosadilo aktuálních českých 21 %, číselník
 * je POZITIVNĚ potvrdil jako tuzemskou sazbu, invariant řádek pustil do tuzemské větve
 * a polská daň skončila na ř. 1 českého přiznání bez jediného varování.
 *
 * Import proto úroveň překládá sám, ale jen tam, kde to není dohad. Tenhle test hlídá
 * obě podmínky, bez kterých by to dohad byl — TUZEMSKÝ ODBĚRATEL a PROCENTO Z ČÍSELNÍKU
 * K DATU PLNĚNÍ — a to, že se z nedosaditelné úrovně nikdy nestane nula.
 *
 * Bez konstruktoru: testované metody čtou jen své argumenty a memoizované sazby, které
 * se sem vloží přímo (`domesticScaleRates`). Odpověď číselníku je vstup rozhodnutí, ne
 * jeho součást — týž vzor jako {@see InvoiceImportRateCodebookGateTest}.
 */
final class InvoiceImportRateSubstitutionTest extends TestCase
{
    /** Skutečná historie českých sazeb ze seedu (migrace 1294) — 'reduced' se v roce 2024 mění. */
    private const CZ_2020 = [
        ['rate_type' => 'standard', 'rate_percent' => 21.0],
        ['rate_type' => 'reduced', 'rate_percent' => 15.0],
        ['rate_type' => 'second_reduced', 'rate_percent' => 10.0],
    ];
    private const CZ_2024 = [
        ['rate_type' => 'standard', 'rate_percent' => 21.0],
        ['rate_type' => 'reduced', 'rate_percent' => 12.0],
    ];

    private InvoiceImportService $service;

    protected function setUp(): void
    {
        $this->service = (new \ReflectionClass(InvoiceImportService::class))->newInstanceWithoutConstructor();
    }

    /** @param array<string, list<array{rate_type:string, rate_percent:float}>> $rates klíč „země|datum" */
    private function codebookSays(array $rates): void
    {
        (new \ReflectionProperty(InvoiceImportService::class, 'domesticScaleRates'))
            ->setValue($this->service, $rates);
    }

    /** @param array<string,mixed> $item */
    private function rate(array $item, ?string $clientCountry, string $taxDate = '2020-05-15'): ?float
    {
        /** @var ?float */
        return (new \ReflectionMethod(InvoiceImportService::class, 'itemRate'))->invokeArgs(
            $this->service,
            [$item, new OssClientContext($clientCountry, true, null), 'CZ', $taxDate],
        );
    }

    /** @param array<string,mixed> $item */
    private function message(array $item, ?string $clientCountry, string $taxDate = '2020-05-15'): string
    {
        /** @var string */
        return (new \ReflectionMethod(InvoiceImportService::class, 'unresolvedRateMessage'))->invokeArgs(
            $this->service,
            [$item, new OssClientContext($clientCountry, true, null), 'CZ', $taxDate],
        );
    }

    /** @return array<string,mixed> */
    private function enumOnly(string $level = 'reduced', string $enum = 'low'): array
    {
        return ['vat_rate' => null, 'vat_rate_level' => $level, 'vat_rate_enum' => $enum];
    }

    /**
     * Úroveň se překládá SAZBOU PLATNOU K DATU PLNĚNÍ, ne konstantou. Týž soubor s toutéž
     * `low` znamená v roce 2020 patnáct procent a v roce 2024 dvanáct — konstanta v kódu
     * by zpětně datovanému dokladu vyměřila daň dnešní sazbou.
     */
    public function testLevelIsTranslatedByTheCodebookRateValidOnTheTaxDate(): void
    {
        $this->codebookSays(['CZ|2020-05-15' => self::CZ_2020, 'CZ|2024-05-15' => self::CZ_2024]);

        self::assertSame(15.0, $this->rate($this->enumOnly(), 'CZ', '2020-05-15'));
        self::assertSame(12.0, $this->rate($this->enumOnly(), 'CZ', '2024-05-15'));
    }

    /**
     * JÁDRO ÚNIKU. Zahraniční odběratel enum bez procenta nesmí dostat sazbu země
     * dodavatele: Pohoda schema zahraniční sazby nezná, takže `high` na polském dokladu
     * znamená „skutečné procento je jinde", ne „21 %". Dosazení by cizí daň prohlásilo
     * za tuzemskou a číselník by ji vzápětí potvrdil.
     */
    public function testForeignCustomerNeverGetsTheSupplierCountryRate(): void
    {
        $this->codebookSays(['CZ|2020-05-15' => self::CZ_2020]);

        self::assertNull($this->rate($this->enumOnly('standard', 'high'), 'PL'));
    }

    /**
     * Neznámá země odběratele tuzemsko NENÍ. `ClientResolver` ukládá neznámou zemi jako
     * 'CZ', takže opačný výklad by tentýž únik obnovil u každého exportu, který zemi
     * protistrany neuvádí — a to je běžná neúplnost, ne okrajový případ.
     */
    public function testUnknownCustomerCountryIsNotDomestic(): void
    {
        $this->codebookSays(['CZ|2020-05-15' => self::CZ_2020]);

        self::assertNull($this->rate($this->enumOnly('standard', 'high'), null));
    }

    /** Procento ze souboru je nejsilnější zdroj — dosazení do něj nikdy nesmí sáhnout. */
    public function testPercentageFromTheFileWinsOverTheCodebook(): void
    {
        $this->codebookSays(['CZ|2020-05-15' => self::CZ_2020]);

        $item = ['vat_rate' => 23.0, 'vat_rate_level' => 'standard', 'vat_rate_enum' => 'high'];
        self::assertSame(23.0, $this->rate($item, 'CZ'));
    }

    /**
     * Úroveň, kterou číselník k datu nevede (druhá snížená sazba vznikla až v roce 2015),
     * zůstává NEURČENÁ. Dosazení nejbližší sazby ani nuly tu není v nabídce: nula by
     * z plnění udělala osvobozené, které invariant proti úniku vůbec neprověřuje.
     */
    public function testLevelTheCodebookDoesNotKnowStaysUnresolved(): void
    {
        $this->codebookSays(['CZ|2014-05-15' => [['rate_type' => 'standard', 'rate_percent' => 21.0]]]);

        self::assertNull($this->rate($this->enumOnly('second_reduced', 'low2'), 'CZ', '2014-05-15'));
    }

    /** `history*` a neznámé kódy úroveň nemají — není z čeho překládat ani u tuzemska. */
    public function testHistoricEnumWithoutALevelStaysUnresolvedEvenForADomesticCustomer(): void
    {
        $this->codebookSays(['CZ|2020-05-15' => self::CZ_2020]);

        $item = ['vat_rate' => null, 'vat_rate_level' => null, 'vat_rate_enum' => 'historyHigh'];
        self::assertNull($this->rate($item, 'CZ'));
    }

    /** Úroveň mimo doménu `oss_member_state_rates.rate_type` se číselníku neklade jako dotaz. */
    public function testLevelOutsideTheCodebookDomainIsIgnored(): void
    {
        $this->codebookSays(['CZ|2020-05-15' => self::CZ_2020]);

        self::assertNull($this->rate($this->enumOnly('vysoka', 'high'), 'CZ'));
    }

    /**
     * R2 — cena. Netto z parseru je PROVIZORNÍ, když se počítala koeficientem sazby, kterou
     * soubor neurčoval. Po rozhodnutí o sazbě se musí přepočítat z brutto: rozdíl mezi
     * provizorními 12 % a skutečnými 15 % je na dokladu z roku 2020 na každém řádku.
     */
    public function testProvisionalNetPriceIsRecomputedFromGrossOnceTheRateIsDecided(): void
    {
        $item = ['unit_price_without_vat' => 1150 / 1.12, 'unit_price_with_vat' => 1150.0];

        self::assertEqualsWithDelta(1000.0, $this->netUnitPrice($item, 15.0), 0.0001);
    }

    /** Bez brutto ceny je netto konečné (pochází z `typ:price`) — přepočítat by ho zkazilo. */
    public function testFinalNetPriceIsLeftAlone(): void
    {
        $item = ['unit_price_without_vat' => 1000.0, 'unit_price_with_vat' => null];

        self::assertSame(1000.0, $this->netUnitPrice($item, 21.0));
    }

    /** @param array<string,mixed> $item */
    private function netUnitPrice(array $item, float $rate): float
    {
        /** @var float */
        return (new \ReflectionMethod(InvoiceImportService::class, 'netUnitPrice'))
            ->invoke(null, $item, $rate);
    }

    /**
     * R3 — tři různé příčiny vedou ke třem různým krokům, takže se nesmějí slít do jedné
     * věty. Původní hláška mluvila jen o `history*` bez `inv:percentVAT` a u zbylých dvou
     * příčin radila mimo: uživatel s tuzemským dokladem hledal chybu v souboru, ačkoli
     * mu chyběl seed číselníku.
     */
    public function testEachCauseOfAnUnresolvedRateGetsItsOwnRemedy(): void
    {
        $this->codebookSays(['CZ|2020-05-15' => self::CZ_2020]);

        $foreign = $this->message($this->enumOnly('standard', 'high'), 'PL');
        self::assertStringContainsString('„high"', $foreign);
        self::assertStringContainsString('není tuzemský', $foreign);
        self::assertStringContainsString('inv:percentVAT', $foreign);

        $codebook = $this->message($this->enumOnly('second_reduced', 'low2'), 'CZ');
        self::assertStringContainsString('Číselník sazeb členských států', $codebook);
        self::assertStringContainsString('migrate.php', $codebook);

        $noLevel = $this->message(
            ['vat_rate' => null, 'vat_rate_level' => null, 'vat_rate_enum' => 'historyHigh'],
            'CZ',
        );
        self::assertStringContainsString('„historyHigh"', $noLevel);
        self::assertStringContainsString('neurčuje sazbu DPH', $noLevel);
        // Rada „doplňte procento" musí padnout i u ISDOCu, kde se element jmenuje jinak.
        self::assertStringContainsString('ClassifiedTaxCategory/Percent', $noLevel);
    }

    /**
     * R4 — BRÁNA SE MUSÍ PTÁT I NA ENUM-ONLY ŘÁDEK. Sazba se mu dosazuje z číselníku,
     * takže bez číselníku se odmítne stejně jako řádek s procentem. Kdyby se nezapočítal,
     * oněměla by brána přesně u běžného tuzemského exportu z Pohody, který `percentVAT`
     * nepíše — tedy u těch souborů, kvůli kterým existuje.
     */
    public function testTheCodebookGateAsksForEnumOnlyRowsToo(): void
    {
        $invoice = [
            'supplier' => ['ic' => '12345678'],
            'client' => ['ic' => '87654321'],
            'issue_date' => '2020-05-15',
            'tax_date' => '2020-05-15',
            'items' => [$this->enumOnly()],
        ];

        self::assertSame(['2020-05-15'], $this->datesNeedingRateCodebook([$invoice]));

        // Řádek bez sazby i bez úrovně zůstává vadou jednoho dokladu — na neznámé procento
        // se číselníku stejně není jak zeptat, takže bránu shodit nesmí.
        $invoice['items'] = [['vat_rate' => null, 'vat_rate_level' => null]];
        self::assertSame([], $this->datesNeedingRateCodebook([$invoice]));
    }

    /**
     * @param  list<array<string,mixed>> $invoices
     * @return list<string>
     */
    private function datesNeedingRateCodebook(array $invoices): array
    {
        /** @var list<string> */
        return (new \ReflectionMethod(InvoiceImportService::class, 'datesNeedingRateCodebook'))->invokeArgs(
            $this->service,
            [[['file' => 'davka.xml', 'invoices' => $invoices]], '12345678', 'issued'],
        );
    }
}
