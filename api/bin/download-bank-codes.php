<?php

declare(strict_types=1);

/**
 * MyÚčto.cz — download-bank-codes
 *
 * Aktualizuje číselník kódů platebního styku ČNB v
 * `api/resources/ciselniky/kody_bank_CR.csv` ({@see CzechBankCodeRegistry}).
 * Proti němu se kontroluje kód banky v platebním spojení oznámení NEMPRI
 * (C_KODBANKY, chyba DIS 06).
 *
 * Registr se mění zřídka (vznik nebo zánik banky). Skript se pouští ručně,
 * výsledek se zkontroluje přes `git diff` a commitne.
 *
 * Použití:
 *   php api/bin/download-bank-codes.php              # stáhne, ověří a přepíše
 *   php api/bin/download-bank-codes.php --dry-run    # jen vypíše rozdíl
 *   php api/bin/download-bank-codes.php --url=…      # jiný zdroj (test/mirror)
 *
 * Exit kódy: 0 = OK (i „beze změny"), 1 = chyba stažení nebo formátu.
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Service\Bank\CzechBankCodeRegistry;

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$url = CzechBankCodeRegistry::SOURCE_URL;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $url = substr($arg, 6);
    }
}

try {
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'timeout' => 60,
        'header' => "User-Agent: Mozilla/5.0 (MyUcto download-bank-codes)\r\nAccept: text/csv,*/*\r\n",
        'ignore_errors' => true,
    ]]);
    $content = @file_get_contents($url, false, $context);
    if ($content === false || $content === '') {
        throw new RuntimeException("stažení selhalo: {$url}");
    }
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $match) === 1 && $match[1] !== '200') {
            throw new RuntimeException("zdroj vrátil HTTP {$match[1]}");
        }
    }
    $fresh = CzechBankCodeRegistry::parse($content);
    $current = is_file(CzechBankCodeRegistry::RESOURCE)
        ? CzechBankCodeRegistry::parse((string) file_get_contents(CzechBankCodeRegistry::RESOURCE))
        : [];
} catch (Throwable $exception) {
    fwrite(STDERR, 'Chyba: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$added = array_diff_key($fresh, $current);
$removed = array_diff_key($current, $fresh);
foreach ($added as $code => $row) {
    echo "+ {$code} {$row['name']}" . PHP_EOL;
}
foreach ($removed as $code => $row) {
    echo "- {$code} {$row['name']}" . PHP_EOL;
}
if ($added === [] && $removed === []) {
    echo 'Kódy bank beze změny (' . count($fresh) . ').' . PHP_EOL;
}
if (!$dryRun) {
    file_put_contents(CzechBankCodeRegistry::RESOURCE, $content);
    echo 'Zapsáno: ' . CzechBankCodeRegistry::RESOURCE . PHP_EOL;
}
exit(0);
