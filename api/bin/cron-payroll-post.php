<?php

declare(strict_types=1);

/**
 * Cron — automatické měsíční zaúčtování mezd (migrace 1175).
 *
 * Použití:
 *   php api/bin/cron-payroll-post.php
 *   php api/bin/cron-payroll-post.php --dry-run              (jen vypíše, co by zaúčtoval)
 *   php api/bin/cron-payroll-post.php --supplier=12          (jen jeden dodavatel)
 *   php api/bin/cron-payroll-post.php --period=2026-06       (dohnat konkrétní měsíc)
 *
 * Spouští se 1. dne v měsíci a účtuje měsíc PŘEDCHOZÍ — k tomu dni jsou už všechna
 * jeho data známá. Datum účetního případu je poslední den účtovaného měsíce, takže
 * zápis padne do správného období i tehdy, když cron proběhne se zpožděním.
 *
 * Pro každého dodavatele v podvojném účetnictví projde aktivní zaměstnance
 * s `auto_post = 1` a vyplněnou `monthly_gross` a zaúčtuje jim mzdovou rekapitulaci.
 * Rozhodování, co se přeskočí (už zaevidováno / kolize zápisu za měsíc), drží
 * {@see \MyInvoice\Service\Accounting\Payroll\PayrollAutoPostService} — tenhle skript
 * je jen wrapper s výpisem a heartbeatem, ať jde logika otestovat bez procesu.
 *
 * Chyba u jednoho zaměstnance (uzavřené období, chybějící období, zamčené datum)
 * běh neshodí — skončí v reportu a je vidět v Systém → Plánované úlohy.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Accounting\Payroll\PayrollAutoPostService;
use MyInvoice\Service\Cron\CronRun;

$dryRun = false;
$onlySupplier = null;
$period = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') { $dryRun = true; continue; }
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

/** @var PayrollAutoPostService $service */
$service = $container->get(PayrollAutoPostService::class);

$run = CronRun::start($pdo, 'cron-payroll-post');
$startedAt = microtime(true);

[$year, $month] = $period ?? PayrollAutoPostService::periodFor(new DateTimeImmutable('today'));

$supplierIds = $service->doubleEntrySupplierIds();
if ($onlySupplier !== null) {
    $supplierIds = in_array($onlySupplier, $supplierIds, true) ? [$onlySupplier] : [];
}

$report = [
    'dry_run'    => $dryRun,
    'year'       => $year,
    'month'      => $month,
    'suppliers'  => count($supplierIds),
    'candidates' => 0,
    'posted'     => 0,
    'already'    => 0,
    'conflicts'  => 0,
    'errors'     => 0,
    'skipped'    => [],
];

printf(
    "[%s] cron-payroll-post%s — období %02d/%04d, %d double-entry supplier(s)\n",
    date('Y-m-d H:i:s'),
    $dryRun ? ' --dry-run' : '',
    $month,
    $year,
    count($supplierIds),
);

$meta = ['user_agent' => 'cron-payroll-post/1.0'];

foreach ($supplierIds as $sid) {
    try {
        $r = $service->runForSupplier($sid, $year, $month, $dryRun, $meta);
    } catch (\Throwable $e) {
        // Selhání celého dodavatele (nedostupná karta, rozbitá osnova) nesmí zastavit
        // ostatní — mzdy jsou na sobě napříč firmami nezávislé.
        $report['errors']++;
        fprintf(STDERR, "  ✗ supplier #%d — %s\n", $sid, $e->getMessage());
        continue;
    }

    $report['candidates'] += $r['candidates'];
    $report['posted']     += $r['posted'];
    $report['already']    += $r['already'];
    $report['conflicts']  += $r['conflicts'];
    $report['errors']     += $r['errors'];

    foreach ($r['items'] as $item) {
        $label = sprintf('#%d %s (%d Kč)', $item['employee_id'], $item['name'], $item['gross']);
        switch ($item['status']) {
            case PayrollAutoPostService::STATUS_POSTED:
                printf("  ✓ supplier #%d %s → zápis #%d\n", $sid, $label, $item['journal_entry_id']);
                break;
            case PayrollAutoPostService::STATUS_DRY_RUN:
                printf("  [DRY] supplier #%d %s → zaúčtovalo by se\n", $sid, $label);
                break;
            case PayrollAutoPostService::STATUS_ALREADY:
                printf("  = supplier #%d %s — %s\n", $sid, $label, $item['message']);
                break;
            default:
                // Konflikt i chyba patří do reportu, ne jen do logu: v UI Plánované úlohy
                // je jinak nikdo neuvidí a mzda tiše nezaúčtovaná zůstane.
                $report['skipped'][] = [
                    'supplier_id' => $sid,
                    'employee_id' => $item['employee_id'],
                    'name'        => $item['name'],
                    'status'      => $item['status'],
                    'message'     => $item['message'] ?? '',
                ];
                fprintf(STDERR, "  ✗ supplier #%d %s — %s\n", $sid, $label, $item['message'] ?? '');
        }
    }
}

$ms = (int) ((microtime(true) - $startedAt) * 1000);
echo "  done ({$ms} ms): candidates={$report['candidates']}, posted={$report['posted']}, "
    . "already={$report['already']}, conflicts={$report['conflicts']}, errors={$report['errors']}"
    . ($dryRun ? ' (DRY RUN, nic se nezaúčtovalo)' : '') . "\n";

$pdo->prepare("INSERT INTO activity_log (action, payload) VALUES ('cron.payroll_post', ?)")
    ->execute([json_encode($report, JSON_UNESCAPED_UNICODE)]);

$run->finish($report['errors'] > 0 ? 'error' : 'ok', $report);
