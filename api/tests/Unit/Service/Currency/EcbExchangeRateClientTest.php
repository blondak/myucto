<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Currency;

use MyInvoice\Service\Currency\EcbExchangeRateClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Referenční kurzy ECB — čistá část (parser a aritmetika), bez sítě a bez DB.
 *
 * Hlídá tři věci, u kterých by tichá chyba znamenala špatnou částku v OSS podání:
 * orientaci kurzu (ECB kótuje JEDNOTKY ZA 1 EUR, ČNB naopak), přítomnost eura v sadě
 * (ECB samo sebe nekótuje) a přesnost kurzu při přepočtu.
 */
#[Group('unit')]
final class EcbExchangeRateClientTest extends TestCase
{
    private const SAMPLE = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01"
                         xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
          <gesmes:subject>Reference rates</gesmes:subject>
          <gesmes:Sender><gesmes:name>European Central Bank</gesmes:name></gesmes:Sender>
          <Cube>
            <Cube time="2096-07-01">
              <Cube currency="CZK" rate="25.000"/>
              <Cube currency="PLN" rate="4.3060"/>
              <Cube currency="USD" rate="1.1554"/>
            </Cube>
            <Cube time="2096-06-28">
              <Cube currency="CZK" rate="28.000"/>
              <Cube currency="PLN" rate="4.4000"/>
            </Cube>
          </Cube>
        </gesmes:Envelope>
        XML;

    public function testParseGroupsRatesByPublicationDay(): void
    {
        $parsed = EcbExchangeRateClient::parse(self::SAMPLE);

        self::assertSame(['2096-07-01', '2096-06-28'], array_keys($parsed));
        self::assertSame(25.0, $parsed['2096-07-01']['CZK']);
        self::assertSame(28.0, $parsed['2096-06-28']['CZK']);
    }

    /** ECB kótuje „kolik jednotek měny za 1 EUR" — opačně než ČNB. Záměna = řádová chyba. */
    public function testParseKeepsEcbOrientationOfTheRate(): void
    {
        $parsed = EcbExchangeRateClient::parse(self::SAMPLE);

        self::assertGreaterThan(1.0, $parsed['2096-07-01']['CZK'], 'Kurz CZK/EUR musí být ~25, ne ~0,04.');
        self::assertLessThan(1.0, EcbExchangeRateClient::crossRate($parsed['2096-07-01'], 'CZK', 'EUR') ?? 0.0);
    }

    /** Den bez jediné měny je pořád DEN publikace — prázdné pole se nesmí ztratit. */
    public function testParseKeepsDayWithoutAnyCurrency(): void
    {
        $xml = '<Cube><Cube time="2096-07-01"></Cube><Cube time="2096-07-02">'
            . '<Cube currency="CZK" rate="25.0"/></Cube></Cube>';

        $parsed = EcbExchangeRateClient::parse($xml);

        self::assertArrayHasKey('2096-07-01', $parsed);
        self::assertSame([], $parsed['2096-07-01']);
    }

    public function testParseIgnoresGarbage(): void
    {
        self::assertSame([], EcbExchangeRateClient::parse(''));
        self::assertSame([], EcbExchangeRateClient::parse('tohle není XML'));
        // Kurz bez data, nula i prázdný kód nesmí projít.
        self::assertSame(
            ['2096-07-01' => ['CZK' => 25.0]],
            EcbExchangeRateClient::parse(
                '<Cube><Cube currency="USD" rate="1.1"/><Cube time="2096-07-01">'
                . '<Cube currency="CZK" rate="25.0"/><Cube currency="XXX" rate="0"/>'
                . '<Cube currency="" rate="3"/></Cube></Cube>'
            ),
        );
    }

    /** ECB euro nekótuje — bez doplněné jedničky by přepočet do EUR neměl cílový kurz. */
    public function testCrossRateTreatsEuroAsUnit(): void
    {
        $rates = ['CZK' => 25.0];

        self::assertEqualsWithDelta(0.04, EcbExchangeRateClient::crossRate($rates, 'CZK', 'EUR'), 1e-12);
        self::assertEqualsWithDelta(25.0, EcbExchangeRateClient::crossRate($rates, 'EUR', 'CZK'), 1e-12);
    }

    /** Kříž mezi dvěma neeurovými měnami jde přes euro, ne přes CZK. */
    public function testCrossRateBetweenTwoNonEuroCurrencies(): void
    {
        $rates = ['CZK' => 25.0, 'PLN' => 4.0];

        self::assertEqualsWithDelta(0.16, EcbExchangeRateClient::crossRate($rates, 'CZK', 'PLN'), 1e-12);
    }

    public function testCrossRateIsNullForCurrencyEcbDoesNotQuote(): void
    {
        self::assertNull(EcbExchangeRateClient::crossRate(['CZK' => 25.0], 'XYZ', 'EUR'));
        self::assertNull(EcbExchangeRateClient::crossRate(['CZK' => 0.0], 'CZK', 'EUR'));
    }

    /** Půlhaléřová hranice jde NAHORU — částka míří do podání, ne do statistiky. */
    public function testApplyRateRoundsHalfUp(): void
    {
        self::assertSame(1.24, EcbExchangeRateClient::applyRate(12.35, 0.1));
        self::assertSame(-1.24, EcbExchangeRateClient::applyRate(-12.35, 0.1));
    }

    /**
     * Kurz nesmí projít zaokrouhlením na šest desetinných míst. Na milionovém základu
     * dělá takový ořez u kurzu 0,0413… rozdíl v jednotkách EUR — a je to částka, kterou
     * zákazník podává.
     */
    public function testApplyRateUsesFullRatePrecision(): void
    {
        $rate = EcbExchangeRateClient::crossRate(['CZK' => 24.195], 'CZK', 'EUR');
        self::assertNotNull($rate);

        $exact = EcbExchangeRateClient::applyRate(5_000_000.0, $rate);
        $truncated = EcbExchangeRateClient::applyRate(5_000_000.0, round($rate, 6));

        self::assertEqualsWithDelta(206_654.27, $exact, 0.01);
        self::assertNotEqualsWithDelta($exact, $truncated, 0.05, 'Ořez kurzu na 6 míst musí být měřitelný.');
    }
}
