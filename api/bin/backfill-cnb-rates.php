<?php

declare(strict_types=1);

/**
 * Backfill historických denních kurzů ČNB do `exchange_rates`.
 *
 * Proč: tabulka se dosud plnila jen jako ad-hoc cache při prvním dotazu na konkrétní den
 * ({@see \MyInvoice\Service\Fx\CnbExchangeRateClient}), takže historie byla děravá. Účtování
 * cizoměnového dokladu pak spadlo na `last_known` fallback (kurz z jiného dne) a kurz zadaný
 * na faktuře nebylo proti čemu validovat. Načtením souvislé historie odpadnou živá HTTP volání
 * na ČNB při dávkovém účtování i kontrola kurzu proti prázdné tabulce.
 *
 * Zdroj: roční textový export ČNB (jeden request na rok místo jednoho na den):
 *   https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/rok.txt?rok=YYYY
 * Formát: hlavička `Datum|1 AUD|...|100 JPY|...`, řádky `02.01.2024|15,278|...`.
 * Desetinná čárka, hodnota platí pro NÁSOBITELE z hlavičky (100 JPY) — do DB se ukládá kurz
 * na 1 jednotku (JPY 13,108/100 = 0,131080), shodně s CnbExchangeRateClient.
 *
 * ČNB vyhlašuje kurz jen v pracovní dny; víkendy/svátky se nedoplňují (konzumenti mají
 * last_known fallback a §4 ZoÚ stejně velí kurz vyhlášený k rozhodnému dni).
 *
 * Idempotentní: INSERT ... ON DUPLICATE KEY UPDATE, druhý běh nic neduplikuje.
 *
 * Použití:
 *   php api/bin/backfill-cnb-rates.php --from=2024 --to=2026           # dry-run
 *   php api/bin/backfill-cnb-rates.php --from=2024 --to=2026 --apply   # zápis
 *   php api/bin/backfill-cnb-rates.php --from=2024 --to=2026 --apply --currency=EUR
 */

require __DIR__ . '/../vendor/autoload.php';

function argValue(array $argv, string $key): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$key}=")) {
            return substr($arg, strlen($key) + 3);
        }
    }
    return null;
}

$dryRun   = !in_array('--apply', $argv, true);
$fromYear = (int) (argValue($argv, 'from') ?? date('Y'));
$toYear   = (int) (argValue($argv, 'to') ?? date('Y'));
$only     = argValue($argv, 'currency');
$only     = $only !== null ? strtoupper($only) : null;

if ($fromYear < 1991 || $toYear < $fromYear || $toYear > (int) date('Y')) {
    fwrite(STDERR, "Neplatný rozsah let (ČNB vyhlašuje kurzy od 1991).\n");
    exit(2);
}

$pdo = \MyInvoice\Bootstrap::buildApp()->getContainer()
    ->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();

$stmt = $pdo->prepare(
    'INSERT INTO exchange_rates (rate_date, currency_code, rate)
     VALUES (:d, :c, :r)
     ON DUPLICATE KEY UPDATE rate = VALUES(rate), fetched_at = CURRENT_TIMESTAMP'
);

$prefix = $dryRun ? '[DRY-RUN] ' : '';
echo "{$prefix}Backfill kurzů ČNB — roky {$fromYear}–{$toYear}"
    . ($only !== null ? ", jen {$only}" : '') . ".\n";

$totalRows = 0;
$totalDays = 0;

for ($year = $fromYear; $year <= $toYear; $year++) {
    $url = 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/'
        . 'kurzy-devizoveho-trhu/rok.txt?rok=' . $year;

    $body = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 30, 'header' => "User-Agent: MyUcto/1.0\r\n"],
    ]));
    if ($body === false || trim($body) === '') {
        fwrite(STDERR, "  {$year}: stažení selhalo, přeskakuji.\n");
        continue;
    }

    $lines = preg_split('/\R/', trim($body)) ?: [];
    $head  = array_shift($lines);
    if (!is_string($head) || !str_contains($head, '|')) {
        fwrite(STDERR, "  {$year}: neočekávaný formát hlavičky, přeskakuji.\n");
        continue;
    }

    // Hlavička: "Datum|1 AUD|...|100 JPY" → kód měny + násobitel, na který kurz platí.
    $cols = array_slice(explode('|', $head), 1);
    $meta = [];
    foreach ($cols as $i => $col) {
        if (preg_match('/^\s*(\d+)\s+([A-Z]{3})\s*$/', $col, $m) === 1) {
            $meta[$i] = ['amount' => (int) $m[1], 'code' => $m[2]];
        }
    }
    if ($meta === []) {
        fwrite(STDERR, "  {$year}: v hlavičce nenalezena žádná měna, přeskakuji.\n");
        continue;
    }

    $yearRows = 0;
    $yearDays = 0;
    if (!$dryRun) {
        $pdo->beginTransaction();
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = explode('|', $line);
        $date  = \DateTimeImmutable::createFromFormat('!d.m.Y', trim($parts[0]));
        if ($date === false) {
            continue;
        }
        $yearDays++;
        foreach ($meta as $i => $cur) {
            if ($only !== null && $cur['code'] !== $only) {
                continue;
            }
            $raw = $parts[$i + 1] ?? '';
            $val = (float) str_replace([' ', ','], ['', '.'], trim($raw));
            if ($val <= 0 || $cur['amount'] <= 0) {
                continue; // měna v daném dni nevyhlášena (prázdná buňka)
            }
            if (!$dryRun) {
                $stmt->execute([
                    ':d' => $date->format('Y-m-d'),
                    ':c' => $cur['code'],
                    ':r' => round($val / $cur['amount'], 6),
                ]);
            }
            $yearRows++;
        }
    }
    if (!$dryRun) {
        $pdo->commit();
    }

    printf("  %d: %3d dnů, %5d kurzů%s\n", $year, $yearDays, $yearRows, $dryRun ? ' (neuloženo)' : '');
    $totalRows += $yearRows;
    $totalDays += $yearDays;
}

echo "───────────────────────────────────────────────\n";
printf("  celkem: %d dnů, %d kurzů\n", $totalDays, $totalRows);
if ($dryRun) {
    echo "\n(dry-run — nic nebylo zapsáno; pro ostrý běh přidej --apply)\n";
}
