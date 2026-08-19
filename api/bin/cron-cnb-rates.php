<?php

declare(strict_types=1);

/**
 * Cron — denní stažení kurzovního lístku ČNB do `exchange_rates` (#28).
 *
 * Proč: tabulka se dosud plnila JEN jako ad-hoc cache při prvním dotazu na konkrétní
 * den ({@see \MyInvoice\Service\Currency\CnbExchangeRateClient}), takže u čerstvé
 * instalace v ní seděl jeden jediný kurzový den. Cizoměnová úhrada ke dni, ke kterému
 * není kurz ani zpětně, pak neměla čím ocenit pohyb — a peněžní deník kvůli jednomu
 * dokladu spadl celý. Souvislá historie je levná (jeden HTTP call zaplní celý den)
 * a odpadnou s ní i živá volání na ČNB z běžných requestů.
 *
 * Dohánění mezer: skript nekouká jen na dnešek, ale na okno posledních `--days` dnů
 * a doplní každý den, který v tabulce chybí. Výpadek hostingu přes víkend se tak
 * srovná sám při dalším běhu. Dny, které už kurz mají, se NEstahují znovu (žádné
 * HTTP volání) — běh nad zaplněnou tabulkou je proto skoro zadarmo.
 *
 * ČNB vyhlašuje kurz jen v pracovní dny a zveřejňuje ho kolem 14:30, proto je
 * doporučený čas 15:00 (viz CronCatalog); víkendy se přeskakují úplně a svátek
 * se prostě nevyhlásí — konzumenti mají last-known fallback (§ 4 ZoÚ stejně velí
 * kurz vyhlášený k rozhodnému dni).
 *
 * Idempotentní: INSERT ... ON DUPLICATE KEY UPDATE, opakovaný běh nic neduplikuje.
 * Pro jednorázové doplnění CELÉ historie slouž `api/bin/backfill-cnb-rates.php`
 * (roční export ČNB, jeden request na rok).
 *
 * Použití:
 *   php api/bin/cron-cnb-rates.php
 *   php api/bin/cron-cnb-rates.php --days=90     — širší okno na dohnání mezer
 *   php api/bin/cron-cnb-rates.php --dry-run     — jen vypíše, které dny chybí
 */

require __DIR__ . '/../vendor/autoload.php';

// STDOUT/STDERR existují jen v CLI SAPI. Skript jde spustit i z admin UI
// („Plánované úlohy → spustit teď"), kde může doběhnout pod php-cgi/FastCGI.
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Currency\CnbExchangeRateClient;

/** Kolik dnů zpět se standardně kontroluje. Měsíc pokryje i delší výpadek hostingu. */
const DEFAULT_DAYS = 30;
/** Strop okna — víc dnů patří do backfill-cnb-rates.php (roční export, 1 request/rok). */
const MAX_DAYS = 400;

$dryRun = false;
$days = DEFAULT_DAYS;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (str_starts_with($arg, '--days=')) {
        $days = (int) substr($arg, 7);
        continue;
    }
    fwrite(STDERR, "Unknown arg: {$arg}\n");
    exit(1);
}

if ($days < 1 || $days > MAX_DAYS) {
    fwrite(STDERR, '--days musí být 1 až ' . MAX_DAYS . " (delší historii doplň přes backfill-cnb-rates.php).\n");
    exit(1);
}

$container = Bootstrap::buildContainer();
$pdo = $container->get(Connection::class)->pdo();
/** @var CnbExchangeRateClient $client */
$client = $container->get(CnbExchangeRateClient::class);

$run = CronRun::start($pdo, 'cron-cnb-rates');
$startedAt = microtime(true);

$today = new DateTimeImmutable('today');
$from = $today->modify('-' . ($days - 1) . ' day');

printf(
    "[%s] cron-cnb-rates %s–%s%s\n",
    date('Y-m-d H:i:s'),
    $from->format('Y-m-d'),
    $today->format('Y-m-d'),
    $dryRun ? ' --dry-run' : '',
);

// Dny, které v okně už aspoň jeden kurz mají — ty se přeskočí bez HTTP volání.
$stmt = $pdo->prepare('SELECT DISTINCT rate_date FROM exchange_rates WHERE rate_date BETWEEN ? AND ?');
$stmt->execute([$from->format('Y-m-d'), $today->format('Y-m-d')]);
$cachedDays = array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);

$report = [
    'dry_run'          => $dryRun,
    'from'             => $from->format('Y-m-d'),
    'to'               => $today->format('Y-m-d'),
    'days_cached'      => 0,   // v tabulce už byly
    'days_fetched'     => 0,   // doplněné tímhle během
    'days_unpublished' => 0,   // ČNB nic nevyhlásila (svátek / dnešek před 14:30) nebo feed selhal
    'rates_saved'      => 0,
];
$missing = [];

for ($day = $from; $day <= $today; $day = $day->modify('+1 day')) {
    // Sobota/neděle — ČNB kurz nevyhlašuje, nemá cenu se ptát.
    if ((int) $day->format('N') >= 6) {
        continue;
    }

    $key = $day->format('Y-m-d');
    if (isset($cachedDays[$key])) {
        $report['days_cached']++;
        continue;
    }

    if ($dryRun) {
        $missing[] = $key;
        continue;
    }

    $saved = $client->syncDay($day);
    if ($saved === 0) {
        $report['days_unpublished']++;
        $missing[] = $key;
        continue;
    }
    $report['days_fetched']++;
    $report['rates_saved'] += $saved;
}

if ($dryRun) {
    $report['days_missing'] = count($missing);
}

$ms = (int) ((microtime(true) - $startedAt) * 1000);

if ($dryRun) {
    printf(
        "  done (%d ms): %d dnů v cache, %d chybí%s\n",
        $ms,
        $report['days_cached'],
        count($missing),
        $missing === [] ? '' : ' (' . implode(', ', array_slice($missing, 0, 15))
            . (count($missing) > 15 ? ', …' : '') . ')',
    );
} else {
    printf(
        "  done (%d ms): %d dnů v cache, %d doplněno (%d kurzů), %d nevyhlášeno\n",
        $ms,
        $report['days_cached'],
        $report['days_fetched'],
        $report['rates_saved'],
        $report['days_unpublished'],
    );
}

$run->finish('ok', $report);
