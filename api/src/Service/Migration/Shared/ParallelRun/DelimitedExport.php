<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared\ParallelRun;

use MyInvoice\Service\Migration\MoneyS3\MoneyReportParser;

/**
 * Tabulková sestava vyexportovaná ze starého účetního programu (CSV / text se středníkem,
 * tabulátorem nebo čárkou) → řádky pojmenované podle hlavičky.
 *
 * Programy pojmenovávají sloupce různě („Zůstatek", „Zbývá uhradit", „Saldo"), proto se
 * sloupec hledá podle seznamu jmen bez diakritiky a velikosti písmen. Hlavička nemusí být
 * na prvním řádku: sestavy mívají nad tabulkou název firmy a období, bere se první řádek,
 * na kterém jsou všechny povinné sloupce. Soubor v CP1250 se převede na UTF-8.
 */
final class DelimitedExport
{
    /** Kolik řádků od začátku souboru se prohledá kvůli hlavičce. */
    private const HEADER_SCAN = 30;

    /**
     * @param array<string,list<string>> $columns pole => přijatelná jména sloupce (bez diakritiky, malými)
     * @param list<string> $required pole, bez kterých sestava nejde přečíst
     * @return list<array<string,string>> řádky pod hlavičkou (jen pole, která soubor má)
     * @throws ParallelRunException hlavička s povinnými sloupci nenalezena
     */
    public static function rows(string $content, array $columns, array $required, string $inputKind): array
    {
        $lines = self::lines($content);
        $delimiter = self::delimiter($lines);
        $header = null;
        $start = 0;
        foreach (array_slice($lines, 0, self::HEADER_SCAN) as $i => $line) {
            $map = self::mapHeader(self::cells($line, $delimiter), $columns);
            if (array_diff($required, array_keys($map)) === []) {
                $header = $map;
                $start = $i + 1;
                break;
            }
        }
        if ($header === null) {
            throw new ParallelRunException('input_header_missing', sprintf(
                'V souboru %s chybí hlavička se sloupci: %s.',
                $inputKind,
                implode(', ', array_map(static fn (string $f): string => $columns[$f][0] ?? $f, $required)),
            ), ['input' => $inputKind, 'required' => $required]);
        }

        $out = [];
        foreach (array_slice($lines, $start) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = self::cells($line, $delimiter);
            $row = [];
            foreach ($header as $field => $index) {
                $row[$field] = trim((string) ($cells[$index] ?? ''));
            }
            $out[] = $row;
        }
        return $out;
    }

    /** Číslo ze sestavy („1 234,56", „-1234.56", „1 234,56-"); prázdné nebo text → null. */
    public static function number(string $cell): ?float
    {
        return MoneyReportParser::number($cell);
    }

    /** Součtový řádek sestavy („Celkem", „Součet", „Total") — do porovnání nepatří. */
    public static function isTotalLabel(string $cell): bool
    {
        return preg_match('/^(celkem|soucet|souhrn|total)\b/', self::fold($cell)) === 1;
    }

    /** Text bez diakritiky, malými písmeny, jen písmena, číslice a mezery. */
    public static function fold(string $text): string
    {
        // Vlastní převod místo iconv TRANSLIT: ten se na Windows a Linuxu chová různě
        // (na Windows z „Č" udělá „?") a hlavička by se podle platformy nenašla.
        $ascii = strtr(mb_strtolower($text), self::DIACRITICS);
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? $ascii;
        return trim($ascii);
    }

    private const DIACRITICS = [
        'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'ë' => 'e', 'í' => 'i',
        'ľ' => 'l', 'ĺ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ř' => 'r', 'ŕ' => 'r',
        'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ý' => 'y', 'ž' => 'z', 'ß' => 'ss',
    ];

    /** @return list<string> */
    private static function lines(string $content): array
    {
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = (string) @iconv('CP1250', 'UTF-8//TRANSLIT', $content);
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        return preg_split('/\R/', $content) ?: [];
    }

    /** @param list<string> $lines */
    private static function delimiter(array $lines): string
    {
        $counts = [';' => 0, "\t" => 0, ',' => 0];
        foreach (array_slice($lines, 0, 50) as $line) {
            foreach ($counts as $d => $_) {
                $counts[$d] += substr_count($line, $d);
            }
        }
        if ($counts[';'] > 0) {
            return ';';
        }
        if ($counts["\t"] > 0) {
            return "\t";
        }
        return ',';
    }

    /** @return list<string> */
    private static function cells(string $line, string $delimiter): array
    {
        return array_map(static fn ($c): string => trim((string) $c), str_getcsv($line, $delimiter, '"', ''));
    }

    /**
     * @param list<string> $cells
     * @param array<string,list<string>> $columns
     * @return array<string,int> pole => index sloupce
     */
    private static function mapHeader(array $cells, array $columns): array
    {
        $folded = array_map([self::class, 'fold'], $cells);
        $map = [];
        foreach ($columns as $field => $names) {
            foreach ($names as $name) {
                $index = array_search($name, $folded, true);
                if ($index !== false && !in_array($index, $map, true)) {
                    $map[$field] = (int) $index;
                    break;
                }
            }
        }
        return $map;
    }
}
