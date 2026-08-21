<?php

declare(strict_types=1);

/**
 * Plánovač pro režim CronScheduleMode::DISPATCHER — jediná položka v crontabu
 * (`* * * * *`), která si sama spočítá, které úlohy z katalogu jsou na řadě.
 *
 * V režimu INDIVIDUAL (default) se tenhle skript vůbec neplánuje a celý systém
 * se chová jako dřív: 20 samostatných položek. Přepíná se v UI
 * Systém → Plánované úlohy.
 *
 * Bootstrap je tady schválně minimální (Config + PDO, žádný DI kontejner):
 * dispatcher sám nedělá práci úloh, jen je spouští, a běží každou minutu.
 *
 * Použití:
 *   php api/bin/cron-dispatch.php              — ostrý tick
 *   php api/bin/cron-dispatch.php --dry-run    — jen vypíše, co by spustil
 *   php api/bin/cron-dispatch.php --at="2026-08-03 09:00"  — simulace jiné minuty
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\BackgroundCronProcessLauncher;
use MyInvoice\Service\Cron\CronDispatcher;
use MyInvoice\Service\Cron\CronJobGate;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Cron\CronScheduleMode;
use MyInvoice\Service\System\MaintenanceLock;

$dryRun = false;
$at = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (str_starts_with($arg, '--at=')) {
        $at = substr($arg, 5);
        continue;
    }
    fwrite(STDERR, "Unknown arg: {$arg}\n");
    exit(1);
}

$config = Config::load(Bootstrap::rootDir());
date_default_timezone_set((string) $config->get('app.timezone', 'Europe/Prague'));
$pdo = (new Connection($config))->pdo();

try {
    $now = $at === null ? new DateTimeImmutable() : new DateTimeImmutable($at);
} catch (Throwable $e) {
    fwrite(STDERR, "Neplatný --at: {$e->getMessage()}\n");
    exit(1);
}

$run = $dryRun ? null : CronRun::start($pdo, 'cron-dispatch');

try {
    // Pojistka proti nedopatření: běží-li instalace v režimu INDIVIDUAL, spouští
    // úlohy crontab sám. Dispatcher by je pustil PODRUHÉ. Radši nic neudělá
    // a řekne proč — tichý duplicitní běh cron-generate-recurring-invoices
    // by znamenal doklady navíc.
    $mode = CronScheduleMode::current($pdo);
    if ($mode !== CronScheduleMode::DISPATCHER && !$dryRun) {
        $result = ['skipped' => 'schedule_mode_is_' . $mode];
        $run?->finish('ok', $result);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    // Zámek údržby zastavuje SPOUŠTĚNÍ nových úloh, ne dispatcher samotný:
    // heartbeat se posouvá dál, aby hosting po celou dobu údržby viděl, že
    // instalace žije, a aby po odstranění zámku nic nezůstalo hlášené jako mrtvé.
    $dispatcher = new CronDispatcher(
        $pdo,
        new CronJobGate($config, $pdo),
        new BackgroundCronProcessLauncher($config->dataDir()),
        new MaintenanceLock($config),
    );

    $report = $dispatcher->tick($now, $dryRun);

    // Tick, který nic nespustil, je normální stav (většina minut) — do historie
    // běhů se nezapisuje, jen se posune heartbeat. Viz CronRun.
    $didWork = $report['launched'] !== [] || $report['errors'] !== [];
    $status = $report['errors'] !== [] ? 'error' : 'ok';

    $run?->finish($status, $report, null, null, $didWork);
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($report['errors'] !== [] ? 2 : 0);
} catch (Throwable $e) {
    $run?->finish('error', ['error' => $e->getMessage()], $e->getMessage());
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
