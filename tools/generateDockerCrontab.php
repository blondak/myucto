<?php

declare(strict_types=1);

/**
 * Vypíše obsah /etc/cron.d/myucto z CronCatalog.
 *
 * Dva režimy:
 *
 *   php tools/generateDockerCrontab.php
 *       Všechny úlohy z katalogu. Volá se při Docker BUILDU, kdy runtime
 *       konfigurace instalace ještě neexistuje — výsledek je bezpečný fallback.
 *
 *   php tools/generateDockerCrontab.php --runtime
 *       Jen úlohy, které u téhle instalace mají co dělat (viz CronJobGate).
 *       Volá se z entrypointu po migracích. Typický tenant bez inboxu přijatých
 *       faktur a bez adresáře bankovních výpisů tím přijde o desítky běhů denně,
 *       které dneska jen postaví kontejner a zjistí, že nemají co dělat.
 *
 * Bootstrap je tady schválně minimální (Config + Connection, ne DI kontejner) —
 * skript běží při startu kontejneru a nemá důvod stavět celou aplikaci.
 */

require __DIR__ . '/../api/vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronJobGate;
use MyInvoice\Service\Cron\CronScheduleMode;
use MyInvoice\Service\Cron\DockerCrontabGenerator;

$args = array_slice($argv, 1);
$runtime = in_array('--runtime', $args, true);

// Explicitní volba režimu (hlavně pro testy a diagnostiku); bez ní se v runtime
// režimu bere nastavení z DB, jinak platí default INDIVIDUAL.
$forcedMode = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--mode=')) {
        $forcedMode = substr($arg, 7);
    }
}

$gate = null;
$mode = $forcedMode ?? CronScheduleMode::INDIVIDUAL;

if ($runtime) {
    // Fail-open: když se konfigurace nebo DB nedá načíst, vygenerujeme plný
    // katalog v default režimu. Nedostupná DB při startu nesmí vést k tichému
    // vypnutí cronu ani k přepnutí na dispatcher, který nikdo nenaplánoval.
    try {
        $config = Config::load(Bootstrap::rootDir());
        $pdo = null;
        try {
            $pdo = (new Connection($config))->pdo();
        } catch (Throwable $e) {
            fwrite(STDERR, "[generateDockerCrontab] DB nedostupná, gate jede jen nad configem: {$e->getMessage()}\n");
        }
        $gate = new CronJobGate($config, $pdo);
        if ($forcedMode === null) {
            $mode = CronScheduleMode::current($pdo);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "[generateDockerCrontab] config nedostupný, generuji plný katalog: {$e->getMessage()}\n");
        $gate = null;
        $mode = $forcedMode ?? CronScheduleMode::INDIVIDUAL;
    }
}

if (!CronScheduleMode::isValid($mode)) {
    fwrite(STDERR, "[generateDockerCrontab] neznámý režim '{$mode}', používám default.\n");
    $mode = CronScheduleMode::INDIVIDUAL;
}

echo DockerCrontabGenerator::generate($gate, $mode);
