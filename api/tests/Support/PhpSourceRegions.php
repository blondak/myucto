<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

/**
 * Mapování zdrojového kódu na pojmenované oblasti (metody, funkce, konstanty).
 *
 * Vzniklo z poučení fáze F1: guard, jehož allowlist je na úrovni SOUBORU, vyjme
 * ze skenu i všechno ostatní v tomtéž souboru. `CrmAggregationService.php` byl
 * kvůli jedné legitimní výjimce celý mimo kontrolu — a přesně v něm pak seděly
 * dva nehlídané závazkové dotazy. Granularita allowlistu je součástí návrhu
 * guardu, ne detail.
 *
 * Kotvíme na JMÉNO symbolu, ne na číslo řádku: jméno přežije reformátování
 * i vložení kódu nad ním, číslo řádku ne.
 */
final class PhpSourceRegions
{
    /**
     * Pojmenované oblasti v souboru, seřazené podle začátku.
     *
     * @return list<array{name:string, startLine:int, endLine:int}>
     */
    public static function symbols(string $code): array
    {
        $tokens = token_get_all($code);
        $out = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok)) {
                continue;
            }

            if ($tok[0] === T_FUNCTION) {
                $name = self::nameAfter($tokens, $i, $count);
                if ($name === null) {
                    continue; // anonymní funkce / first-class callable
                }
                $region = self::blockAfter($tokens, $i, $count);
                if ($region !== null) {
                    $out[] = ['name' => $name, 'startLine' => $tok[2], 'endLine' => $region];
                }
                continue;
            }

            if ($tok[0] === T_CONST) {
                $name = self::nameAfter($tokens, $i, $count);
                if ($name === null) {
                    continue;
                }
                $end = self::statementEnd($tokens, $i, $count);
                $out[] = ['name' => $name, 'startLine' => $tok[2], 'endLine' => $end];
            }
        }

        usort($out, static fn (array $a, array $b): int => $a['startLine'] <=> $b['startLine']);
        return $out;
    }

    /**
     * Jméno symbolu, ve kterém leží daný řádek (1-indexovaný). Vrací nejtěsnější
     * obalující symbol; `null`, leží-li řádek mimo jakýkoli (např. v hlavičce třídy).
     */
    public static function symbolAtLine(string $code, int $line): ?string
    {
        $best = null;
        $bestSpan = PHP_INT_MAX;
        foreach (self::symbols($code) as $sym) {
            if ($line < $sym['startLine'] || $line > $sym['endLine']) {
                continue;
            }
            $span = $sym['endLine'] - $sym['startLine'];
            if ($span < $bestSpan) {
                $best = $sym['name'];
                $bestSpan = $span;
            }
        }
        return $best;
    }

    /**
     * Kód bez těl uvedených symbolů. Řádky se nemažou, jen vyprazdňují — čísla
     * řádků ve zbytku souboru tak zůstanou platná pro hlášení nálezů.
     *
     * @param list<string> $names
     */
    public static function withoutSymbols(string $code, array $names): string
    {
        if ($names === []) {
            return $code;
        }
        $wanted = array_flip($names);
        $lines = explode("\n", $code);

        foreach (self::symbols($code) as $sym) {
            if (!isset($wanted[$sym['name']])) {
                continue;
            }
            for ($l = $sym['startLine']; $l <= $sym['endLine']; $l++) {
                if (isset($lines[$l - 1])) {
                    $lines[$l - 1] = '';
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Jména symbolů, které v kódu skutečně existují — pro kontrolu, že se
     * allowlist nerozešel s kódem. Zastaralý záznam v allowlistu vyjímá ze skenu
     * neexistující symbol a tváří se přitom, že něco kryje.
     *
     * @param list<string> $names
     * @return list<string> jména, která v kódu NEJSOU
     */
    public static function missingSymbols(string $code, array $names): array
    {
        $present = [];
        foreach (self::symbols($code) as $sym) {
            $present[$sym['name']] = true;
        }
        return array_values(array_filter($names, static fn (string $n): bool => !isset($present[$n])));
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function nameAfter(array $tokens, int $i, int $count): ?string
    {
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($t) && $t[0] === T_STRING) {
                return $t[1];
            }
            return null; // `(` u anonymní funkce, `&` u referenční, apod.
        }
        return null;
    }

    /**
     * Řádek uzavírající `}` bloku, který za pozicí `$i` následuje.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function blockAfter(array $tokens, int $i, int $count): ?int
    {
        $depth = 0;
        $line = null;

        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];

            if (is_array($t)) {
                if ($depth === 0 && $t[0] === T_FUNCTION) {
                    return null; // narazili jsme na další deklaraci → tahle tělo nemá
                }
                // Interpolované řetězce a heredoc nesou `{` uvnitř hodnoty, ne jako token.
                if (in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                }
                $line = $t[2];
                continue;
            }

            if ($t === ';' && $depth === 0) {
                return null; // abstract / interface metoda bez těla
            }
            if ($t === '{') {
                $depth++;
                continue;
            }
            if ($t === '}') {
                $depth--;
                if ($depth === 0) {
                    return self::lineAt($tokens, $j, $line);
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function statementEnd(array $tokens, int $i, int $count): int
    {
        $line = is_array($tokens[$i]) ? $tokens[$i][2] : 1;
        $depth = 0;

        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t)) {
                $line = $t[2] + substr_count($t[1], "\n");
                continue;
            }
            if ($t === '[' || $t === '(') {
                $depth++;
            } elseif ($t === ']' || $t === ')') {
                $depth--;
            } elseif ($t === ';' && $depth === 0) {
                return $line;
            }
        }

        return $line;
    }

    /**
     * Číslo řádku pro token bez vlastní pozice (jednoznakové tokeny ji nemají).
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function lineAt(array $tokens, int $j, ?int $fallback): int
    {
        for ($k = $j; $k >= 0; $k--) {
            if (is_array($tokens[$k])) {
                return $tokens[$k][2] + substr_count($tokens[$k][1], "\n");
            }
        }
        return $fallback ?? 1;
    }
}
