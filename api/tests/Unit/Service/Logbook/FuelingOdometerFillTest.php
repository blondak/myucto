<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Logbook;

use MyInvoice\Service\Logbook\FuelingOdometerFill;
use PHPUnit\Framework\TestCase;

final class FuelingOdometerFillTest extends TestCase
{
    private static function row(int $id, string $date, ?int $odo, ?float $qty = null, ?float $amount = null, bool $estimate = false): array
    {
        return [
            'id' => $id, 'date' => $date, 'time' => null, 'odometer' => $odo, 'estimate' => $estimate,
            'quantity' => $qty, 'unit' => 'l', 'amount' => $amount, 'currency' => 'CZK',
        ];
    }

    /** @return array<int,int> id → odhad */
    private static function values(array $plan): array
    {
        $out = [];
        foreach ($plan['estimates'] as $e) $out[$e['id']] = $e['odometer'];
        return $out;
    }

    /** Mezi dvěma tankováními se stavem se ujetá vzdálenost dělí podle natankovaných litrů. */
    public function testInterpolatesByQuantityBetweenKnownFuelings(): void
    {
        $plan = FuelingOdometerFill::plan([
            self::row(1, '2099-01-01', 10000, 40.0),
            self::row(2, '2099-01-15', null, 30.0),
            self::row(3, '2099-01-31', null, 30.0),
            self::row(4, '2099-02-10', 11000, 40.0),
        ], []);

        self::assertSame([2 => 10300, 3 => 10600], self::values($plan));
        self::assertSame('interpolation', $plan['estimates'][0]['method']);
        self::assertSame([], $plan['skipped']);
    }

    /** Bez litrů rozhoduje částka. */
    public function testInterpolatesByAmountWhenQuantityIsMissing(): void
    {
        $plan = FuelingOdometerFill::plan([
            self::row(1, '2099-01-01', 10000, null, 1000.0),
            self::row(2, '2099-01-15', null, null, 1000.0),
            self::row(3, '2099-02-10', 11000, null, 3000.0),
        ], []);

        self::assertSame([2 => 10250], self::values($plan));
    }

    /** Bez litrů i částek lineárně podle data. */
    public function testInterpolatesByDateWithoutWeights(): void
    {
        $plan = FuelingOdometerFill::plan([
            self::row(1, '2099-01-01', 10000),
            self::row(2, '2099-01-15', null),
            self::row(3, '2099-01-31', null),
            self::row(4, '2099-02-10', 11000),
        ], []);

        // 14/40 a 30/40 z 1 000 km
        self::assertSame([2 => 10350, 3 => 10750], self::values($plan));
    }

    /** Na krajích extrapolace průměrným denním nájezdem (1 000 km / 40 dní = 25 km/den). */
    public function testExtrapolatesBeyondKnownStatesByAverageDailyMileage(): void
    {
        $plan = FuelingOdometerFill::plan([
            self::row(1, '2098-12-22', null),
            self::row(2, '2099-01-01', 10000),
            self::row(3, '2099-02-10', 11000),
            self::row(4, '2099-02-20', null),
        ], []);

        self::assertSame([1 => 9750, 4 => 11250], self::values($plan));
        self::assertSame('extrapolation', $plan['estimates'][0]['method']);
    }

    /** Zpětná extrapolace nikdy neklesne pod počáteční stav vozidla. */
    public function testBackwardExtrapolationStaysAboveVehicleStart(): void
    {
        $plan = FuelingOdometerFill::plan([
            self::row(1, '2098-12-22', null),
            self::row(2, '2099-01-01', 10000),
            self::row(3, '2099-02-10', 11000),
        ], [], 9900);

        self::assertSame([1 => 9900], self::values($plan));
    }

    /** Odhad vzniká jen pro tankování bez stavu, zadané hodnoty se nevracejí ani nemění. */
    public function testKnownOdometersAreNeverEstimated(): void
    {
        $plan = FuelingOdometerFill::plan([
            self::row(1, '2099-01-01', 10000, 40.0),
            self::row(2, '2099-01-10', null, 40.0),
            self::row(3, '2099-01-20', 10800, 40.0),
            self::row(4, '2099-01-30', 11600, 40.0),
        ], []);

        self::assertSame([2], array_keys(self::values($plan)));
    }

    /** Vůz bez jediného známého stavu (tankování, jízdy, počáteční stav) se neodhaduje. */
    public function testCarWithoutKnownOdometerIsNotEstimated(): void
    {
        $plan = FuelingOdometerFill::plan([
            self::row(1, '2099-01-01', null, 40.0),
            self::row(2, '2099-01-15', null, 30.0),
        ], []);

        self::assertSame([], $plan['estimates']);
        self::assertSame([FuelingOdometerFill::REASON_NO_KNOWN, FuelingOdometerFill::REASON_NO_KNOWN], array_column($plan['skipped'], 'reason'));
    }

    /** Jediný známý stav nedává průměrný nájezd, mimo něj se neodhaduje. */
    public function testSingleKnownStateCannotExtrapolate(): void
    {
        $plan = FuelingOdometerFill::plan([
            self::row(1, '2099-01-01', 10000, 40.0),
            self::row(2, '2099-02-01', null, 30.0),
        ], []);

        self::assertSame([], $plan['estimates']);
        self::assertSame(FuelingOdometerFill::REASON_SINGLE_DATE, $plan['skipped'][0]['reason']);
    }

    /** Sousední zadané stavy jdou proti sobě → monotónní odhad neexistuje, nenabídne se. */
    public function testInconsistentNeighboursAreSkipped(): void
    {
        $plan = FuelingOdometerFill::plan([
            self::row(1, '2099-01-01', 12000),
            self::row(2, '2099-01-15', null),
            self::row(3, '2099-02-01', 11000),
        ], []);

        self::assertSame([], $plan['estimates']);
        self::assertSame(FuelingOdometerFill::REASON_INCONSISTENT, $plan['skipped'][0]['reason']);
    }

    /** Jízda ve stejný den → heuristika knihy jízd (tankování před jízdou = začátek jízdy). */
    public function testSameDayTripUsesLogbookHeuristic(): void
    {
        $trips = [
            ['date' => '2099-01-10', 'time_start' => 8 * 60, 'time_end' => 12 * 60, 'odo_start' => 100000, 'odo_end' => 100200],
            ['date' => '2099-01-20', 'time_start' => null, 'time_end' => null, 'odo_start' => 100500, 'odo_end' => 100800],
        ];
        $series = [self::row(1, '2099-01-10', null, 50.0)];
        $series[0]['time'] = '07:30';

        $plan = FuelingOdometerFill::plan($series, $trips);

        self::assertSame([1 => 100000], self::values($plan));
        self::assertSame('trips', $plan['estimates'][0]['method']);
    }

    /** Bez jízdy ve stejný den se interpoluje i mezi stavy z knihy jízd. */
    public function testInterpolatesBetweenTripStates(): void
    {
        $trips = [
            ['date' => '2099-01-01', 'time_start' => null, 'time_end' => null, 'odo_start' => 20000, 'odo_end' => 20100],
            ['date' => '2099-01-11', 'time_start' => null, 'time_end' => null, 'odo_start' => 21100, 'odo_end' => 21200],
        ];
        $plan = FuelingOdometerFill::plan([self::row(1, '2099-01-06', null, 40.0)], $trips);

        self::assertSame([1 => 20600], self::values($plan));
    }

    /** Dřívější odhad je jen mez (řada zůstane neklesající), ne podklad dalšího odhadu. */
    public function testEarlierEstimateIsBoundNotAnchor(): void
    {
        $plan = FuelingOdometerFill::plan([
            self::row(1, '2099-01-01', 10000),
            self::row(2, '2099-01-10', 10900, null, null, true),
            self::row(3, '2099-01-20', null),
            self::row(4, '2099-01-30', 11000),
        ], []);

        // Interpolace F1 → F4 dá 10 655, ale řada nesmí klesnout pod odhad 10 900.
        self::assertSame([3 => 10900], self::values($plan));
    }

    /** Odhady drží řadu neklesající i proti sobě navzájem. */
    public function testEstimatesKeepSeriesMonotonic(): void
    {
        $plan = FuelingOdometerFill::plan([
            self::row(1, '2099-01-01', 10000, 10.0),
            self::row(2, '2099-01-05', null, 60.0),
            self::row(3, '2099-01-06', null, 5.0),
            self::row(4, '2099-01-07', null),
            self::row(5, '2099-01-20', 10500, 10.0),
            self::row(6, '2099-01-25', null, 20.0),
            self::row(7, '2099-02-05', 12000, 20.0),
        ], [], 9000, '2098-12-01');

        $series = [10000];
        foreach ([2, 3, 4] as $id) $series[] = self::values($plan)[$id];
        $series[] = 10500;
        $series[] = self::values($plan)[6];
        $series[] = 12000;
        $sorted = $series;
        sort($sorted);
        self::assertSame($sorted, $series);
    }

    /** Filtr roku odhaduje jen tankování daného roku, řadu posuzuje z celé historie. */
    public function testYearFilterLimitsEstimatedRows(): void
    {
        $plan = FuelingOdometerFill::plan([
            self::row(1, '2098-12-20', null),
            self::row(2, '2099-01-01', 10000),
            self::row(3, '2099-01-15', null),
            self::row(4, '2099-02-10', 11000),
        ], [], null, null, 7.0, 18.0, 2099);

        self::assertSame([3], array_keys(self::values($plan)));
    }
}
