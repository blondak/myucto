<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Accrual;

/**
 * Rozpoznání období plnění (časové rozlišení 381/384) z textu položky dokladu.
 *
 * Jediný zdroj pravdy pro backend: používá ho AI vytěžení přijatých i vydaných dokladů
 * a import ISDOC jako náhradu, když model období nevrátí. Frontendové dvojče je
 * `web/src/utils/accrualPeriod.ts` a obě implementace sdílejí sadu případů
 * `web/src/utils/__tests__/accrualPeriod.cases.json`, takže se chování nerozejde.
 *
 * Záměrně konzervativní: samotné datum („DUZP 28. 9. 2026") období není. Období vzniká
 * z rozsahu (dvě data, dva měsíce, dva roky spojené pomlčkou / „až" / „do"), nebo
 * z výslovného měsíce či roku s klíčovým slovem („za září 2026", „na rok 2027").
 */
final class AccrualPeriodDetector
{
    /** Nejdelší přijaté období v letech; delší rozsah je spíš omyl než předplatné. */
    public const MAX_SPAN_YEARS = 5;

    private const MONTHS = [
        'leden' => 1, 'ledna' => 1, 'lednu' => 1, 'january' => 1, 'jan' => 1,
        'únor' => 2, 'února' => 2, 'únoru' => 2, 'unor' => 2, 'unora' => 2, 'february' => 2, 'feb' => 2,
        'březen' => 3, 'března' => 3, 'březnu' => 3, 'brezen' => 3, 'brezna' => 3, 'march' => 3, 'mar' => 3,
        'duben' => 4, 'dubna' => 4, 'dubnu' => 4, 'april' => 4, 'apr' => 4,
        'květen' => 5, 'května' => 5, 'květnu' => 5, 'kveten' => 5, 'kvetna' => 5, 'may' => 5,
        'červen' => 6, 'června' => 6, 'červnu' => 6, 'cerven' => 6, 'cervna' => 6, 'june' => 6, 'jun' => 6,
        'červenec' => 7, 'července' => 7, 'červenci' => 7, 'cervenec' => 7, 'cervence' => 7, 'july' => 7, 'jul' => 7,
        'srpen' => 8, 'srpna' => 8, 'srpnu' => 8, 'august' => 8, 'aug' => 8,
        'září' => 9, 'zari' => 9, 'september' => 9, 'sept' => 9, 'sep' => 9,
        'říjen' => 10, 'října' => 10, 'říjnu' => 10, 'rijen' => 10, 'rijna' => 10, 'october' => 10, 'oct' => 10,
        'listopad' => 11, 'listopadu' => 11, 'november' => 11, 'nov' => 11,
        'prosinec' => 12, 'prosince' => 12, 'prosinci' => 12, 'december' => 12, 'dec' => 12,
    ];

    private const SEPARATOR = '/^\s*(?:[-–—‒−]+|až|az|do|to|until|till|through|thru)\s*$/u';

    private const MONTH_KEYWORD = '/(?:^|[^\p{L}])(?:za|na|pro|období|obdobi|měsíc|mesic|for|period|month)\s*:?\s*$/u';

    private const YEAR_KEYWORD = '/(?:^|[^\p{L}])(?:rok|roku|year)\s*:?\s*$/u';

    /**
     * @return array{from:string,to:string}|null
     */
    public static function detect(string $text): ?array
    {
        $text = mb_strtolower(str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $text), 'UTF-8');
        if (trim($text) === '') {
            return null;
        }
        $tokens = self::tokens($text);

        for ($i = 0, $n = count($tokens) - 1; $i < $n; $i++) {
            $a = $tokens[$i];
            $b = $tokens[$i + 1];
            $between = substr($text, $a['end'], $b['start'] - $a['end']);
            if (preg_match(self::SEPARATOR, $between) !== 1) {
                continue;
            }
            $period = self::pairPeriod($a, $b);
            if ($period !== null) {
                return $period;
            }
        }

        foreach ($tokens as $t) {
            if ($t['y'] === null || $t['kind'] === 'day') {
                continue;
            }
            $before = substr($text, 0, $t['start']);
            $keyword = $t['kind'] === 'year' ? self::YEAR_KEYWORD : self::MONTH_KEYWORD;
            if (preg_match($keyword, $before) !== 1) {
                continue;
            }
            $period = self::valid(self::startOf($t, $t['y']), self::endOf($t, $t['y']));
            if ($period !== null) {
                return $period;
            }
        }
        return null;
    }

    /**
     * Období z hodnot, které přinesl model nebo uživatel: obě data platná (Y-m-d)
     * a od ≤ do, jinak NULL.
     *
     * @return array{from:string,to:string}|null
     */
    public static function normalizePair(mixed $from, mixed $to): ?array
    {
        $f = self::strictDate($from);
        $t = self::strictDate($to);
        if ($f === null || $t === null || $f > $t) {
            return null;
        }
        return ['from' => $f, 'to' => $t];
    }

    /**
     * Období řádku při importu: hodnota z modelu má přednost, jinak rozpoznání z popisu.
     *
     * @return array{from:string,to:string}|null
     */
    public static function resolveForItem(mixed $from, mixed $to, string $description): ?array
    {
        return self::normalizePair($from, $to) ?? self::detect($description);
    }

    private static function strictDate(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m) !== 1) {
            return null;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? trim($value) : null;
    }

    /**
     * @return list<array{kind:string,start:int,end:int,y:?int,m1:int,d1:int,m2:int,d2:?int}>
     */
    private static function tokens(string $text): array
    {
        $names = array_keys(self::MONTHS);
        usort($names, static fn (string $x, string $y): int => mb_strlen($y) <=> mb_strlen($x));
        $name = implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), $names));

        $re = '/(?<![\d.\/])(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)'
            . '|(?<![\d.\/])(\d{1,2})\.\s*(\d{1,2})\.(?:\s*(\d{4})(?!\d))?'
            . '|(?<![\d.\/])(\d{1,2})\/(\d{1,2})\/(\d{4})(?!\d)'
            . '|(?<![\d.\/])(\d{1,2})\.?\s*(' . $name . ')(?!\p{L})(?:\s*(\d{4})(?!\d))?'
            . '|(?<![\d.\/])(\d{1,2})\s*[\/.]\s*(\d{4})(?!\d)'
            . '|(?<!\p{L})(' . $name . ')(?!\p{L})(?:\s*(\d{4})(?!\d))?'
            . '|(?<![\p{L}\d.\/])((?:19|20)\d{2})(?!\d)/u';

        preg_match_all($re, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

        $tokens = [];
        foreach ($matches as $m) {
            $g = static fn (int $i): ?string => $m[$i][0] ?? null;
            $start = (int) $m[0][1];
            $end = $start + strlen((string) $m[0][0]);
            $y = null;
            if ($g(1) !== null) {
                $y = (int) $g(1);
                $d = (int) $g(3);
                $mo = (int) $g(2);
                $tok = ['kind' => 'day', 'm1' => $mo, 'd1' => $d, 'm2' => $mo, 'd2' => $d];
            } elseif ($g(4) !== null) {
                $y = $g(6) !== null ? (int) $g(6) : null;
                $mo = (int) $g(5);
                $d = (int) $g(4);
                $tok = ['kind' => 'day', 'm1' => $mo, 'd1' => $d, 'm2' => $mo, 'd2' => $d];
            } elseif ($g(7) !== null) {
                $y = (int) $g(9);
                $mo = (int) $g(8);
                $d = (int) $g(7);
                $tok = ['kind' => 'day', 'm1' => $mo, 'd1' => $d, 'm2' => $mo, 'd2' => $d];
            } elseif ($g(10) !== null) {
                $y = $g(12) !== null ? (int) $g(12) : null;
                $mo = self::MONTHS[(string) $g(11)];
                $d = (int) $g(10);
                $tok = ['kind' => 'day', 'm1' => $mo, 'd1' => $d, 'm2' => $mo, 'd2' => $d];
            } elseif ($g(13) !== null) {
                $y = (int) $g(14);
                $mo = (int) $g(13);
                $tok = ['kind' => 'month', 'm1' => $mo, 'd1' => 1, 'm2' => $mo, 'd2' => null];
            } elseif ($g(15) !== null) {
                $y = $g(16) !== null ? (int) $g(16) : null;
                $mo = self::MONTHS[(string) $g(15)];
                $tok = ['kind' => 'month', 'm1' => $mo, 'd1' => 1, 'm2' => $mo, 'd2' => null];
            } else {
                $y = (int) $g(17);
                $tok = ['kind' => 'year', 'm1' => 1, 'd1' => 1, 'm2' => 12, 'd2' => 31];
            }
            $tokens[] = $tok + ['start' => $start, 'end' => $end, 'y' => $y];
        }
        return $tokens;
    }

    /**
     * @param array{kind:string,start:int,end:int,y:?int,m1:int,d1:int,m2:int,d2:?int} $a
     * @param array{kind:string,start:int,end:int,y:?int,m1:int,d1:int,m2:int,d2:?int} $b
     * @return array{from:string,to:string}|null
     */
    private static function pairPeriod(array $a, array $b): ?array
    {
        $ya = $a['y'];
        $yb = $b['y'];
        if ($ya === null && $yb === null) {
            return null;
        }
        if ($ya === null) {
            $ya = $yb;
            $end = self::endOf($b, $yb);
            $start = self::startOf($a, $ya);
            if ($start !== null && $end !== null && $start > $end) {
                $ya = $yb - 1;
            }
        } elseif ($yb === null) {
            $yb = $ya;
            $start = self::startOf($a, $ya);
            $end = self::endOf($b, $yb);
            if ($start !== null && $end !== null && $end < $start) {
                $yb = $ya + 1;
            }
        }
        return self::valid(self::startOf($a, $ya), self::endOf($b, $yb));
    }

    /** @param array{m1:int,d1:int} $t */
    private static function startOf(array $t, int $y): ?string
    {
        return self::ymd($y, $t['m1'], $t['d1']);
    }

    /** @param array{m2:int,d2:?int} $t */
    private static function endOf(array $t, int $y): ?string
    {
        if ($t['m2'] < 1 || $t['m2'] > 12) {
            return null;
        }
        $d = $t['d2'] ?? (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $t['m2'])))->format('t');
        return self::ymd($y, $t['m2'], $d);
    }

    private static function ymd(int $y, int $m, int $d): ?string
    {
        if ($y < 1900 || $y > 2100 || !checkdate($m, $d, $y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /** @return array{from:string,to:string}|null */
    private static function valid(?string $from, ?string $to): ?array
    {
        if ($from === null || $to === null || $from > $to) {
            return null;
        }
        $limit = (new \DateTimeImmutable($from))->modify('+' . self::MAX_SPAN_YEARS . ' years')->format('Y-m-d');
        if ($to > $limit) {
            return null;
        }
        return ['from' => $from, 'to' => $to];
    }
}
