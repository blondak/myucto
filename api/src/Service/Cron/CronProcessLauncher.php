<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

/**
 * Spuštění jedné cron úlohy jako samostatného procesu.
 *
 * Vyčleněné do rozhraní kvůli {@see CronDispatcher} — jeho rozhodovací logiku
 * (co je na řadě, co má co dělat, co už bylo spuštěno) musí jít otestovat bez
 * plodění skutečných procesů.
 */
interface CronProcessLauncher
{
    /**
     * @param string      $script Název z {@see CronCatalog} (bez .php).
     * @param string|null $error  Vyplní se krátkým popisem, když spuštění selže.
     */
    public function launch(string $script, ?string &$error = null): bool;
}
