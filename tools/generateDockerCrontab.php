<?php

declare(strict_types=1);

/**
 * Vypíše obsah /etc/cron.d/myucto z CronCatalog. Volá se při Docker buildu:
 *
 *   php tools/generateDockerCrontab.php > /etc/cron.d/myucto
 *
 * Tím je vestavěný cron v image vždy v souladu s katalogem úloh (a s UI
 * „Plánované úlohy"), bez ručního opisování frekvencí.
 */

require __DIR__ . '/../api/vendor/autoload.php';

echo \MyInvoice\Service\Cron\DockerCrontabGenerator::generate();
