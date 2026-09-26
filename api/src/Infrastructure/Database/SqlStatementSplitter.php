<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

/**
 * Rozdělí SQL skript (migraci) na jednotlivé statementy stejně, jak je spouští
 * `api/bin/migrate.php`. Je to jediná implementace: migrátor i dotah globálních
 * seedů ({@see \MyInvoice\Service\System\GlobalSeedRestorer}) musí vidět migraci
 * rozdělenou shodně, jinak by dotah přehrával něco jiného, než proběhlo.
 *
 * Default delimiter `;` lze přepnout direktivou `DELIMITER xxx` (klient-side, na vlastním
 * řádku), což je nutné pro CREATE PROCEDURE / TRIGGER s `;` uvnitř těla.
 *
 * Respektuje single-quoted stringy a komentáře `-- ...` a `/* ... *\/`.
 */
final class SqlStatementSplitter
{
    /** @return list<string> */
    public static function split(string $sql): array
    {
        $stmts = [];
        $current = '';
        $delim = ';';
        $len = strlen($sql);
        $inSingle = false;
        $inLineComment = false;
        $inBlockComment = false;
        $atLineStart = true;

        for ($i = 0; $i < $len; $i++) {
            if ($atLineStart && !$inSingle && !$inLineComment && !$inBlockComment) {
                $j = $i;
                while ($j < $len && ($sql[$j] === ' ' || $sql[$j] === "\t")) $j++;
                if ($j + 10 <= $len && strcasecmp(substr($sql, $j, 10), 'DELIMITER ') === 0) {
                    $eol = strpos($sql, "\n", $j + 10);
                    if ($eol === false) $eol = $len;
                    $newDelim = trim(substr($sql, $j + 10, $eol - ($j + 10)));
                    if ($newDelim !== '') {
                        if (trim($current) !== '') {
                            $stmts[] = $current;
                            $current = '';
                        }
                        $delim = $newDelim;
                    }
                    $i = $eol;
                    $atLineStart = true;
                    continue;
                }
            }
            $atLineStart = false;

            $ch  = $sql[$i];
            $nxt = ($i + 1 < $len) ? $sql[$i + 1] : '';

            if ($inLineComment) {
                $current .= $ch;
                if ($ch === "\n") { $inLineComment = false; $atLineStart = true; }
                continue;
            }
            if ($inBlockComment) {
                $current .= $ch;
                if ($ch === '*' && $nxt === '/') {
                    $current .= '/';
                    $i++;
                    $inBlockComment = false;
                }
                continue;
            }
            if ($inSingle) {
                $current .= $ch;
                if ($ch === '\\' && $nxt !== '') {
                    $current .= $nxt;
                    $i++;
                    continue;
                }
                if ($ch === "'") $inSingle = false;
                continue;
            }

            if ($ch === '-' && $nxt === '-') {
                $current .= '--';
                $i++;
                $inLineComment = true;
                continue;
            }
            if ($ch === '/' && $nxt === '*') {
                $current .= '/*';
                $i++;
                $inBlockComment = true;
                continue;
            }
            if ($ch === "'") {
                $inSingle = true;
                $current .= $ch;
                continue;
            }
            if ($ch === "\n") {
                $current .= $ch;
                $atLineStart = true;
                continue;
            }

            $dlen = strlen($delim);
            if ($dlen > 0 && substr_compare($sql, $delim, $i, $dlen) === 0) {
                if (trim($current) !== '') $stmts[] = $current;
                $current = '';
                $i += $dlen - 1;
                continue;
            }
            $current .= $ch;
        }

        if (trim($current) !== '') $stmts[] = $current;

        return $stmts;
    }

    /** Statement bez úvodních komentářů a bílých znaků — podle něj se pozná, co dělá. */
    public static function stripLeadingComments(string $stmt): string
    {
        $out = ltrim($stmt);
        while (true) {
            if (str_starts_with($out, '--')) {
                $eol = strpos($out, "\n");
                $out = $eol === false ? '' : ltrim(substr($out, $eol + 1));
                continue;
            }
            if (str_starts_with($out, '/*')) {
                $end = strpos($out, '*/');
                $out = $end === false ? '' : ltrim(substr($out, $end + 2));
                continue;
            }
            return $out;
        }
    }
}
