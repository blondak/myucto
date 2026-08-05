<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\PohodaXmlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ČESKÁ KOTVA PŘIHRÁDKY SE NESMÍ POUŽÍT NA CIZOMĚNOVÉM DOKLADU (§ H4).
 *
 * Pohoda schema zná jen tři české sazbové přihrádky (High/Low/3), takže zahraniční sazba
 * v nich chodí „schovaná" a skutečné procento se dopočítá z ČÁSTEK téže rekapitulace.
 * Dopočet ale nevychází na kulaté číslo — producent zaokrouhluje po řádcích —, a proto se
 * výsledek přichytává na kotvu v toleranci 0,3 procentního bodu.
 *
 * Kotvy jsou dvě a NEJSOU si rovné:
 *   - `@rate` na přihrádce je údaj z TOHOTO souboru, takže se dá použít vždycky;
 *   - české výchozí procento přihrádky (21/12/10) je NÁŠ předpoklad o tom, co přihrádka
 *     znamená — a nad dokladem v cizí měně nemá co dělat.
 *
 * Rozdíl není kosmetický. Dopočtených 20,90 % přepsaných na rovných 21 % změní haléřový
 * šum v POZITIVNÍ tvrzení „tohle je česká sazba", a přesně tohle tvrzení čte invariant
 * proti úniku ({@see \MyInvoice\Service\Oss\OssItemDeriver}) jako potvrzení tuzemského
 * plnění: číselník členských států 21 % v ČR potvrdí, řádek projde kvadrantem „platí jen
 * v tuzemsku" a polská daň skončí na ř. 1 českého přiznání — bez jediného varování,
 * protože z pohledu systému je všechno v pořádku.
 *
 * Test proto netvrdí jen „klíč rekapitulace je 11,80" (to by byla kosmetika), ale i to,
 * že dopočtené procento dojde až na POLOŽKU, tedy tam, odkud se ptá derivace.
 */
final class PohodaXmlParserCzechAnchorTest extends TestCase
{
    private const DAT = 'http://www.stormware.cz/schema/version_2/data.xsd';
    private const INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
    private const TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';

    /**
     * Doklad o jedné položce, jejíž procento neurčuje nic než rekapitulace: `<inv:rateVAT>`
     * nese jen sazbovou ÚROVEŇ, `<inv:percentVAT>` na dokladu chybí. Přesně tak vypadá
     * export, u kterého dopočet z částek rozhoduje.
     */
    private function build(string $level, string $bucketXml, bool $foreign, float $unitPrice = 1000.0): string
    {
        $dat = self::DAT;
        $inv = self::INV;
        $typ = self::TYP;
        $priceBlock = $foreign
            ? "<inv:foreignCurrency><typ:unitPrice>{$unitPrice}</typ:unitPrice></inv:foreignCurrency>"
            : "<inv:homeCurrency><typ:unitPrice>{$unitPrice}</typ:unitPrice></inv:homeCurrency>";
        $summary = $foreign
            ? '<inv:invoiceSummary><inv:foreignCurrency>'
                . '<typ:currency><typ:ids>PLN</typ:ids></typ:currency><typ:rate>6.00</typ:rate>'
                . '<typ:amount>1</typ:amount>' . $bucketXml
                . '</inv:foreignCurrency></inv:invoiceSummary>'
            : '<inv:invoiceSummary><inv:homeCurrency>' . $bucketXml . '</inv:homeCurrency></inv:invoiceSummary>';

        return <<<XML
        <dat:dataPack xmlns:dat="$dat" xmlns:inv="$inv" xmlns:typ="$typ" ico="12345678">
          <dat:dataPackItem>
            <inv:invoice version="2.0">
              <inv:invoiceHeader>
                <inv:invoiceType>issuedInvoice</inv:invoiceType>
                <inv:symVar>2096001</inv:symVar>
                <inv:date>2096-05-15</inv:date>
              </inv:invoiceHeader>
              <inv:invoiceDetail>
                <inv:invoiceItem>
                  <inv:text>Zbozi do Polska</inv:text>
                  <inv:quantity>1</inv:quantity>
                  <inv:rateVAT>$level</inv:rateVAT>
                  $priceBlock
                </inv:invoiceItem>
              </inv:invoiceDetail>
              $summary
            </inv:invoice>
          </dat:dataPackItem>
        </dat:dataPack>
        XML;
    }

    /** @return array<string,mixed> */
    private function parseFirst(string $xml): array
    {
        $res = (new PohodaXmlParser())->parse($xml);
        self::assertNotEmpty($res['invoices']);
        self::assertArrayNotHasKey('__error', $res['invoices'][0]);

        return $res['invoices'][0];
    }

    /**
     * Všechny tři přihrádky, každá s dopočteným procentem UVNITŘ tolerance 0,3 p. b. od
     * své české kotvy. Kdyby kotvení zůstalo, přepsalo by se 20,80 na 21, 11,80 na 12
     * a 10,20 na 10 — a z každého takového dokladu by se stalo tvrzení o českém plnění.
     *
     * @return list<array{0:string, 1:string, 2:string, 3:float}>
     */
    public static function foreignBucketsNearTheCzechAnchor(): array
    {
        return [
            'High (česká kotva 21 %)' => [
                'high',
                '<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT>208.00</typ:priceHighVAT>',
                '20.80',
                20.8,
            ],
            'Low (česká kotva 12 %)' => [
                'low',
                '<typ:priceLow>1000.00</typ:priceLow><typ:priceLowVAT>118.00</typ:priceLowVAT>',
                '11.80',
                11.8,
            ],
            '3 (česká kotva 10 %)' => [
                'low2',
                '<typ:price3>1000.00</typ:price3><typ:price3VAT>102.00</typ:price3VAT>',
                '10.20',
                10.2,
            ],
        ];
    }

    #[DataProvider('foreignBucketsNearTheCzechAnchor')]
    public function testForeignBucketDoesNotSnapToTheCzechAnchor(
        string $level,
        string $bucketXml,
        string $expectedKey,
        float $expectedRate,
    ): void {
        $invoice = $this->parseFirst($this->build($level, $bucketXml, foreign: true));

        self::assertSame('PLN', $invoice['currency'], 'Předpoklad testu: doklad je v cizí měně.');
        self::assertSame([$expectedKey], array_keys($invoice['vat_recap']),
            'Dopočtené procento se přepsalo na české — z haléřového šumu se tím stalo tvrzení '
                . 'o tuzemském plnění, které invariant proti úniku bere jako potvrzení.');

        // A hlavně: procento musí dojít až na POLOŽKU, protože odtud se ptá derivace.
        self::assertSame($expectedRate, $invoice['items'][0]['vat_rate']);
        self::assertSame('summary_recap', $invoice['items'][0]['vat_rate_source']);
        self::assertSame([], $invoice['file_issues'], 'Doklad si neodporuje — hlásit nemá co.');
    }

    /**
     * PROTIPÓL: kotva ZE SOUBORU platí i v cizí měně. Omezení české kotvy nesmí připravit
     * dopočet o přesnost tam, kde producent procento sám deklaruje — jinak by se místo
     * tichého přepisu na českou sazbu tříštily klíče rekapitulace na 22,99 / 23,01.
     */
    public function testDeclaredRateStillAbsorbsPennyRoundingOnAForeignDocument(): void
    {
        $invoice = $this->parseFirst($this->build(
            'high',
            '<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT rate="23">229.90</typ:priceHighVAT>',
            foreign: true,
        ));

        self::assertSame(['23.00'], array_keys($invoice['vat_recap']));
        self::assertSame(23.0, $invoice['items'][0]['vat_rate']);
    }

    /**
     * DRUHÝ PROTIPÓL: na KORUNOVÉM dokladu česká kotva zůstává. Tam je předpoklad „přihrádka
     * High znamená 21 %" pravdivý a jeho zrušení by rozbilo běžný tuzemský import — klíče
     * rekapitulace by se roztříštily podle haléřů a `vat_recap` by přestal sedět
     * s číselníkem sazeb.
     *
     * @return list<array{0:string, 1:string, 2:string, 3:float}>
     */
    public static function homeBucketsNearTheCzechAnchor(): array
    {
        return [
            'High' => [
                'high',
                '<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT>208.00</typ:priceHighVAT>',
                '21.00',
                21.0,
            ],
            'Low' => [
                'low',
                '<typ:priceLow>1000.00</typ:priceLow><typ:priceLowVAT>118.00</typ:priceLowVAT>',
                '12.00',
                12.0,
            ],
        ];
    }

    #[DataProvider('homeBucketsNearTheCzechAnchor')]
    public function testHomeCurrencyBucketStillSnapsToTheCzechAnchor(
        string $level,
        string $bucketXml,
        string $expectedKey,
        float $expectedRate,
    ): void {
        $invoice = $this->parseFirst($this->build($level, $bucketXml, foreign: false));

        self::assertSame('CZK', $invoice['currency']);
        self::assertSame([$expectedKey], array_keys($invoice['vat_recap']));
        self::assertSame($expectedRate, $invoice['items'][0]['vat_rate']);
    }

    /**
     * Mimo toleranci se nekotví ani doma. Bez tohohle případu by testy výše prošly
     * i implementaci „kotvi vždycky", stačilo by rozšířit toleranci.
     */
    public function testRateFarFromTheAnchorIsKeptAsCalculatedEvenInCzk(): void
    {
        $invoice = $this->parseFirst($this->build(
            'high',
            '<typ:priceHigh>1000.00</typ:priceHigh><typ:priceHighVAT>230.00</typ:priceHighVAT>',
            foreign: false,
        ));

        self::assertSame(['23.00'], array_keys($invoice['vat_recap']));
        self::assertSame(23.0, $invoice['items'][0]['vat_rate']);
    }
}
