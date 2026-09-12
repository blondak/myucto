<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\FuelingRepository;
use PDO;

/**
 * Doplnění odhadnutého stavu tachometru u tankování, které ho nemá.
 *
 * Odhad pro jedno tankování (v pořadí, v jakém řadu posuzuje {@see FuelingOdometerWarnings}):
 *   1) Jízda ve stejný den se známým začátkem i koncem → heuristika z knihy jízd
 *      ({@see FuelingOdometerEstimator::estimate()}: čas, jinak spotřeba).
 *   2) Jinak interpolace mezi nejbližším předchozím a následujícím ZNÁMÝM stavem vozu
 *      (skutečný tachometr u tankování, začátek/konec jízdy, počáteční stav vozidla).
 *      Leží-li mezi dvěma tankováními se skutečným stavem, dělí se ujetá vzdálenost
 *      podle natankovaného množství (případně částky), protože množství u tankování
 *      odpovídá spotřebě od předchozího tankování. Jinak lineárně podle data.
 *   3) Na krajích extrapolace průměrným denním nájezdem mezi prvním a posledním
 *      známým stavem.
 *
 * Výsledek se ořízne tak, aby řada tankování zůstala neklesající: nikdy pod žádný
 * dřívější stav (ani pod počáteční stav vozidla) a nikdy nad žádný pozdější. Kde to
 * sousední zadané stavy nedovolí (nesouvislá řada), odhad se nenabídne.
 *
 * Odhad se zapisuje jen do tankování bez stavu (`odometer IS NULL`) a nese příznak
 * `odometer_is_estimate`; odhadnuté stavy slouží dalším odhadům jen jako mez, ne jako
 * podklad, aby se chyba odhadu nesčítala.
 */
final class FuelingOdometerFill
{
    /** Vůz nemá žádný známý stav, není z čeho odhadovat. */
    public const REASON_NO_KNOWN = 'no_known_odometer';
    /** Známé stavy jsou jen k jednomu datu, chybí průměrný nájezd pro extrapolaci. */
    public const REASON_SINGLE_DATE = 'single_known_date';
    /** Sousední zadané stavy jdou proti sobě, odhad by řadu nesrovnal. */
    public const REASON_INCONSISTENT = 'inconsistent_series';

    public function __construct(
        private readonly Connection $db,
        private readonly FuelingRepository $repo,
        private readonly FuelingOdometerEstimator $estimator,
    ) {}

    /**
     * @return array{cars:list<array{car_id:int, registration:string, missing:int, estimable:int,
     *               skipped:array<string,int>, reason:?string,
     *               estimates:list<array{id:int, date:string, odometer:int, method:string}>}>,
     *               totals:array{missing:int, estimable:int}}
     */
    public function preview(int $supplierId, ?int $carId = null, ?int $year = null): array
    {
        $cars = $this->cars($supplierId, $carId);
        $out = ['cars' => [], 'totals' => ['missing' => 0, 'estimable' => 0]];
        if ($cars === []) return $out;

        $series = $this->series($supplierId, array_keys($cars));
        $withMissing = [];
        foreach ($series as $cid => $rows) {
            foreach ($rows as $r) {
                if ($r['odometer'] === null && ($year === null || self::year($r['date']) === $year)) {
                    $withMissing[] = $cid;
                    break;
                }
            }
        }
        if ($withMissing === []) return $out;

        $trips = $this->estimator->tripsByCar($supplierId, $withMissing);
        $consL = $this->estimator->consumptionByCar($supplierId, $withMissing, false);
        $consKwh = $this->estimator->consumptionByCar($supplierId, $withMissing, true);

        foreach ($withMissing as $cid) {
            $car = $cars[$cid];
            $plan = self::plan(
                $series[$cid], $trips[$cid] ?? [], $car['odometer_start'], $car['odometer_start_date'],
                $consL[$cid] ?? 7.0, $consKwh[$cid] ?? 18.0, $year,
            );
            $skipped = [];
            foreach ($plan['skipped'] as $s) $skipped[$s['reason']] = ($skipped[$s['reason']] ?? 0) + 1;
            arsort($skipped);
            $missing = count($plan['estimates']) + count($plan['skipped']);
            $out['cars'][] = [
                'car_id'       => $cid,
                'registration' => $car['registration'],
                'missing'      => $missing,
                'estimable'    => count($plan['estimates']),
                'skipped'      => $skipped,
                'reason'       => $skipped !== [] ? (string) array_key_first($skipped) : null,
                'estimates'    => $plan['estimates'],
            ];
            $out['totals']['missing'] += $missing;
            $out['totals']['estimable'] += count($plan['estimates']);
        }
        return $out;
    }

    /**
     * Zapíše odhady z {@see preview()}, a to jen do tankování, která stav pořád nemají.
     *
     * @return array{filled:int, filled_ids:list<int>, estimates:array<int,int>, cars:list<array<string,mixed>>, totals:array{missing:int, estimable:int}}
     */
    public function apply(int $supplierId, ?int $carId = null, ?int $year = null): array
    {
        $preview = $this->preview($supplierId, $carId, $year);
        $ids = [];
        $values = [];
        foreach ($preview['cars'] as &$car) {
            $car['filled'] = 0;
            foreach ($car['estimates'] as $e) {
                if ($this->repo->setEstimatedOdometer($e['id'], $supplierId, $e['odometer'])) {
                    $car['filled']++;
                    $ids[] = $e['id'];
                    $values[$e['id']] = $e['odometer'];
                }
            }
        }
        unset($car);
        return ['filled' => count($ids), 'filled_ids' => $ids, 'estimates' => $values] + $preview;
    }

    /**
     * Čistý výpočet odhadů pro jedno vozidlo (test seam).
     *
     * @param list<array{id:int, date:string, time?:?string, odometer:?int, estimate?:bool,
     *                   quantity?:?float, unit?:?string, amount?:?float, currency?:?string}> $series
     *        tankování vozidla seřazená podle data, času a id
     * @param list<array{date:string,time_start:?int,time_end:?int,odo_start:?int,odo_end:?int}> $trips
     * @return array{estimates:list<array{id:int, date:string, odometer:int, method:string}>,
     *               skipped:list<array{id:int, date:string, reason:string}>}
     */
    public static function plan(
        array $series,
        array $trips,
        ?int $odometerStart = null,
        ?string $odometerStartDate = null,
        float $consumption = 7.0,
        float $consumptionKwh = 18.0,
        ?int $year = null,
    ): array {
        $series = array_values($series);
        $n = count($series);

        // Podklady odhadu: skutečné stavy u tankování, jízdy, počáteční stav vozidla.
        $points = [];
        foreach ($trips as $t) {
            if ($t['odo_start'] !== null) $points[] = ['day' => self::day($t['date']), 'odo' => (int) $t['odo_start']];
            if ($t['odo_end'] !== null) $points[] = ['day' => self::day($t['date']), 'odo' => (int) $t['odo_end']];
        }
        if ($odometerStart !== null && $odometerStartDate !== null) {
            $points[] = ['day' => self::day($odometerStartDate), 'odo' => $odometerStart];
        }
        $anchors = $points;
        foreach ($series as $r) {
            if (self::isReal($r)) $anchors[] = ['day' => self::day($r['date']), 'odo' => (int) $r['odometer']];
        }
        $rate = self::dailyRate($anchors);

        // Horní mez: nejnižší známý stav (i odhadnutý) u pozdějších tankování.
        $suffixMin = array_fill(0, $n + 1, null);
        for ($i = $n - 1; $i >= 0; $i--) {
            $o = $series[$i]['odometer'];
            $next = $suffixMin[$i + 1];
            $suffixMin[$i] = $o === null ? $next : ($next === null ? (int) $o : min((int) $o, $next));
        }

        $lo = $odometerStart;
        $estimates = [];
        $skipped = [];
        foreach ($series as $i => $r) {
            if ($r['odometer'] !== null) {
                $lo = $lo === null ? (int) $r['odometer'] : max($lo, (int) $r['odometer']);
                continue;
            }
            if ($year !== null && self::year($r['date']) !== $year) continue;

            if ($anchors === []) {
                $skipped[] = ['id' => (int) $r['id'], 'date' => $r['date'], 'reason' => self::REASON_NO_KNOWN];
                continue;
            }
            [$value, $method] = self::candidate($series, $i, $trips, $points, $rate, $consumption, $consumptionKwh);
            if ($value === null) {
                $skipped[] = ['id' => (int) $r['id'], 'date' => $r['date'], 'reason' => self::REASON_SINGLE_DATE];
                continue;
            }
            $hi = $suffixMin[$i + 1];
            if ($lo !== null && $hi !== null && $lo > $hi) {
                $skipped[] = ['id' => (int) $r['id'], 'date' => $r['date'], 'reason' => self::REASON_INCONSISTENT];
                continue;
            }

            [$tripLo, $tripHi] = self::tripBounds($trips, $r['date']);
            if ($tripLo === null || $tripHi === null || $tripLo <= $tripHi) {
                if ($tripLo !== null) $value = max($value, $tripLo);
                if ($tripHi !== null) $value = min($value, $tripHi);
            }
            if ($lo !== null) $value = max($value, $lo);
            if ($hi !== null) $value = min($value, $hi);
            $value = max(0, $value);

            $estimates[] = ['id' => (int) $r['id'], 'date' => $r['date'], 'odometer' => $value, 'method' => $method];
            $lo = $lo === null ? $value : max($lo, $value);
        }
        return ['estimates' => $estimates, 'skipped' => $skipped];
    }

    /**
     * @param list<array<string,mixed>> $series
     * @param list<array{date:string,time_start:?int,time_end:?int,odo_start:?int,odo_end:?int}> $trips
     * @param list<array{day:int, odo:int}> $points podklady mimo tankování (jízdy, počáteční stav)
     * @return array{0:?int, 1:?string}
     */
    private static function candidate(array $series, int $i, array $trips, array $points, ?float $rate, float $consumption, float $consumptionKwh): array
    {
        $r = $series[$i];
        $date = (string) $r['date'];
        $day = self::day($date);

        $sameStart = false;
        $sameEnd = false;
        foreach ($trips as $t) {
            if ($t['date'] !== $date) continue;
            $sameStart = $sameStart || $t['odo_start'] !== null;
            $sameEnd = $sameEnd || $t['odo_end'] !== null;
        }
        if ($sameStart && $sameEnd) {
            $cons = FuelingOdometerEstimator::isElectricUnit($r['unit'] ?? null) ? $consumptionKwh : $consumption;
            $v = FuelingOdometerEstimator::estimate(
                ['fueled_date' => $date, 'fueled_time' => $r['time'] ?? null, 'quantity' => $r['quantity'] ?? null],
                $trips,
                $cons,
            );
            if ($v !== null) return [$v, 'trips'];
        }

        $left = null;
        $right = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (self::isReal($series[$j])) {
                $left = ['day' => self::day($series[$j]['date']), 'odo' => (int) $series[$j]['odometer'], 'idx' => $j];
                break;
            }
        }
        for ($j = $i + 1, $n = count($series); $j < $n; $j++) {
            if (self::isReal($series[$j])) {
                $right = ['day' => self::day($series[$j]['date']), 'odo' => (int) $series[$j]['odometer'], 'idx' => $j];
                break;
            }
        }
        foreach ($points as $p) {
            if ($p['day'] < $day) {
                if ($left === null || $p['day'] > $left['day'] || ($p['day'] === $left['day'] && $p['odo'] > $left['odo'])) $left = $p;
            } elseif ($p['day'] > $day) {
                if ($right === null || $p['day'] < $right['day'] || ($p['day'] === $right['day'] && $p['odo'] < $right['odo'])) $right = $p;
            }
        }

        if ($left !== null && $right !== null) {
            if (isset($left['idx'], $right['idx'])) {
                $weights = self::gapWeights($series, $left['idx'], $right['idx']);
                if ($weights !== null) {
                    $total = array_sum($weights);
                    $cum = 0.0;
                    for ($k = $left['idx'] + 1; $k <= $i; $k++) $cum += $weights[$k];
                    return [(int) round($left['odo'] + ($right['odo'] - $left['odo']) * $cum / $total), 'interpolation'];
                }
            }
            $span = $right['day'] - $left['day'];
            $frac = $span > 0 ? ($day - $left['day']) / $span : 0.0;
            return [(int) round($left['odo'] + ($right['odo'] - $left['odo']) * $frac), 'interpolation'];
        }
        if ($rate === null) return [null, null];
        if ($left !== null) return [(int) round($left['odo'] + $rate * ($day - $left['day'])), 'extrapolation'];
        if ($right !== null) return [(int) round($right['odo'] - $rate * ($right['day'] - $day)), 'extrapolation'];
        return [null, null];
    }

    /**
     * Váhy úseku mezi dvěma tankováními se skutečným stavem (řádky a+1 … b): množství,
     * když ho mají všechna ve stejné jednotce, jinak částka ve stejné měně, jinak null.
     *
     * @param list<array<string,mixed>> $series
     * @return array<int,float>|null
     */
    private static function gapWeights(array $series, int $a, int $b): ?array
    {
        if ($b <= $a + 1) return null;
        foreach ([['quantity', 'unit'], ['amount', 'currency']] as [$key, $classKey]) {
            $weights = [];
            $class = null;
            for ($k = $a + 1; $k <= $b; $k++) {
                $w = isset($series[$k][$key]) ? (float) $series[$k][$key] : 0.0;
                $c = $classKey === 'unit'
                    ? (FuelingOdometerEstimator::isElectricUnit($series[$k]['unit'] ?? null) ? 'kwh' : 'l')
                    : strtoupper((string) ($series[$k]['currency'] ?? ''));
                if ($w <= 0 || ($class !== null && $c !== $class)) {
                    $weights = null;
                    break;
                }
                $class = $c;
                $weights[$k] = $w;
            }
            if ($weights !== null) return $weights;
        }
        return null;
    }

    /**
     * Průměrný denní nájezd mezi prvním a posledním známým stavem (null = známé stavy
     * jen k jednomu datu).
     *
     * @param list<array{day:int, odo:int}> $anchors
     */
    private static function dailyRate(array $anchors): ?float
    {
        if (count($anchors) < 2) return null;
        $first = null;
        $last = null;
        foreach ($anchors as $p) {
            if ($first === null || $p['day'] < $first['day'] || ($p['day'] === $first['day'] && $p['odo'] < $first['odo'])) $first = $p;
            if ($last === null || $p['day'] > $last['day'] || ($p['day'] === $last['day'] && $p['odo'] > $last['odo'])) $last = $p;
        }
        $span = $last['day'] - $first['day'];
        if ($span <= 0) return null;
        return max(0.0, ($last['odo'] - $first['odo']) / $span);
    }

    /**
     * Stav jízd kolem dne: konec poslední jízdy před ním a začátek první jízdy po něm.
     *
     * @param list<array{date:string,time_start:?int,time_end:?int,odo_start:?int,odo_end:?int}> $trips
     * @return array{0:?int, 1:?int}
     */
    private static function tripBounds(array $trips, string $date): array
    {
        $lo = null;
        $hi = null;
        foreach ($trips as $t) {
            if ($t['date'] < $date && $t['odo_end'] !== null) $lo = $lo === null ? $t['odo_end'] : max($lo, $t['odo_end']);
            if ($t['date'] > $date && $t['odo_start'] !== null) $hi = $hi === null ? $t['odo_start'] : min($hi, $t['odo_start']);
        }
        return [$lo, $hi];
    }

    /** @param array<string,mixed> $r */
    private static function isReal(array $r): bool
    {
        return $r['odometer'] !== null && empty($r['estimate']);
    }

    private static function day(string $date): int
    {
        $ts = (new \DateTimeImmutable(substr($date, 0, 10) . ' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        return intdiv($ts, 86400);
    }

    private static function year(string $date): int
    {
        return (int) substr($date, 0, 4);
    }

    /**
     * @return array<int, array{registration:string, odometer_start:?int, odometer_start_date:?string}>
     */
    private function cars(int $supplierId, ?int $carId): array
    {
        $sql = 'SELECT id, registration, odometer_start, odometer_start_date FROM cars WHERE supplier_id = ?';
        $params = [$supplierId];
        if ($carId !== null) {
            $sql .= ' AND id = ?';
            $params[] = $carId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY registration');
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = [
                'registration'        => (string) $r['registration'],
                'odometer_start'      => $r['odometer_start'] !== null ? (int) $r['odometer_start'] : null,
                'odometer_start_date' => $r['odometer_start_date'] !== null ? (string) $r['odometer_start_date'] : null,
            ];
        }
        return $out;
    }

    /**
     * Řada tankování per vozidlo ve stejném pořadí jako {@see FuelingOdometerWarnings}.
     *
     * @param list<int> $carIds
     * @return array<int, list<array{id:int, date:string, time:?string, odometer:?int, estimate:bool,
     *                              quantity:?float, unit:string, amount:float, currency:string}>>
     */
    private function series(int $supplierId, array $carIds): array
    {
        $in = implode(',', array_fill(0, count($carIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, car_id, fueled_date, fueled_time, odometer, odometer_is_estimate, quantity, unit, amount_with_vat, currency
               FROM fuelings
              WHERE supplier_id = ? AND car_id IN ($in)
              ORDER BY car_id, fueled_date, COALESCE(fueled_time, '00:00:00'), id"
        );
        $stmt->execute(array_merge([$supplierId], $carIds));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['car_id']][] = [
                'id'       => (int) $r['id'],
                'date'     => (string) $r['fueled_date'],
                'time'     => $r['fueled_time'] !== null ? (string) $r['fueled_time'] : null,
                'odometer' => $r['odometer'] !== null ? (int) $r['odometer'] : null,
                'estimate' => (int) $r['odometer_is_estimate'] === 1,
                'quantity' => $r['quantity'] !== null ? (float) $r['quantity'] : null,
                'unit'     => (string) $r['unit'],
                'amount'   => (float) $r['amount_with_vat'],
                'currency' => (string) $r['currency'],
            ];
        }
        return $out;
    }
}
