<?php

declare(strict_types=1);

/**
 * MyÚčto.cz — download-okec
 *
 * Aktualizuje snapshot číselníku ČINNOSTI (OKEC) z rozhraní číselníků
 * Daňového portálu do `api/resources/ciselniky/okec.txt`. Proti tomuhle
 * souboru se kanonizuje CZ-NACE (`c_okec` v DPHDP3) a jede našeptávač
 * v Nastavení — viz `EpoOkecCodebook`.
 *
 * Číselník se mění zřídka (poslední velká změna: přechod na NACE rev. 2.1
 * k 1. 1. 2026, kdy všechny starší kódy dostaly `d_ukopl` 2025-12-31).
 * Zastaralý snapshot nic neblokuje — kód mimo něj se uloží i odešle, jen
 * s upozorněním — takže tenhle skript stačí pustit ručně jednou za čas
 * (typicky když FÚ ohlásí novou revizi) a výsledek commitnout.
 *
 * Použití:
 *   php api/bin/download-okec.php              # stáhne, ověří a přepíše snapshot
 *   php api/bin/download-okec.php --dry-run    # jen porovná a vypíše rozdíl
 *   php api/bin/download-okec.php --url=…      # jiný zdroj (test/mirror)
 *
 * Exit kódy: 0 = OK (i „beze změny"), 1 = chyba stažení/validace.
 */

require __DIR__ . '/../vendor/autoload.php';

if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

use MyInvoice\Service\Report\EpoOkecCodebook;

const DEFAULT_URL = 'https://adisspr.mfcr.cz/mepo/pub/api/ciselniky/soubor/okec';
const HEADER_LINE = 'c_nace|d_pocpl|d_ukopl|naz_nace|';
/** Sanity floor — v roce 2026 má číselník ~1950 řádků; cokoliv pod tím je useknutý přenos. */
const MIN_ROWS = 500;

$args   = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$url    = DEFAULT_URL;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $url = substr($arg, 6);
    }
}

$target = __DIR__ . '/../resources/ciselniky/okec.txt';

/** Stáhne gzip a rozbalí; vrací raw obsah tak, jak ho portál publikuje (vč. CRLF). */
function fetch(string $url): string
{
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'timeout' => 60,
        'header'  => "User-Agent: MyInvoice download-okec\r\nAccept: */*\r\n",
        // Rozbalení si řešíme sami — tělo JE gzip soubor, ne Content-Encoding.
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        throw new RuntimeException("stažení selhalo: {$url}");
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    if ($status !== 0 && $status !== 200) {
        throw new RuntimeException("zdroj vrátil HTTP {$status}");
    }
    // Gzip magic 1F 8B — portál publikuje .gz, ale ať skript přežije i plain text.
    if (str_starts_with($raw, "\x1f\x8b")) {
        $decoded = @gzdecode($raw);
        if ($decoded === false) {
            throw new RuntimeException('obsah se tváří jako gzip, ale nejde rozbalit');
        }
        return $decoded;
    }
    return $raw;
}

/**
 * Ověří, že jde opravdu o číselník ČINNOSTI — jinak bychom si funkční snapshot
 * přepsali chybovou stránkou portálu.
 *
 * @return list<array{code: string, from: string, to: string, name: string}>
 */
function parseAndValidate(string $content): array
{
    $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
    $head  = trim((string) ($lines[0] ?? ''));
    if ($head !== HEADER_LINE) {
        throw new RuntimeException('nečekaná hlavička souboru: ' . substr($head, 0, 60));
    }
    $rows = [];
    foreach (array_slice($lines, 1) as $i => $line) {
        if (trim($line) === '') {
            continue;
        }
        $cols = explode('|', $line);
        if (count($cols) < 4 || !ctype_digit($cols[0]) || trim($cols[3]) === '') {
            throw new RuntimeException('neplatný řádek #' . ($i + 2) . ': ' . substr($line, 0, 60));
        }
        $rows[] = ['code' => $cols[0], 'from' => trim($cols[1]), 'to' => trim($cols[2]), 'name' => trim($cols[3])];
    }
    if (count($rows) < MIN_ROWS) {
        throw new RuntimeException('podezřele málo řádků (' . count($rows) . ' < ' . MIN_ROWS . ')');
    }
    return $rows;
}

/** @param list<array{code: string, from: string, to: string, name: string}> $rows */
function activeCodes(array $rows, string $today): array
{
    $out = [];
    foreach ($rows as $r) {
        if (($r['to'] === '' || $r['to'] >= $today) && ($r['from'] === '' || $r['from'] <= $today)) {
            $out[$r['code']] = $r['name'];
        }
    }
    return $out;
}

try {
    echo "[download-okec] zdroj: {$url}\n";
    $content = fetch($url);
    $rows    = parseAndValidate($content);
    $today   = date('Y-m-d');
    $active  = activeCodes($rows, $today);
    printf("[download-okec] staženo: %d řádků, z toho %d platných k %s\n", count($rows), count($active), $today);

    // Porovnáváme s normalizovanými konci řádků: portál publikuje LF, ale na
    // Windows checkoutu má soubor v pracovním stromě CRLF (core.autocrlf),
    // takže syrové porovnání bajtů by hlásilo změnu při každém spuštění.
    $oldContent = is_file($target) ? (string) file_get_contents($target) : '';
    if (str_replace("\r\n", "\n", $oldContent) === str_replace("\r\n", "\n", $content)) {
        echo "[download-okec] snapshot je aktuální — beze změny.\n";
        exit(0);
    }

    if ($oldContent !== '') {
        $oldActive = activeCodes(parseAndValidate($oldContent), $today);
        $added     = array_diff_key($active, $oldActive);
        $removed   = array_diff_key($oldActive, $active);
        printf("[download-okec] změna platných kódů: +%d / -%d\n", count($added), count($removed));
        foreach (array_slice($added, 0, 15, true) as $code => $name) {
            echo "   + {$code}  " . EpoOkecCodebook::humanizeName($name) . "\n";
        }
        foreach (array_slice($removed, 0, 15, true) as $code => $name) {
            echo "   - {$code}  " . EpoOkecCodebook::humanizeName($name) . "\n";
        }
        if (count($added) > 15 || count($removed) > 15) {
            echo "   … (zkráceno, celý rozdíl uvidíš v `git diff`)\n";
        }
    }

    if ($dryRun) {
        echo "[download-okec] DRY-RUN — soubor se nezapisuje.\n";
        exit(0);
    }

    // Zápis přes dočasný soubor, ať se při pádu neztratí funkční snapshot.
    $tmp = $target . '.tmp';
    if (@file_put_contents($tmp, $content) === false || !@rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException("zápis do {$target} selhal");
    }
    echo "[download-okec] zapsáno: {$target}\n";
    echo "[download-okec] hotovo — projdi `git diff` a změnu commitni.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[download-okec] CHYBA: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "[download-okec] snapshot ponechán beze změny.\n");
    exit(1);
}
