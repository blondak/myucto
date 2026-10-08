<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Pension;

/**
 * Den dosažení důchodového věku podle § 32 zákona č. 155/1995 Sb. a jeho
 * přílohy č. 1 — jen jako NABÍDKA pro účetní, nikdy jako zapsaný údaj.
 *
 * Výpočet se nabídne jen tam, kde z data narození a pohlaví plyne jediný
 * výsledek:
 *
 * - narození 1936–1973: příloha č. 1. U mužů jednoznačně, u žen jen v ročníku,
 *   kde mají všechny sloupce počtu vychovaných dětí tutéž hodnotu (počet dětí
 *   evidence nevede a hádat ho nelze),
 * - narození 1974–1988: 65 let a 8 měsíců plus rozdíl let mezi rokem narození
 *   a rokem 1973 v měsících (§ 32 odst. 3), bez ohledu na pohlaví,
 * - narození po roce 1988: 67 let.
 *
 * Snížený důchodový věk (horníci, zvláštní předpisy) ani narození před rokem
 * 1936 výpočet nezná — tam a u žen s rozdílnými sloupci rozhoduje ruční zápis.
 *
 * Den: „věk dosažený v posledním přičteném kalendářním měsíci v den, který se
 * číslem shoduje se dnem narození pojištěnce; neobsahuje-li takto určený měsíc
 * takový den, … v posledním dni posledního přičteného kalendářního měsíce"
 * (§ 32 odst. 2 věta druhá). Pravidlo mluví o přičtených MĚSÍCÍCH; věk v celých
 * letech u narozených 29. února zákon neřeší, a proto se pro ně nenabízí.
 *
 * Znění ověřeno proti textu zákona (zakonyprolidi.cz, 8. 10. 2026).
 */
final class PayrollPensionAgeTable
{
    public const SOURCE = '§ 32 a příloha č. 1 zákona č. 155/1995 Sb.';

    /**
     * Příloha č. 1: rok narození => důchodový věk v měsících
     * [muži, ženy s 0, 1, 2, 3 a 4, 5 a více vychovanými dětmi].
     *
     * @var array<int,array{int,int,int,int,int,int}>
     */
    private const ANNEX_1 = [
        1936 => [722, 684, 672, 660, 648, 636],
        1937 => [724, 684, 672, 660, 648, 636],
        1938 => [726, 684, 672, 660, 648, 636],
        1939 => [728, 688, 672, 660, 648, 636],
        1940 => [730, 692, 676, 660, 648, 636],
        1941 => [732, 696, 680, 664, 648, 636],
        1942 => [734, 700, 684, 668, 652, 636],
        1943 => [736, 704, 688, 672, 656, 640],
        1944 => [738, 708, 692, 676, 660, 644],
        1945 => [740, 712, 696, 680, 664, 648],
        1946 => [742, 716, 700, 684, 668, 652],
        1947 => [744, 720, 704, 688, 672, 656],
        1948 => [746, 724, 708, 692, 676, 660],
        1949 => [748, 728, 712, 696, 680, 664],
        1950 => [750, 732, 716, 700, 684, 668],
        1951 => [752, 736, 720, 704, 688, 672],
        1952 => [754, 740, 724, 708, 692, 676],
        1953 => [756, 744, 728, 712, 696, 680],
        1954 => [758, 748, 732, 716, 700, 684],
        1955 => [760, 752, 736, 720, 704, 688],
        1956 => [762, 758, 740, 724, 708, 692],
        1957 => [764, 764, 746, 728, 712, 696],
        1958 => [766, 766, 752, 734, 716, 700],
        1959 => [768, 768, 758, 740, 722, 704],
        1960 => [770, 770, 764, 746, 728, 710],
        1961 => [772, 772, 770, 752, 734, 716],
        1962 => [774, 774, 774, 758, 740, 722],
        1963 => [776, 776, 776, 764, 746, 728],
        1964 => [778, 778, 778, 770, 752, 734],
        1965 => [780, 780, 780, 776, 758, 740],
        1966 => [781, 781, 781, 781, 764, 746],
        1967 => [782, 782, 782, 782, 770, 752],
        1968 => [783, 783, 783, 783, 776, 758],
        1969 => [784, 784, 784, 784, 782, 764],
        1970 => [785, 785, 785, 785, 785, 770],
        1971 => [786, 786, 786, 786, 786, 776],
        1972 => [787, 787, 787, 787, 787, 782],
        1973 => [788, 788, 788, 788, 788, 788],
    ];

    /**
     * Nabízený den dosažení důchodového věku, nebo `null`, když výpočet
     * z data narození a pohlaví jednoznačný není.
     *
     * @param ?string $sex `male`, `female`, `unspecified` nebo `null`
     */
    public static function suggest(?string $birthDate, ?string $sex): ?string
    {
        if ($birthDate === null
            || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $birthDate, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            return null;
        }
        $months = self::months((int) $parts[1], $sex);
        if ($months === null) {
            return null;
        }
        $day = (int) $parts[3];
        if ($months % 12 === 0 && (int) $parts[2] === 2 && $day === 29) {
            return null;
        }
        $total = (int) $parts[1] * 12 + ((int) $parts[2] - 1) + $months;
        $year = intdiv($total, 12);
        $month = $total % 12 + 1;
        $lastDay = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');

        return sprintf('%04d-%02d-%02d', $year, $month, min($day, $lastDay));
    }

    /** Důchodový věk v měsících, je-li pro ročník a pohlaví jediný. */
    private static function months(int $birthYear, ?string $sex): ?int
    {
        if ($birthYear > 1988) {
            return 67 * 12;
        }
        if ($birthYear >= 1974) {
            return 65 * 12 + 8 + ($birthYear - 1973);
        }
        $row = self::ANNEX_1[$birthYear] ?? null;
        if ($row === null) {
            return null;
        }
        $women = array_slice($row, 1);
        $womenUnique = count(array_unique($women)) === 1;

        return match ($sex) {
            'male' => $row[0],
            'female' => $womenUnique ? $women[0] : null,
            default => $womenUnique && $women[0] === $row[0] ? $row[0] : null,
        };
    }
}
