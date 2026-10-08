<?php

declare(strict_types=1);

/**
 * Oprava částek dokladů převzatých z Fakturoidu před opravou #128 a #131.
 *
 * Import dřív u dokladů s cenami včetně DPH (`vat_price_mode = from_total_with_vat`)
 * bral cenu s DPH jako cenu bez DPH a DPH přičetl podruhé, u přijatých dokladů
 * nepřevzal zaokrouhlení a zdrojovou rekapitulaci DPH. Opakovaný import tyto
 * doklady přeskočí podle `fakturoid_id`, proto tenhle skript.
 *
 * Skript znovu načte doklady z Fakturoid API, porovná je s doklady v systému
 * a vypíše, co by se změnilo. Bez --apply nic nezapíše. Zamčený doklad, doklad
 * v období s podaným přiznáním, kontrolním nebo souhrnným hlášením k DPH a doklad
 * se skutečnou úhradou jen nahlásí ke kontrole. Pravidla viz
 * MyInvoice\Service\Import\FakturoidImportedAmountsRepair.
 *
 * Použití:
 *   php api/bin/fix-fakturoid-imported-amounts.php --supplier-id=1            # jen výpis
 *   php api/bin/fix-fakturoid-imported-amounts.php --supplier-id=1 --apply    # zápis
 *   php api/bin/fix-fakturoid-imported-amounts.php --supplier-id=1 --json     # výpis jako JSON
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Import\FakturoidClient;
use MyInvoice\Service\Import\FakturoidImportedAmountsRepair;

$apply = in_array('--apply', $argv, true);
$json = in_array('--json', $argv, true);
$supplierId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--supplier-id=(\d+)$/', $arg, $m)) $supplierId = (int) $m[1];
}
if ($supplierId === null) {
    fwrite(STDERR, "Chybí --supplier-id=N\n");
    exit(2);
}

$container = Bootstrap::buildApp()->getContainer();
$client = $container->get(FakturoidClient::class);
if ($client->getCredentials($supplierId) === null) {
    fwrite(STDERR, "Firma #{$supplierId} nemá nastavené přístupy k Fakturoidu.\n");
    exit(2);
}

$report = $container->get(FakturoidImportedAmountsRepair::class)->run(
    $supplierId,
    $client->getAll($supplierId, 'invoices.json'),
    $client->getAll($supplierId, 'expenses.json'),
    $apply,
);

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

$fmt = static fn (array $a): string => sprintf(
    'základ %s, DPH %s, celkem %s, zaokrouhlení %s',
    number_format($a['base'], 2, ',', ' '),
    number_format($a['vat'], 2, ',', ' '),
    number_format($a['total'], 2, ',', ' '),
    number_format($a['rounding'], 2, ',', ' '),
);
$label = static fn (array $e): string => sprintf(
    '%s #%d (%s, Fakturoid %d)',
    $e['agenda'] === 'issued' ? 'Vydaná' : 'Přijatá',
    $e['local_id'],
    $e['number'] !== '' ? $e['number'] : '-',
    $e['fakturoid_id'],
);

echo ($apply ? 'Opraveno' : 'K opravě') . ': ' . count($report['changed']) . "\n";
foreach ($report['changed'] as $e) {
    echo '  ' . $label($e) . "\n";
    echo '    před: ' . $fmt($e['before']) . "\n";
    echo '    po:   ' . $fmt($e['after']) . "\n";
}
echo 'Ke kontrole (nic se nezměnilo nebo zbývá rozdíl): ' . count($report['review']) . "\n";
foreach ($report['review'] as $e) {
    echo '  ' . $label($e) . ': ' . $e['review'] . "\n";
}
echo 'Beze změny: ' . $report['unchanged'] . "\n";
if ($report['not_in_source'] > 0) {
    echo 'Ve Fakturoidu nenalezeno: ' . $report['not_in_source'] . "\n";
}
if (!$apply) {
    echo "\n(jen výpis, pro zápis spusťte s --apply)\n";
}
