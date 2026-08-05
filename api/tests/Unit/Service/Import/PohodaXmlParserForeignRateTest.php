<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\PohodaXmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Zahraniční sazby (OSS) a náhrada variabilního symbolu v Pohoda XML.
 *
 * Pohoda schema zná jen české sazbové úrovně, takže zahraniční procento chodí
 * v `percentVAT` / `rateVAT@value` a v summary se schová do české přihrádky.
 */
final class PohodaXmlParserForeignRateTest extends TestCase
{
    private const DAT = 'http://www.stormware.cz/schema/version_2/data.xsd';
    private const INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
    private const TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';

    /**
     * @param string $headerExtra doplňkové elementy hlavičky (symVar/number)
     */
    private function build(string $itemXml, string $summaryXml = '', string $headerExtra = '<inv:symVar>2026001</inv:symVar>'): string
    {
        $dat = self::DAT;
        $inv = self::INV;
        $typ = self::TYP;

        return <<<XML
        <dat:dataPack xmlns:dat="$dat" xmlns:inv="$inv" xmlns:typ="$typ" ico="12345678">
          <dat:dataPackItem>
            <inv:invoice version="2.0">
              <inv:invoiceHeader>
                <inv:invoiceType>issuedInvoice</inv:invoiceType>
                $headerExtra
                <inv:date>2026-06-15</inv:date>
              </inv:invoiceHeader>
              <inv:invoiceDetail>
                <inv:invoiceItem>
                  <inv:text>Sluzba</inv:text>
                  <inv:quantity>1</inv:quantity>
                  $itemXml
                  <inv:homeCurrency><typ:unitPrice>100</typ:unitPrice></inv:homeCurrency>
                </inv:invoiceItem>
              </inv:invoiceDetail>
              $summaryXml
            </inv:invoice>
          </dat:dataPackItem>
        </dat:dataPack>
        XML;
    }

    /**
     * @return array<string,mixed>
     */
    private function parseFirst(string $xml): array
    {
        $res = (new PohodaXmlParser())->parse($xml);
        self::assertNotEmpty($res['invoices']);
        self::assertArrayNotHasKey('__error', $res['invoices'][0]);

        return $res['invoices'][0];
    }

    private function firstRate(string $itemXml): ?float
    {
        return $this->parseFirst($this->build($itemXml))['items'][0]['vat_rate'];
    }

    /** Přesně to, co posílá SuperFaktura: 23 % v percentVAT, historyHigh v rateVAT. */
    public function testPercentVatWinsOverHistoryHighEnum(): void
    {
        self::assertSame(23.0, $this->firstRate(
            '<inv:rateVAT>historyHigh</inv:rateVAT><inv:percentVAT>23</inv:percentVAT>'
        ));
    }

    public function testPercentVatWinsOverStandardEnum(): void
    {
        self::assertSame(27.0, $this->firstRate(
            '<inv:rateVAT>high</inv:rateVAT><inv:percentVAT>27</inv:percentVAT>'
        ));
    }

    /**
     * `history*` znamená doslova „tahle sazba už neplatí, skutečné procento je
     * v percentVAT". Bez `percentVAT` proto sazba ZNÁMÁ NENÍ a parser ji nesmí dosadit —
     * ani nulou (z dokladu by zmizela daň), ani aktuální českou sazbou téže úrovně.
     * Druhá varianta byla dřív v kódu jako „nejmenší zlo" a byl to únik: polských 23 %
     * dostalo 21 %, prošlo kvadrantem „platí jen v tuzemsku" a skončilo na ř. 1 českého
     * přiznání bez jediného varování. Rozhodnout smí až invariant nad parserem.
     */
    public function testHistoryEnumsWithoutPercentAreUnknownNotGuessed(): void
    {
        self::assertNull($this->firstRate('<inv:rateVAT>historyHigh</inv:rateVAT>'));
        self::assertNull($this->firstRate('<inv:rateVAT>historyLow</inv:rateVAT>'));
        self::assertNull($this->firstRate('<inv:rateVAT>historyLow2</inv:rateVAT>'));
        self::assertNull($this->firstRate('<inv:rateVAT>historyThird</inv:rateVAT>'));
        self::assertNull($this->firstRate('<inv:rateVAT>uplneNeznamyKod</inv:rateVAT>'));
    }

    /**
     * Ani AKTUÁLNÍ česká sazbová úroveň procento neurčuje. `high` je jen jméno úrovně
     * a dosadit za něj 21 % byl přesně ten únik: doklad polského spotřebitele dostal
     * českou sazbu, číselník ji jako českou POTVRDIL, invariant proti úniku řádek pustil
     * jako tuzemský a polská daň skončila na ř. 1 přiznání. Procento smí dosadit až
     * vrstva, která zná zemi dodavatele a číselník k datu plnění.
     */
    public function testCurrentCzechEnumsAloneDoNotDetermineTheRate(): void
    {
        foreach (['high', 'low', 'low2', 'third'] as $code) {
            self::assertNull($this->firstRate("<inv:rateVAT>$code</inv:rateVAT>"), $code);
        }
    }

    /** Úroveň se pojmenuje hodnotami `oss_member_state_rates.rate_type`. */
    public function testEnumLevelsMatchCodebookRateTypes(): void
    {
        $levels = [];
        foreach (['high', 'low', 'low2', 'third', 'historyHigh', 'none'] as $code) {
            $levels[$code] = $this->parseFirst($this->build("<inv:rateVAT>$code</inv:rateVAT>"))
                ['items'][0]['vat_rate_level'];
        }

        self::assertSame([
            'high'        => 'standard',
            'low'         => 'reduced',
            'low2'        => 'second_reduced',
            'third'       => 'second_reduced',
            'historyHigh' => null,
            'none'        => null,
        ], $levels);
    }

    public function testExemptCodesStayZero(): void
    {
        self::assertSame(0.0, $this->firstRate('<inv:rateVAT>none</inv:rateVAT>'));
        self::assertSame(0.0, $this->firstRate('<inv:rateVAT>nonSubsume</inv:rateVAT>'));
        self::assertSame(0.0, $this->firstRate(''));
    }

    /** `rateVAT@value` je exportní atribut se skutečným procentem. */
    public function testRateVatValueAttributeUsedWhenPercentMissing(): void
    {
        self::assertSame(23.0, $this->firstRate('<inv:rateVAT value="23">historyHigh</inv:rateVAT>'));
    }

    /**
     * `percentVAT=0` u položky označené `high` je artefakt producenta — nula by
     * z celého dokladu udělala osvobozené plnění. Sazba tím ale známá nebude.
     */
    public function testZeroPercentVatDoesNotBecomeExemptSupply(): void
    {
        self::assertNull($this->firstRate(
            '<inv:rateVAT>high</inv:rateVAT><inv:percentVAT>0</inv:percentVAT>'
        ));
    }

    public function testGarbagePercentVatIgnored(): void
    {
        self::assertNull($this->firstRate(
            '<inv:rateVAT>high</inv:rateVAT><inv:percentVAT>abc</inv:percentVAT>'
        ));
        self::assertNull($this->firstRate(
            '<inv:rateVAT>high</inv:rateVAT><inv:percentVAT>1000</inv:percentVAT>'
        ));
    }

    // --- Dopočet sazby položky z rekapitulace (§ G1 krok 2) ----------------

    /**
     * Běžný tuzemský export z Pohody `percentVAT` nepíše, ale rekapitulace nese základ
     * i daň — 210 / 1 000 je 21 %. To není hádání, ale aritmetika ze STEJNÉHO souboru,
     * takže se sazba určit dá a doklad se nemusí odmítnout.
     */
    public function testRateIsDerivedFromTheMatchingSummaryBucket(): void
    {
        $item = $this->parseFirst($this->build(
            '<inv:rateVAT>high</inv:rateVAT>',
            '<inv:invoiceSummary><inv:homeCurrency>'
            . '<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT>210.00</typ:priceHighVAT>'
            . '</inv:homeCurrency></inv:invoiceSummary>'
        ))['items'][0];

        self::assertSame(21.0, $item['vat_rate']);
        self::assertSame('summary_recap', $item['vat_rate_source']);
    }

    /**
     * Přesně soubor, který si sám odporuje: položka nese `high` bez procenta, ale
     * rekapitulace v témže souboru deklaruje 23 % na základ 1 000. Rozhodují částky,
     * takže se položka určí na 23 % — dřív z ní bylo českých 21 % a s nimi ř. 1.
     */
    public function testForeignRateHiddenInCzechBucketReachesTheItem(): void
    {
        $item = $this->parseFirst($this->build(
            '<inv:rateVAT>high</inv:rateVAT>',
            $this->foreignSummary(
                '<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT rate="23">230.00</typ:priceHighVAT>'
            )
        ))['items'][0];

        self::assertSame(23.0, $item['vat_rate']);
        self::assertSame('summary_recap', $item['vat_rate_source']);
    }

    /** Sazbu bere ODPOVÍDAJÍCÍ přihrádka, ne první, která je po ruce. */
    public function testRateComesFromTheBucketOfItsOwnLevel(): void
    {
        $summary = '<inv:invoiceSummary><inv:homeCurrency>'
            . '<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT>210.00</typ:priceHighVAT>'
            . '<typ:priceLow>500.00</typ:priceLow><typ:priceLowVAT>60.00</typ:priceLowVAT>'
            . '</inv:homeCurrency></inv:invoiceSummary>';

        self::assertSame(21.0, $this->parseFirst($this->build('<inv:rateVAT>high</inv:rateVAT>', $summary))['items'][0]['vat_rate']);
        self::assertSame(12.0, $this->parseFirst($this->build('<inv:rateVAT>low</inv:rateVAT>', $summary))['items'][0]['vat_rate']);
    }

    /**
     * Přihrádka bez daňové částky procento neuvádí — a české výchozí procento přihrádky
     * je dohad, který se do sazby POLOŽKY dostat nesmí (do `vat_recap` jako override
     * součtů korunového dokladu ano, viz testHomeBucketWithoutVatKeepsCzechDefault).
     */
    public function testBucketWithoutVatAmountDoesNotDetermineTheItemRate(): void
    {
        $item = $this->parseFirst($this->build(
            '<inv:rateVAT>high</inv:rateVAT>',
            '<inv:invoiceSummary><inv:homeCurrency>'
            . '<typ:priceHigh>1000.00</typ:priceHigh>'
            . '</inv:homeCurrency></inv:invoiceSummary>'
        ))['items'][0];

        self::assertNull($item['vat_rate']);
        self::assertSame('unresolved', $item['vat_rate_source']);
    }

    /** `percentVAT` na položce přebíjí i rekapitulaci — je to údaj o TÉHLE položce. */
    public function testItemPercentBeatsTheSummaryBucket(): void
    {
        $item = $this->parseFirst($this->build(
            '<inv:rateVAT>high</inv:rateVAT><inv:percentVAT>23</inv:percentVAT>',
            '<inv:invoiceSummary><inv:homeCurrency>'
            . '<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT>210.00</typ:priceHighVAT>'
            . '</inv:homeCurrency></inv:invoiceSummary>'
        ))['items'][0];

        self::assertSame(23.0, $item['vat_rate']);
        self::assertSame('percent', $item['vat_rate_source']);
    }

    // --- Křížová kontrola položek proti rekapitulaci (§ G2) ----------------

    /**
     * Doklad, jehož položka tvrdí 23 % a rekapitulace 21 % na tomtéž základu, si
     * odporuje. Dřív prošel tiše — a mlčky se vybralo jedno z obou čísel.
     */
    public function testContradictingRatesAreReportedAsFileIssue(): void
    {
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>historyHigh</inv:rateVAT><inv:percentVAT>23</inv:percentVAT>',
            '<inv:invoiceSummary><inv:homeCurrency>'
            . '<typ:priceHigh>100.00</typ:priceHigh><typ:priceHighVAT>21.00</typ:priceHighVAT>'
            . '</inv:homeCurrency></inv:invoiceSummary>'
        ));

        self::assertCount(1, $invoice['file_issues']);
        self::assertStringContainsString('23 %', $invoice['file_issues'][0]);
        self::assertStringContainsString('21 %', $invoice['file_issues'][0]);
    }

    /** Sedící doklad nesmí hlásit nic — jinak by se hláška stala šumem. */
    public function testConsistentDocumentHasNoFileIssues(): void
    {
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>historyHigh</inv:rateVAT><inv:percentVAT>21</inv:percentVAT>',
            '<inv:invoiceSummary><inv:homeCurrency>'
            . '<typ:priceHigh>100.00</typ:priceHigh><typ:priceHighVAT>21.00</typ:priceHighVAT>'
            . '</inv:homeCurrency></inv:invoiceSummary>'
        ));

        self::assertSame([], $invoice['file_issues']);
    }

    /** Rozdíl v ZÁKLADU při shodné sazbě je vada souboru stejně jako rozdíl v sazbě. */
    public function testMismatchedBaseIsReported(): void
    {
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>historyHigh</inv:rateVAT><inv:percentVAT>21</inv:percentVAT>',
            '<inv:invoiceSummary><inv:homeCurrency>'
            . '<typ:priceHigh>250.00</typ:priceHigh><typ:priceHighVAT>52.50</typ:priceHighVAT>'
            . '</inv:homeCurrency></inv:invoiceSummary>'
        ));

        self::assertCount(1, $invoice['file_issues']);
        self::assertStringContainsString('250,00', $invoice['file_issues'][0]);
    }

    /** Haléřové zaokrouhlení producenta rozpor není. */
    public function testPennyRoundingIsNotReported(): void
    {
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>historyHigh</inv:rateVAT><inv:percentVAT>21</inv:percentVAT>',
            '<inv:invoiceSummary><inv:homeCurrency>'
            . '<typ:priceHigh>100.01</typ:priceHigh><typ:priceHighVAT>21.00</typ:priceHighVAT>'
            . '</inv:homeCurrency></inv:invoiceSummary>'
        ));

        self::assertSame([], $invoice['file_issues']);
    }

    /**
     * Položka s neurčenou sazbou kontrolu vypíná: doklad je stejně k odmítnutí
     * s konkrétnější hláškou a obecný rozpor by ji jen přehlušil.
     */
    public function testUnknownItemRateSuppressesTheCrossCheck(): void
    {
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>historyHigh</inv:rateVAT>',
            '<inv:invoiceSummary><inv:homeCurrency>'
            . '<typ:priceHigh>250.00</typ:priceHigh><typ:priceHighVAT>52.50</typ:priceHighVAT>'
            . '</inv:homeCurrency></inv:invoiceSummary>'
        ));

        self::assertNull($invoice['items'][0]['vat_rate']);
        self::assertSame([], $invoice['file_issues']);
    }

    /** Doklad bez rekapitulace není s čím křížit. */
    public function testDocumentWithoutSummaryHasNoFileIssues(): void
    {
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>historyHigh</inv:rateVAT><inv:percentVAT>23</inv:percentVAT>'
        ));

        self::assertSame([], $invoice['file_issues']);
    }

    // --- Rekapitulace ------------------------------------------------------

    private function foreignSummary(string $buckets): string
    {
        return '<inv:invoiceSummary><inv:foreignCurrency>'
            . '<typ:currency><typ:ids>PLN</typ:ids></typ:currency><typ:rate>5.80</typ:rate>'
            . $buckets
            . '</inv:foreignCurrency></inv:invoiceSummary>';
    }

    /** 23 % leží v přihrádce High — procento se musí vzít z částek, ne z názvu. */
    public function testForeignRecapDerivesRateFromAmounts(): void
    {
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>historyHigh</inv:rateVAT><inv:percentVAT>23</inv:percentVAT>',
            $this->foreignSummary('<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT>230.00</typ:priceHighVAT>')
        ));

        self::assertSame('PLN', $invoice['currency']);
        self::assertArrayNotHasKey('21.00', $invoice['vat_recap']);
        self::assertSame(['base' => 1000.00, 'vat' => 230.00], $invoice['vat_recap']['23.00']);
    }

    /** Deklarované @rate nesmí přebít částky, když si odporují. */
    public function testAmountsBeatContradictingRateAttribute(): void
    {
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>historyHigh</inv:rateVAT><inv:percentVAT>23</inv:percentVAT>',
            $this->foreignSummary('<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT rate="21">230.00</typ:priceHighVAT>')
        ));

        self::assertSame(['23.00'], array_keys($invoice['vat_recap']));
    }

    /** Haléřové zaokrouhlení se přichytí na kotvu, ať se klíče netříští. */
    public function testRoundingNoiseSnapsToAnchor(): void
    {
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>high</inv:rateVAT>',
            '<inv:invoiceSummary><inv:homeCurrency>'
            . '<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT>209.90</typ:priceHighVAT>'
            . '</inv:homeCurrency></inv:invoiceSummary>'
        ));

        self::assertSame(['21.00'], array_keys($invoice['vat_recap']));
    }

    /**
     * Na CIZOMĚNOVÉM dokladu česká kotva (21/12/10) kotvit NESMÍ.
     *
     * Tolerance 0,3 p. b. je tu kvůli haléřům, ne kvůli tomu, aby se dopočtená sazba
     * převyprávěla na českou. Dopočtených 20,90 % přepsaných na rovných 21 % není
     * kosmetika: z haléřového šumu vznikne POZITIVNÍ tvrzení „tohle je česká sazba",
     * které invariant proti úniku přečte jako potvrzení tuzemského plnění — a řádek
     * skončí v českém přiznání místo v OSS.
     */
    public function testForeignRoundingNoiseDoesNotSnapToTheCzechAnchor(): void
    {
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>high</inv:rateVAT>',
            $this->foreignSummary('<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT>209.00</typ:priceHighVAT>')
        ));

        self::assertSame(['20.90'], array_keys($invoice['vat_recap']));
        self::assertSame(20.9, $invoice['items'][0]['vat_rate'], 'položka bere procento z téže přihrádky');
        self::assertSame('summary_recap', $invoice['items'][0]['vat_rate_source']);
    }

    /**
     * Kotva ZE SOUBORU (`@rate`) platí i na cizoměnovém dokladu — haléře pohltí ona,
     * takže omezení české kotvy o přesnost dopočtu nepřipraví.
     */
    public function testForeignDeclaredRateStillAbsorbsPennyRounding(): void
    {
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>high</inv:rateVAT>',
            $this->foreignSummary('<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT rate="23">229.90</typ:priceHighVAT>')
        ));

        self::assertSame(['23.00'], array_keys($invoice['vat_recap']));
    }

    /**
     * Cizoměnová přihrádka bez DPH částky nejde ověřit — vrátit českých 21 %
     * by bylo tvrzení o zahraničním plnění, které nemáme z čeho doložit.
     */
    public function testForeignBucketWithoutVatIsSkipped(): void
    {
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>historyHigh</inv:rateVAT><inv:percentVAT>23</inv:percentVAT>',
            $this->foreignSummary('<typ:priceHigh>1000.00</typ:priceHigh>')
        ));

        self::assertSame([], $invoice['vat_recap']);
    }

    /** U korunového dokladu zůstává české výchozí procento přihrádky. */
    public function testHomeBucketWithoutVatKeepsCzechDefault(): void
    {
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>high</inv:rateVAT>',
            '<inv:invoiceSummary><inv:homeCurrency>'
            . '<typ:priceHigh>1000.00</typ:priceHigh>'
            . '</inv:homeCurrency></inv:invoiceSummary>'
        ));

        self::assertSame(['base' => 1000.00, 'vat' => 0.0], $invoice['vat_recap']['21.00']);
    }

    /** Dvě přihrádky se stejným procentem se sečtou, ne přepíšou. */
    public function testBucketsWithSameRateAreMerged(): void
    {
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>high</inv:rateVAT>',
            '<inv:invoiceSummary><inv:homeCurrency>'
            . '<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT>210.00</typ:priceHighVAT>'
            . '<typ:priceLow>500.00</typ:priceLow><typ:priceLowVAT>105.00</typ:priceLowVAT>'
            . '</inv:homeCurrency></inv:invoiceSummary>'
        ));

        self::assertSame(['21.00'], array_keys($invoice['vat_recap']));
        self::assertSame(['base' => 1500.00, 'vat' => 315.00], $invoice['vat_recap']['21.00']);
    }

    // --- Variabilní symbol -------------------------------------------------

    public function testValidSymVarIsKept(): void
    {
        $invoice = $this->parseFirst($this->build('<inv:rateVAT>high</inv:rateVAT>'));

        self::assertSame('2026001', $invoice['varsymbol']);
        self::assertSame('symVar', $invoice['varsymbol_source']);
        self::assertNull($invoice['varsymbol_original']);
    }

    /** GUID producenta neprojde validací importu → náhrada číslem dokladu. */
    public function testGuidSymVarFallsBackToDocumentNumber(): void
    {
        $guid = '0f8e1c2a-4b6d-4e8f-9a1b-2c3d4e5f6a7b';
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>high</inv:rateVAT>',
            '',
            "<inv:symVar>$guid</inv:symVar>"
            . '<inv:number><typ:numberRequested>26FV0042</typ:numberRequested></inv:number>'
        ));

        self::assertSame('26FV0042', $invoice['varsymbol']);
        self::assertSame('number', $invoice['varsymbol_source']);
        self::assertSame($guid, $invoice['varsymbol_original']);
        self::assertTrue(PohodaXmlParser::isAcceptableVarsymbol($invoice['varsymbol']));
    }

    /** Prázdný symVar není náhrada — nebylo co nahradit. */
    public function testEmptySymVarReportsNoReplacedValue(): void
    {
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>high</inv:rateVAT>',
            '',
            '<inv:number><typ:numberRequested>26FV0043</typ:numberRequested></inv:number>'
        ));

        self::assertSame('26FV0043', $invoice['varsymbol']);
        self::assertSame('number', $invoice['varsymbol_source']);
        self::assertNull($invoice['varsymbol_original']);
    }

    /** Číslo dokladu s lomítkem taky neprojde — sanitizuje se a odliší zdrojem. */
    public function testUnusableDocumentNumberIsSanitized(): void
    {
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>high</inv:rateVAT>',
            '',
            '<inv:symVar>nejaky zcela neplatny symbol</inv:symVar>'
            . '<inv:number><typ:numberRequested>2026/0123</typ:numberRequested></inv:number>'
        ));

        self::assertSame('2026-0123', $invoice['varsymbol']);
        self::assertSame('number_sanitized', $invoice['varsymbol_source']);
        self::assertSame('nejaky zcela neplatny symbol', $invoice['varsymbol_original']);
    }

    /** Není čím nahradit → původní hodnota projde dál, ať ji import vypíše v reportu. */
    public function testInvalidSymVarWithoutFallbackIsPassedThrough(): void
    {
        $guid = '0f8e1c2a-4b6d-4e8f-9a1b-2c3d4e5f6a7b';
        $invoice = $this->parseFirst($this->build(
            '<inv:rateVAT>high</inv:rateVAT>',
            '',
            "<inv:symVar>$guid</inv:symVar>"
        ));

        self::assertSame($guid, $invoice['varsymbol']);
        self::assertSame('symVar', $invoice['varsymbol_source']);
        self::assertNull($invoice['varsymbol_original']);
        self::assertFalse(PohodaXmlParser::isAcceptableVarsymbol($invoice['varsymbol']));
    }

    public function testMissingBothSymVarAndNumberYieldsError(): void
    {
        $res = (new PohodaXmlParser())->parse($this->build('<inv:rateVAT>high</inv:rateVAT>', '', ''));

        self::assertArrayHasKey('__error', $res['invoices'][0]);
    }

    public function testVarsymbolPatternMatchesImportRule(): void
    {
        self::assertTrue(PohodaXmlParser::isAcceptableVarsymbol('26FV0042'));
        self::assertTrue(PohodaXmlParser::isAcceptableVarsymbol('a_b-c'));
        self::assertFalse(PohodaXmlParser::isAcceptableVarsymbol(''));
        self::assertFalse(PohodaXmlParser::isAcceptableVarsymbol('2026/0123'));
        self::assertFalse(PohodaXmlParser::isAcceptableVarsymbol(str_repeat('9', 21)));
    }
}
