<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převod starých účetních agend nese doklady se sazbami DPH, které už neplatí. Bez nich
 * v číselníku doklad nejde uložit (vat_rate_id je NOT NULL) a převod celé firmy skončí.
 */
#[Group('integration')]
final class VatRatesHistoricalTest extends TestCase
{
    /** @return iterable<string,array{0:float,1:string}> */
    public static function historicalRates(): iterable
    {
        yield 'základní 2012' => [20.0, '2012-06-30'];
        yield 'snížená 2012' => [14.0, '2012-06-30'];
        yield 'základní 2011' => [20.0, '2011-06-30'];
        yield 'snížená 2011' => [10.0, '2011-06-30'];
        yield 'základní 2009' => [19.0, '2009-06-30'];
        yield 'snížená 2009' => [9.0, '2009-06-30'];
        yield 'snížená 2007' => [5.0, '2007-06-30'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('historicalRates')]
    public function testRateValidOnDateExists(float $rate, string $date): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $pdo = Bootstrap::buildContainer()->get(Connection::class)->pdo();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM vat_rates
              WHERE country = 'CZ' AND is_reverse_charge = 0 AND rate_percent = ?
                AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)"
        );
        $stmt->execute([number_format($rate, 2, '.', ''), $date, $date]);

        self::assertSame(1, (int) $stmt->fetchColumn(), "Sazba {$rate} % platná k {$date} v číselníku chybí.");
    }
}
