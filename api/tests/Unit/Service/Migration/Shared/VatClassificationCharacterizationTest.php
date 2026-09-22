<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use MyInvoice\Service\Migration\MoneyS3\Ms3VatCode;
use MyInvoice\Service\Migration\Pohoda\PohodaVat;
use MyInvoice\Service\Migration\Premier\PremierVat;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxVat;
use PHPUnit\Framework\TestCase;

/**
 * Charakterizace klasifikace DPH všech čtyř převodů (Money S3, Pohoda, PREMIER, Stereo NX).
 *
 * Otisk ve `fixtures/vat-classification-master.json` vznikl z kódu PŘED sjednocením tabulek
 * řádků přiznání do sdílené vrstvy a drží, co každý převod pro daný vstup vrací. Pokrývá
 * všechny jednotlivé řádky 0-66, dvojice a vybrané trojice řádků, přípony členění Money,
 * příznaky číselníku PREMIER a sazební přihrádky Stereo NX. V otisku jsou jen vstupy
 * s nenulovým výsledkem; vstup, který by nově dostal zařazení, se projeví jako klíč navíc.
 *
 * Otisk se přegeneruje jen vědomou změnou chování: `MYUCTO_VAT_SNAPSHOT_WRITE=1`.
 */
final class VatClassificationCharacterizationTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/vat-classification-master.json';

    /** Řádky, jejichž dvojice se procházejí. */
    private const PAIR_LINES = [1, 2, 3, 4, 5, 6, 7, 8, 10, 11, 12, 13, 20, 21, 22, 23, 24, 25, 26, 30, 31, 40, 41, 42, 43, 44, 45, 47, 50, 51, 52, 60, 61];

    /** Řádky, jejichž trojice se procházejí. */
    private const TRIPLE_LINES = [1, 2, 3, 4, 5, 10, 11, 12, 20, 25, 40, 41, 43, 44, 47, 50, 51];

    private const MONEY_SUFFIXES = ['', 'M', 'P', '_S', 'K', 'MK', 'PK', 'MR', 'PR', '_C', '_P', 'BN', 'X', '_SK'];

    public function testClassificationMatchesMasterSnapshot(): void
    {
        $actual = self::collect();
        if (getenv('MYUCTO_VAT_SNAPSHOT_WRITE') === '1') {
            file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            self::markTestSkipped('Otisk klasifikace DPH přegenerován.');
        }
        $expected = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(array_keys($expected), array_keys($actual));
        foreach ($expected as $source => $cases) {
            self::assertSame([], array_keys(array_diff_key($actual[$source], $cases)), "{$source}: vstupy, které nově dostaly výsledek");
            self::assertSame([], array_keys(array_diff_key($cases, $actual[$source])), "{$source}: vstupy, které výsledek ztratily");
            foreach ($cases as $key => $value) {
                self::assertSame($value, $actual[$source][$key], "{$source} {$key}");
            }
        }
    }

    public function testSnapshotCoversEverySource(): void
    {
        $expected = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
        foreach (['money', 'money_reverse', 'pohoda', 'premier', 'premier_rate', 'stereo'] as $source) {
            self::assertGreaterThan(20, count($expected[$source] ?? []), $source);
        }
    }

    /** @return array<string,array<string,string>> */
    private static function collect(): array
    {
        return [
            'money' => self::money(),
            'money_reverse' => self::moneyReverse(),
            'pohoda' => self::pohoda(),
            'premier' => self::premier(),
            'premier_rate' => self::premierRates(),
            'stereo' => self::stereo(),
        ];
    }

    /** @return list<list<int>> */
    private static function lineSets(): array
    {
        $sets = [[]];
        for ($l = 0; $l <= 66; $l++) {
            $sets[] = [$l];
        }
        $pairs = self::PAIR_LINES;
        foreach ($pairs as $i => $a) {
            foreach (array_slice($pairs, $i + 1) as $b) {
                $sets[] = [$a, $b];
            }
        }
        $t = self::TRIPLE_LINES;
        $n = count($t);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                for ($k = $j + 1; $k < $n; $k++) {
                    $sets[] = [$t[$i], $t[$j], $t[$k]];
                }
            }
        }
        return $sets;
    }

    /** @param list<int> $lines */
    private static function linesKey(array $lines): string
    {
        return $lines === [] ? '-' : implode(',', $lines);
    }

    private static function enc(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,string> */
    private static function money(): array
    {
        $codes = ['', 'X', '19Ř', '19Ř00P', '19Ř00U', '19Ř0', '19R40,41', '19Ř 40,41', '19Ř40, 41', '19Ř40 41', '25Ř40,41', '19Ř40,41 K', '19Ř 40,41 MK', '19Ř40,41k'];
        foreach (self::lineSets() as $lines) {
            if (count($lines) > 2 && !in_array($lines, [[1, 2, 51], [40, 41, 47], [43, 44, 47]], true)) {
                continue;
            }
            $rows = implode(',', array_map(static fn (int $l): string => sprintf('%02d', $l), $lines));
            foreach (self::MONEY_SUFFIXES as $suffix) {
                $codes[] = '19Ř' . $rows . $suffix;
            }
        }
        $out = [];
        foreach (array_unique($codes) as $code) {
            $issued = Ms3VatCode::resolve($code, true);
            $received = Ms3VatCode::resolve($code, false);
            $rc = Ms3VatCode::isReverseChargeOutput($code);
            if ($issued === null && $received === null && !$rc) {
                continue;
            }
            $out[$code] = self::enc(['issued' => $issued, 'received' => $received, 'rc_output' => $rc]);
        }
        return $out;
    }

    /** @return array<string,string> */
    private static function moneyReverse(): array
    {
        $outputs = [];
        foreach (['03,04', '05,06', '07,08', '10,11', '12,13', '40,41', '43,44', '01,02', '03', '10'] as $rows) {
            foreach (['', '_S', 'K', 'M'] as $suffix) {
                $outputs[] = '19Ř' . $rows . $suffix;
            }
        }
        $outputs[] = '19Ř 10,11 ';
        $outputs[] = 'X';
        $mirrors = [null, '', ' ', '19Ř43,44', '19Ř43,44M', '19Ř43,44P', '19Ř43,44K', '19Ř43,44MK', '19Ř43,44PK', '19Ř43,44MR', '19Ř43,44_S', '19Ř43,44X', '19Ř40,41', '19Ř 43,44 K', 'X'];
        $subjects = ['', '4', '5', '3', ' 5 ', '1', 'X'];
        $out = [];
        foreach ($outputs as $o) {
            foreach ($mirrors as $m) {
                foreach ($subjects as $s) {
                    $r = Ms3VatCode::reverseCharge($o, $m, $s);
                    if ($r !== null) {
                        $out[$o . '|' . ($m ?? 'NULL') . '|' . $s] = self::enc($r);
                    }
                }
            }
        }
        return $out;
    }

    /** @return array<string,string> */
    private static function pohoda(): array
    {
        $classes = [];
        foreach (self::lineSets() as $lines) {
            $k = self::linesKey($lines);
            foreach (['C' . $k, 'PK' . $k, 'PDK' . $k] as $code) {
                $classes[$code] = ['lines' => $lines, 'name' => 'N ' . $code, 'section' => ''];
            }
        }
        foreach (['A.5', 'A.5.', 'A.5. ', 'A.4., A.5.', 'B.3', 'A.1'] as $i => $section) {
            $classes['S' . $i] = ['lines' => [1, 2], 'name' => '', 'section' => $section];
        }
        $make = \Closure::bind(static fn (array $c): PohodaVat => new PohodaVat($c), null, PohodaVat::class);
        $vat = $make($classes);
        $out = [];
        foreach (array_keys($classes) as $code) {
            $r = [];
            foreach ([21.0, 12.0, 15.0, 10.0, 0.0, 21.004] as $rate) {
                $r['sale' . $rate] = $vat->sale($code, $rate);
            }
            $r['purchase'] = $vat->purchase($code);
            $r['sa'] = $vat->selfAssessmentCode($code);
            $r['a5'] = $vat->forcesA5($code);
            if (array_filter($r, static fn ($v): bool => $v !== null && $v !== false) === []) {
                continue;
            }
            $out[$code] = self::enc($r);
        }
        $out['?unknown'] = self::enc([$vat->known('??'), $vat->name('??'), $vat->sale('??', 21.0), $vat->purchase('??'), $vat->selfAssessmentCode('??'), $vat->forcesA5('??')]);
        return $out;
    }

    /** @return array<string,string> */
    private static function premier(): array
    {
        $rows = [];
        foreach (self::lineSets() as $lines) {
            $k = self::linesKey($lines);
            foreach ([false, true] as $reduced) {
                foreach ([[false, false], [true, false], [false, true], [true, true]] as [$in, $outFlag]) {
                    $code = $k . ($reduced ? 'K' : '') . ($in ? 'I' : '') . ($outFlag ? 'O' : '');
                    $row = ['KOD_DPH' => $code, 'TEXT' => '', 'FA_IN' => $in, 'FA_OUT' => $outFlag, 'IS_REVERS' => false, 'IS_KRACENY' => $reduced, 'SAZBA' => 0, 'JINA_SAZBA' => 0, 'TAB_FA' => '', 'TAB_FANE' => '', 'R19' => 0, 'R19B' => 0, 'R19C' => 0];
                    foreach (array_values($lines) as $i => $line) {
                        $row[['R19', 'R19B', 'R19C'][$i]] = $line;
                    }
                    $rows[] = $row;
                }
            }
        }
        $vat = PremierVat::fromRows($rows);
        $out = [];
        foreach (self::lineSets() as $lines) {
            $k = self::linesKey($lines);
            // Zařazení závisí jen na řádcích a krácení, příznaky směru jen na isPurchase().
            $r = ['sale' => $vat->sale($k), 'purchase' => $vat->purchase($k), 'purchase_reduced' => $vat->purchase($k . 'K')];
            if (array_filter($r, static fn ($v): bool => $v !== null) !== []) {
                $out[$k] = self::enc($r);
            }
            $flags = '';
            foreach (['', 'I', 'O', 'IO', 'K', 'KIO'] as $suffix) {
                $flags .= match ($vat->isPurchase($k . $suffix)) { true => 't', false => 'f', null => '-' };
                $expectedPurchase = str_starts_with($suffix, 'K') ? $r['purchase_reduced'] : $r['purchase'];
                if ($vat->sale($k . $suffix) !== $r['sale'] || $vat->purchase($k . $suffix) !== $expectedPurchase) {
                    $flags .= '!';
                }
            }
            $out['flags|' . $k] = $flags . '|' . ($vat->rateClass($k) ?? '-');
        }
        return $out;
    }

    /** @return array<string,string> */
    private static function premierRates(): array
    {
        $rows = [];
        foreach ([0, 1, 2, 3] as $class) {
            foreach ([[], [1], [2], [43], [44], [4, 44], [40, 47]] as $lines) {
                foreach (['', 'A.5', 'B.3', 'a.5.', 'A.4', 'B.2'] as $kh) {
                    $code = 'S' . $class . '/' . self::linesKey($lines) . '/' . $kh;
                    $row = ['KOD_DPH' => $code, 'TEXT' => $kh === '' ? '' : 'Text', 'FA_IN' => true, 'FA_OUT' => false, 'SAZBA' => $class, 'JINA_SAZBA' => $class === 3 ? 15 : 7, 'TAB_FA' => $kh, 'TAB_FANE' => '', 'R15' => 99, 'R17' => 0, 'R17B' => 0];
                    foreach (array_values($lines) as $i => $line) {
                        $row[['R17', 'R17B'][$i]] = $line;
                    }
                    $rows[] = $row;
                }
            }
        }
        $vat = PremierVat::fromRows($rows);
        $out = [];
        foreach ($rows as $row) {
            $code = $row['KOD_DPH'];
            $out[$code] = self::enc([$vat->known($code), $vat->name($code), $vat->lines($code), $vat->rateClass($code), $vat->otherRate($code), $vat->forcesSummaryKh($code), $vat->isPurchase($code)]);
        }
        $out['?unknown'] = self::enc([$vat->known('??'), $vat->name('??'), $vat->lines('??'), $vat->rateClass('??'), $vat->otherRate('??'), $vat->forcesSummaryKh('??'), $vat->isPurchase('??'), $vat->sale('??'), $vat->purchase('??')]);
        foreach ([PremierVat::RATE_BASE, PremierVat::RATE_REDUCED] as $class) {
            foreach (['2004-05-01', '2007-12-31', '2008-01-01', '2009-12-31', '2010-01-01', '2011-12-31', '2012-01-01', '2012-12-31', '2013-01-01', '2023-12-31', '2024-01-01', '2026-09-22'] as $date) {
                $out['rate|' . $class . '|' . $date] = self::enc(PremierVat::rateFor($class, $date));
            }
        }
        $out['duplicate'] = self::enc(PremierVat::fromRows([
            ['KOD_DPH' => 'D', 'R17' => 40, 'IS_KRACENY' => false, 'FA_IN' => true],
            ['KOD_DPH' => 'D', 'R17' => 1, 'IS_KRACENY' => true, 'FA_IN' => false],
        ])->purchase('D'));
        $out['no_columns'] = self::enc(PremierVat::fromRows([['KOD_DPH' => 'N', 'FA_IN' => true]])->purchase('N'));
        $out['empty'] = self::enc(PremierVat::fromRows([])->known('N'));
        return $out;
    }

    /** @return array<string,string> */
    private static function stereo(): array
    {
        $out = [];
        $sets = array_values(array_filter(self::lineSets(), static fn (array $l): bool => !in_array(0, $l, true)));
        foreach (['z', '0'] as $slot) {
            foreach ($sets as $lines) {
                if ($slot === '0' && count($lines) > 2) {
                    continue;
                }
                foreach ([['P', false], ['P', true], ['U', false]] as [$kind, $reduced]) {
                    $row = self::stereoRow($kind, $reduced, $slot, array_map('strval', $lines));
                    $key = $slot . '|' . $kind . ($reduced ? 'K' : '') . '|' . self::linesKey($lines);
                    $res = self::stereoCall($row, $kind === 'P' ? 'purchase' : 'sale', $slot);
                    if (!str_starts_with($res, 'EX:vat_classification_unsupported')) {
                        $out[$key] = $res;
                    }
                }
            }
        }
        $base = self::stereoRow('P', false, 'z', ['40']);
        $special = [
            'wrong_kind_sale' => [$base, 'sale', 'z'],
            'bad_slot' => [$base, 'purchase', 'q'],
            'moss' => [['Moss' => true] + $base, 'purchase', 'z'],
            'kraceni_null' => [['Kraceni' => null] + $base, 'purchase', 'z'],
            'bad_line' => [['E19RadekZakladZ' => 'AB'] + $base, 'purchase', 'z'],
            'zero_line' => [['E19RadekZakladZ' => '0'] + $base, 'purchase', 'z'],
            'three_digit' => [['E19RadekZakladZ' => '100'] + $base, 'purchase', 'z'],
            'ne_values' => [['E19RadekZakladZ' => 'NE'] + $base, 'purchase', 'z'],
            'spaces' => [['E19RadekZakladZ' => ' 41 '] + $base, 'purchase', 'z'],
            'slot_s' => [self::stereoRow('P', false, 's', ['41']), 'purchase', 's'],
            'slot_t' => [self::stereoRow('U', false, 't', ['2']), 'sale', 't'],
        ];
        $missing = $base;
        unset($missing['E19RadekDanZx']);
        $special['missing_column'] = [$missing, 'purchase', 'z'];
        foreach ($special as $key => [$row, $method, $slot]) {
            $out['special|' . $key] = self::stereoCall($row, $method, $slot);
        }
        try {
            new StereoNxVat([$base, $base]);
            $out['special|duplicate'] = 'ok';
        } catch (StereoNxException $e) {
            $out['special|duplicate'] = 'EX:' . $e->errorCode . ':' . $e->getMessage();
        }
        return $out;
    }

    /**
     * @param list<string> $values
     * @return array<string,mixed>
     */
    private static function stereoRow(string $kind, bool $reduced, string $slot, array $values): array
    {
        $row = ['TypDPH' => 'SYN', 'Plneni' => $kind, 'Kraceni' => $reduced, 'Moss' => false];
        foreach (['Z', 'S', 'T', '0'] as $s) {
            foreach (['Zaklad', 'Dan'] as $k) {
                foreach (['', 'x'] as $x) {
                    $row['E19Radek' . $k . $s . $x] = 'NE';
                }
            }
        }
        $fields = $slot === '0'
            ? ['E19RadekZaklad0', 'E19RadekZaklad0x']
            : ['E19RadekZaklad' . strtoupper($slot), 'E19RadekDan' . strtoupper($slot), 'E19RadekZaklad' . strtoupper($slot) . 'x', 'E19RadekDan' . strtoupper($slot) . 'x'];
        foreach ($values as $i => $v) {
            $row[$fields[$i]] = $v;
        }
        return $row;
    }

    /** @param array<string,mixed> $row */
    private static function stereoCall(array $row, string $method, string $slot): string
    {
        try {
            return self::enc((new StereoNxVat([$row]))->{$method}('SYN', $slot));
        } catch (StereoNxException $e) {
            return 'EX:' . $e->errorCode . ':' . $e->getMessage();
        }
    }
}
