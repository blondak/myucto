<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Demo\DemoProvisioner;

if (!in_array('--yes', $argv, true) && !in_array('-y', $argv, true)) {
    fwrite(STDERR, "[demo] Provisioning mění pouze nakonfigurovanou demo databázi. Potvrďte parametrem --yes.\n");
    exit(2);
}

try {
    $app = Bootstrap::buildApp();
    $result = $app->getContainer()->get(DemoProvisioner::class)->provision(
        refreshSample: in_array('--refresh-sample', $argv, true),
    );
} catch (Throwable $e) {
    fwrite(STDERR, '[demo] Provisioning selhal: ' . $e->getMessage() . "\n");
    exit(1);
}

printf("[demo] Role #%d, uživatel #%d.\n", $result['role_id'], $result['user_id']);
printf("[demo] Firmy: %s.\n", implode(', ', array_map(static fn (int $id): string => '#' . $id, $result['supplier_ids'])));
if ($result['generated'] !== []) {
    printf("[demo] Ukázková data vytvořena pro: %s.\n", implode(', ', array_map(static fn (int $id): string => '#' . $id, $result['generated'])));
}
if ($result['refreshed'] !== []) {
    printf("[demo] Původní ukázková data obnovena pro: %s.\n", implode(', ', array_map(static fn (int $id): string => '#' . $id, $result['refreshed'])));
}
if ($result['skipped'] !== []) {
    printf("[demo] Existující ukázková data ponechána pro: %s.\n", implode(', ', array_map(static fn (int $id): string => '#' . $id, $result['skipped'])));
}
