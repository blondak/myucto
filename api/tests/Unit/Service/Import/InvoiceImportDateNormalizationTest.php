<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Service\Import\PohodaXmlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ÚNIK Č. 2 NA HRANICI IMPORTU — nekanonické datum nesmí obejít invariant proti úniku
 * cizí daně, a nečitelné datum nesmí projít do databáze.
 *
 * Reprodukovaný nález: Pohoda XML s `<inv:date>2096-5-15</inv:date>` (bez vodicích nul)
 * a BEZ `<inv:dateTax>`. Parser datum jen trimoval, takže se dál nesla hodnota, na kterou
 * `OssItemDeriver` neuměl odpovědět — a protože se nevědomost o datu dřív vydávala za
 * tuzemsko, polská sazba 23 % se napárovala na uživatelovu sazbu „PL-23" vedenou pod zemí
 * CZ a doklad skončil na ř. 1 českého přiznání jako česká daň na výstupu. MariaDB přitom
 * mlčela: `'2096-5-15'` přijme do sloupce `DATE` i do porovnání s `valid_from` bez hlesnutí,
 * takže po sobě celý řetěz nezanechal jedinou stopu.
 *
 * Proto se tu netestuje jen „regexp umí doplnit nulu". Testuje se HRANICE: co doklad
 * přinese ({@see PohodaXmlParser}), to musí být zkanonizované DŘÍV, než se o dokladu
 * cokoli rozhodne — a co zkanonizovat nejde, je vada dokladu s hláškou, ne tichý propad
 * do větve, která invariant obchází.
 *
 * Bez databáze schválně: `normalizeDates()` je čistá funkce a její chyby jsou tiché.
 * Integrační protějšek (týž doklad projde celým importem a skončí v OSS, ne na ř. 1) je
 * v `Tests\Integration\Import\OssInvoiceImportTest`.
 */
final class InvoiceImportDateNormalizationTest extends TestCase
{
    /**
     * @param  array<string,mixed> $inv
     * @return array<string,mixed>
     */
    private function normalize(array $inv): array
    {
        return (new \ReflectionMethod(InvoiceImportService::class, 'normalizeDates'))->invoke(null, $inv);
    }

    /**
     * DOSLOVNÁ reprodukce úniku č. 2 na hranici: datum bez vodicích nul a chybějící DUZP,
     * tak jak to dorazilo v souboru zákazníka.
     *
     * Kanonizace tu není kosmetika. Platnost sazby v číselníku členských států i platnost
     * registrace do OSS se porovnávají jako ŘETĚZCE, takže '2096-5-15' odpovídá na jinou
     * otázku než '2096-05-15' — lexikograficky leží až za '2096-12-31'.
     */
    public function testNonCanonicalIssueDateIsCanonicalisedAndMissingTaxDateStaysNull(): void
    {
        $dates = $this->normalize(['issue_date' => '2096-5-15', 'tax_date' => '', 'due_date' => '2096-6-15']);

        self::assertArrayNotHasKey('error', $dates, 'Čitelné datum není vada dokladu, jen jiný tvar.');
        self::assertSame('2096-05-15', $dates['issue_date']);
        self::assertNull($dates['tax_date'], 'Prázdné DUZP zůstává prázdné — dosadit ho by byl dohad.');
        self::assertSame('2096-06-15', $dates['due_date']);
    }

    /**
     * Táž věta, ale nad SKUTEČNÝM výstupem parseru — hranice se testuje tam, kde je.
     * Kdyby se parser někdy začal normalizovat sám, tenhle test zůstane zelený; kdyby
     * naopak někdo kanonizaci z importu odstranil s odůvodněním „parser to už dělá",
     * spadne.
     */
    public function testLeakTwoDocumentIsCanonicalisedBetweenParserAndImport(): void
    {
        $invoice = (new PohodaXmlParser())->parse(self::LEAK_TWO_XML)['invoices'][0];

        self::assertSame('2096-5-15', $invoice['issue_date'],
            'Předpoklad testu: parser datum NEnormalizuje. Kdyby ano, hranice by tu netvrdila nic.');
        self::assertNull($invoice['tax_date']);

        $dates = $this->normalize($invoice);

        self::assertArrayNotHasKey('error', $dates);
        self::assertSame('2096-05-15', $dates['issue_date'],
            'Do databáze i do derivace musí jít kanonický tvar — jinak sazbu ověřuje jiné datum, '
                . 'než jaké je na dokladu.');
        self::assertNull($dates['tax_date']);
    }

    /**
     * Nečitelné datum vystavení = vada DOKLADU. Dřív se propadlo až do INSERTu a uživatel
     * dostal `SQLSTATE[22007] Incorrect date value` — a to až POTÉ, co `ClientResolver`
     * stihl přes ARES/VIES založit odběratele.
     *
     * @param mixed $raw hodnota tak, jak přišla ze souboru
     */
    #[DataProvider('unusableDates')]
    public function testUnusableIssueDateRejectsTheWholeDocument(mixed $raw, string $expectedFragment): void
    {
        $dates = $this->normalize(['issue_date' => $raw, 'tax_date' => '2096-05-15', 'due_date' => '2096-06-15']);

        self::assertArrayHasKey('error', $dates, 'Nečitelné datum se nesmí dosadit odhadem.');
        self::assertStringContainsString('datum vystavení', $dates['error']);
        self::assertStringContainsString('RRRR-MM-DD', $dates['error'],
            'Hláška musí říct, co se očekává — u 1 670 dokladů je „neplatné datum" k ničemu.');
        self::assertStringContainsString($expectedFragment, $dates['error'],
            'Hláška musí obsahovat hodnotu ZE SOUBORU, aby ji uživatel v souboru našel.');
    }

    /** @return array<string, array{0:mixed, 1:string}> */
    public static function unusableDates(): array
    {
        return [
            'český tvar' => ['15. 7. 2096', '15. 7. 2096'],
            'neexistující den' => ['2096-02-30', '2096-02-30'],
            'nula místo měsíce' => ['2096-00-15', '2096-00-15'],
            'čas navíc' => ['2096-05-15T00:00:00', '2096-05-15T00:00:00'],
            'americký tvar' => ['05/15/2096', '05/15/2096'],
            'prázdno' => ['', 'v souboru chybí'],
            'chybějící klíč' => [null, 'v souboru chybí'],
        ];
    }

    /**
     * DUZP a splatnost mají stejnou laťku jako datum vystavení. DUZP proto, že z něj plyne
     * zdaňovací období i to, která sazba k tomu dni platila; splatnost proto, že prázdný
     * řetězec by ve sloupci `DATE` skončil chybou z databáze.
     */
    public function testUnusableTaxDateRejectsTheWholeDocument(): void
    {
        $dates = $this->normalize(['issue_date' => '2096-05-15', 'tax_date' => '15. 5. 2096', 'due_date' => '2096-06-15']);

        self::assertArrayHasKey('error', $dates);
        self::assertStringContainsString('datum uskutečnění zdanitelného plnění', $dates['error']);
    }

    public function testUnusableDueDateRejectsTheWholeDocument(): void
    {
        $dates = $this->normalize(['issue_date' => '2096-05-15', 'tax_date' => '2096-05-15', 'due_date' => '2096-13-01']);

        self::assertArrayHasKey('error', $dates);
        self::assertStringContainsString('datum splatnosti', $dates['error']);
    }

    /**
     * Proforma bez DUZP a doklad bez splatnosti jsou běžné, ne vadné — kanonizace je
     * nesmí začít odmítat. Bez tohohle tvrzení by šel invariant „vynutit" tak, že by
     * spadla polovina legitimních dokladů.
     */
    public function testMissingTaxDateAndDueDateAreNormalStates(): void
    {
        $dates = $this->normalize(['issue_date' => '2096-05-15', 'due_date' => '']);

        self::assertArrayNotHasKey('error', $dates);
        self::assertNull($dates['tax_date']);
        self::assertSame('2096-05-15', $dates['due_date'], 'Prázdná splatnost se dorovná datem vystavení.');
    }

    /** Kanonický tvar se nesmí cestou změnit — nejtriviálnější, a proto nejsnáz rozbitelné. */
    public function testCanonicalDatesPassThroughUnchanged(): void
    {
        $dates = $this->normalize([
            'issue_date' => '2096-05-15',
            'tax_date'   => '2096-05-31',
            'due_date'   => '2096-06-15',
        ]);

        self::assertSame(
            ['issue_date' => '2096-05-15', 'tax_date' => '2096-05-31', 'due_date' => '2096-06-15'],
            $dates,
        );
    }

    /**
     * Import a derivace se ptají TÉHOŽ pravidla. Vlastní kopie regexu na hranici by se
     * s deriverem rozešla a doklad by prošel s datem, na které deriver odpoví jinak než
     * zápis do databáze — což je přesně tvar úniku č. 2.
     */
    public function testBoundaryUsesTheSameRuleAsTheDeriver(): void
    {
        foreach (['2096-5-15', '2096-05-15', '2096-02-30', '15. 7. 2096', ''] as $raw) {
            $viaBoundary = $this->normalize(['issue_date' => $raw, 'due_date' => '2096-06-15']);
            $viaDeriver = \MyInvoice\Service\Oss\OssItemDeriver::canonicalDate($raw);

            self::assertSame(
                $viaDeriver,
                $viaBoundary['issue_date'] ?? null,
                sprintf('Hranice a deriver se rozešly na hodnotě „%s".', $raw),
            );
        }
    }

    /**
     * Pohoda XML z nálezu: `<inv:date>` bez vodicích nul, `<inv:dateTax>` chybí úplně,
     * položka nese skutečných 23 % v `<inv:percentVAT>` a odběratel je polský spotřebitel
     * bez DIČ.
     */
    private const LEAK_TWO_XML = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
                      xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
                      xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
                      version="2.0" ico="12345678">
          <dat:dataPackItem version="2.0">
            <inv:invoice version="2.0">
              <inv:invoiceHeader>
                <inv:invoiceType>issuedInvoice</inv:invoiceType>
                <inv:number><typ:numberRequested>26OSS0099</typ:numberRequested></inv:number>
                <inv:symVar>26OSS0099</inv:symVar>
                <inv:date>2096-5-15</inv:date>
                <inv:dateDue>2096-6-15</inv:dateDue>
                <inv:partnerIdentity>
                  <typ:address>
                    <typ:company>Testowy Odbiorca sp. z o.o.</typ:company>
                    <typ:city>Warszawa</typ:city>
                    <typ:country><typ:ids>PL</typ:ids></typ:country>
                  </typ:address>
                </inv:partnerIdentity>
              </inv:invoiceHeader>
              <inv:invoiceDetail>
                <inv:invoiceItem>
                  <inv:text>Zboží do Polska</inv:text>
                  <inv:quantity>1</inv:quantity>
                  <inv:unit>kg</inv:unit>
                  <inv:rateVAT>historyHigh</inv:rateVAT>
                  <inv:percentVAT>23</inv:percentVAT>
                  <inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice></inv:homeCurrency>
                </inv:invoiceItem>
              </inv:invoiceDetail>
            </inv:invoice>
          </dat:dataPackItem>
        </dat:dataPack>
        XML;
}
