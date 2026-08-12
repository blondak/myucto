<?php

declare(strict_types=1);

/**
 * Cron — interní doklad „zúčtování DPH" na konci zdaňovacího období (migrace 1323/1324).
 *
 * ⚠️ ZÁCHRANNÁ SÍŤ, NE PRIMÁRNÍ SPOUŠTĚČ (migrace 1332).
 * Autoritativní okamžik, kdy je daň za období známá, je PODÁNÍ PŘIZNÁNÍ — tehdy se
 * zúčtování zaúčtuje/přepočítá samo ({@see MyInvoice\Service\Accounting\Vat\VatClearingTrigger},
 * volané z TaxSubmissionArchiver::markSubmitted()). Kalendářní běh nemůže vědět, že do
 * období ještě přibude opožděná faktura nebo oprava; tenhle cron proto jen dojíždí
 * období, za která se přiznání v aplikaci nepodalo. Rozjetá období hlásí kontrola
 * uzávěrky `vat_clearing_stale` a agenda DPH.
 *
 * Použití:
 *   php api/bin/cron-vat-clearing.php
 *   php api/bin/cron-vat-clearing.php --dry-run           (jen vypíše, co by zaúčtoval)
 *   php api/bin/cron-vat-clearing.php --supplier=12       (jen jeden dodavatel)
 *   php api/bin/cron-vat-clearing.php --period=2026-06    (dohnat konkrétní období)
 *   php api/bin/cron-vat-clearing.php --force             (i pro dosud neuzavřené období)
 *
 * Spouští se 1. dne v měsíci a řeší období PŘEDCHOZÍ. Měsíčnímu plátci vyjde minulý
 * měsíc; čtvrtletnímu čtvrtletí, do kterého minulý měsíc patří — a to se zaúčtuje až
 * poté, co čtvrtletí skončí (jinak by se doklad tři měsíce po sobě přepisoval neúplnými
 * čísly). Přeskočené měsíce jde dohnat přes --period, vynutit přes --force.
 *
 * Datum účetního případu je poslední den období, takže zápis padne do správného období
 * i při zpožděném běhu. Zaúčtování je idempotentní (source_type 'vat_clearing' +
 * deterministické source_id) — opakovaný běh zápis přepočítá, nikdy nezdvojí.
 *
 * Chyba u jednoho dodavatele (uzavřené období, zamčené datum, chybějící analytika)
 * běh neshodí — skončí v reportu a je vidět v Systém → Plánované úlohy.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Accounting\Vat\VatClearingService;
use MyInvoice\Service\Cron\CronRun;

$dryRun = false;
$force = false;
$onlySupplier = null;
$period = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $dryRun = true; continue; }
    if ($arg === '--force') { $force = true; continue; }
    if (str_starts_with($arg, '--supplier=')) { $onlySupplier = (int) substr($arg, 11); continue; }
    if (str_starts_with($arg, '--period=')) {
        $raw = substr($arg, 9);
        if (preg_match('/^(\d{4})-(\d{2})$/', $raw, $m) !== 1) {
            fwrite(STDERR, "Neplatné --period (očekává se RRRR-MM): $raw\n");
            exit(1);
        }
        $period = [(int) $m[1], (int) $m[2]];
        continue;
    }
    fwrite(STDERR, "Unknown arg: $arg\n");
    exit(1);
}

$container = Bootstrap::buildContainer();

/** @var \MyInvoice\Infrastructure\Database\Connection $conn */
$conn = $container->get(\MyInvoice\Infrastructure\Database\Connection::class);
$pdo = $conn->pdo();

/** @var VatClearingService $service */
$service = $container->get(VatClearingService::class);

$run = CronRun::start($pdo, 'cron-vat-clearing');
$startedAt = microtime(true);

$today = new DateTimeImmutable('today');
[$year, $month] = $period ?? VatClearingService::previousPeriod($today);

$supplierIds = $service->candidateSupplierIds();
if ($onlySupplier !== null) {
    $supplierIds = in_array($onlySupplier, $supplierIds, true) ? [$onlySupplier] : [];
}

$report = [
    'dry_run'    => $dryRun,
    'force'      => $force,
    'year'       => $year,
    'month'      => $month,
    'suppliers'  => count($supplierIds),
    'posted'     => 0,
    'skipped'    => 0,
    'errors'     => 0,
    'items'      => [],
];

printf(
    "[%s] cron-vat-clearing%s — období obsahující %02d/%04d, %d plátce/plátců v podvojném účetnictví\n",
    date('Y-m-d H:i:s'),
    $dryRun ? ' --dry-run' : '',
    $month,
    $year,
    count($supplierIds),
);

echo "  (záchranná síť — primární spouštěč je PODÁNÍ přiznání k DPH; cron jen dojíždí\n"
    . "   období, za která se přiznání v aplikaci nepodalo)\n";

$meta = [
    'user_agent' => 'cron-vat-clearing/1.0',
    'trigger'    => VatClearingService::TRIGGER_CRON,
];

foreach ($supplierIds as $sid) {
    $periodType = $service->vatPeriodFor($sid);

    // Čtvrtletní plátce: doklad se dělá až po konci čtvrtletí. Bez tohohle by ho běh
    // v únoru a v březnu založil dvakrát s neúplnými čísly (zápis by se sice přepsal,
    // ale mezitím by v deníku viselo nesprávné saldo vůči FÚ).
    if (!$force && !VatClearingService::isPeriodClosed($year, $month, $periodType, $today)) {
        $report['skipped']++;
        $report['items'][] = [
            'supplier_id' => $sid,
            'status'      => 'period_not_finished',
            'period'      => VatClearingService::periodLabel($year, $month, $periodType),
        ];
        printf("  = supplier #%d %s — období ještě neskončilo\n", $sid, VatClearingService::periodLabel($year, $month, $periodType));
        continue;
    }

    try {
        $r = $service->postForPeriod($sid, $year, $month, $meta, $dryRun);
    } catch (\Throwable $e) {
        // Selhání jednoho dodavatele nesmí zastavit ostatní — DPH je napříč firmami
        // nezávislé a uzavřené období u jedné firmy není důvod nezaúčtovat zbytek.
        $report['errors']++;
        $report['items'][] = ['supplier_id' => $sid, 'status' => 'error', 'message' => $e->getMessage()];
        fprintf(STDERR, "  ✗ supplier #%d — %s\n", $sid, $e->getMessage());
        continue;
    }

    $label = sprintf(
        '%s: výstup %s, vstup %s → 343.900 %s',
        $r['period_label'],
        number_format($r['output_vat'], 2, ',', ' '),
        number_format($r['input_vat'], 2, ',', ' '),
        number_format($r['settlement'], 2, ',', ' '),
    );

    switch ($r['status']) {
        case VatClearingService::STATUS_POSTED:
            $report['posted']++;
            printf("  ✓ supplier #%d %s → zápis #%d\n", $sid, $label, (int) $r['entry_id']);
            break;
        case VatClearingService::STATUS_DRY_RUN:
            printf("  [DRY] supplier #%d %s → zaúčtovalo by se\n", $sid, $label);
            break;
        case VatClearingService::STATUS_DELETED_ZERO:
            $report['posted']++;
            printf("  ✓ supplier #%d %s — období vynulováno, zúčtovací doklad smazán\n", $sid, $label);
            break;
        default:
            $report['skipped']++;
            printf("  = supplier #%d %s — %s\n", $sid, $label, (string) $r['status']);
    }

    $report['items'][] = [
        'supplier_id' => $sid,
        'status'      => (string) $r['status'],
        'period'      => $r['period_label'],
        'output_vat'  => $r['output_vat'],
        'input_vat'   => $r['input_vat'],
        'settlement'  => $r['settlement'],
        'entry_id'    => $r['entry_id'],
    ];
}

$ms = (int) ((microtime(true) - $startedAt) * 1000);
echo "  done ({$ms} ms): posted={$report['posted']}, skipped={$report['skipped']}, errors={$report['errors']}"
    . ($dryRun ? ' (DRY RUN, nic se nezaúčtovalo)' : '') . "\n";

$pdo->prepare("INSERT INTO activity_log (action, payload) VALUES ('cron.vat_clearing', ?)")
    ->execute([json_encode($report, JSON_UNESCAPED_UNICODE)]);

$run->finish($report['errors'] > 0 ? 'error' : 'ok', $report);
