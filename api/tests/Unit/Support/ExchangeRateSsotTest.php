<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Support;

use MyInvoice\Support\ExchangeRateDate;
use MyInvoice\Support\ExchangeRateSources;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SSOT pro kurz: rozhodný den dokladu a taxonomie zdrojů (migrace 1303).
 *
 * Obojí rozhoduje o tom, jestli automatika smí přepsat kurz na dokladu, takže se to
 * hodí mít připíchnuté i bez databáze.
 */
final class ExchangeRateSsotTest extends TestCase
{
    public function testPurchaseDatePrefersTaxDateAndFallsBackToIssueDate(): void
    {
        self::assertSame('2026-03-31', ExchangeRateDate::forPurchase([
            'tax_date' => '2026-03-31', 'issue_date' => '2026-04-05',
        ]));
        self::assertSame('2026-04-05', ExchangeRateDate::forPurchase([
            'tax_date' => null, 'issue_date' => '2026-04-05',
        ]));
        self::assertNull(ExchangeRateDate::forPurchase([]));
    }

    /**
     * Prázdný řetězec ani nulové datum se nesmí tvářit jako platný den — z takové
     * hodnoty by `new DateTimeImmutable()` udělal „dnešek", a kurz by se načetl
     * k úplně jinému dni, než na jaký doklad patří.
     */
    #[DataProvider('emptyishDatesProvider')]
    public function testEmptyishTaxDateFallsThroughToIssueDate(mixed $taxDate): void
    {
        self::assertSame('2026-04-05', ExchangeRateDate::forPurchase([
            'tax_date' => $taxDate, 'issue_date' => '2026-04-05',
        ]));
    }

    /** @return list<array{mixed}> */
    public static function emptyishDatesProvider(): array
    {
        return [[''], ['   '], ['0000-00-00'], [null], [false], [0]];
    }

    /** DATETIME z DB se ořízne na kalendářní den — porovnává se s DATE sloupci. */
    public function testDateTimeValueIsTruncatedToDay(): void
    {
        self::assertSame('2026-04-05', ExchangeRateDate::forPurchase([
            'tax_date' => '2026-04-05 13:45:00', 'issue_date' => '2026-04-01',
        ]));
    }

    public function testInvoiceAndPurchaseUseTheSameExpression(): void
    {
        $doc = ['tax_date' => '2026-01-31', 'issue_date' => '2026-02-03'];
        self::assertSame(ExchangeRateDate::forPurchase($doc), ExchangeRateDate::forInvoice($doc));
        self::assertSame(
            ExchangeRateDate::purchaseSql('x'),
            ExchangeRateDate::invoiceSql('x'),
            'Rozhodný den je na obou stranách týž výraz — jinak by se větve rozešly.',
        );
    }

    /** Přepsat smí přenačtení jen kurz, který je funkcí data. */
    public function testOnlyDateDerivedSourcesAreAutoReloadable(): void
    {
        self::assertTrue(ExchangeRateSources::isAutoReloadable('cnb'));
        self::assertTrue(ExchangeRateSources::isAutoReloadable('fixed'));

        foreach (['user', 'manual', 'import', 'idoklad', 'fakturoid'] as $protected) {
            self::assertFalse(
                ExchangeRateSources::isAutoReloadable($protected),
                "Zdroj '{$protected}' není odvozený z data — automatika ho přepsat nesmí.",
            );
        }
    }

    /** Neznámá hodnota nesmí spadnout na něco přepisovatelného (fail-safe). */
    public function testUnknownSourceNormalizesToNonReloadableDefault(): void
    {
        self::assertSame('manual', ExchangeRateSources::normalize('neco_jineho'));
        self::assertSame('manual', ExchangeRateSources::normalize(null));
        self::assertFalse(ExchangeRateSources::isAutoReloadable('neco_jineho'));
        self::assertFalse(ExchangeRateSources::isValid('neco_jineho'));
    }

    public function testResolvedApplierSourceMapsToCnbOrFixed(): void
    {
        self::assertSame('fixed', ExchangeRateSources::fromResolved('fixed'));
        foreach (['fresh', 'cache', 'last_known', null] as $source) {
            self::assertSame('cnb', ExchangeRateSources::fromResolved($source));
        }
    }

    public function testUserOutranksEverythingElse(): void
    {
        self::assertGreaterThan(ExchangeRateSources::rank('import'), ExchangeRateSources::rank('user'));
        self::assertGreaterThan(ExchangeRateSources::rank('cnb'), ExchangeRateSources::rank('import'));
        self::assertSame(ExchangeRateSources::rank('cnb'), ExchangeRateSources::rank('fixed'));
        self::assertSame(ExchangeRateSources::rank('import'), ExchangeRateSources::rank('manual'));
    }
}
