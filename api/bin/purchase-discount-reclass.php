<?php

declare(strict_types=1);

/**
 * Přeúčtuje zaúčtované přijaté faktury, u kterých slevový řádek (Sleva, Rabatt,
 * Aktionsrabatt, Discount, Kupon…) šel na vlastní účet místo do ceny zlevněného zboží.
 * Logika: MyInvoice\Service\Accounting\Expense\PurchaseDiscountReclass (přepis na místě,
 * jen otevřený rok; když by to znamenalo storno, doklad se jen vypíše).
 *
 * NENÍ v auto-backfillu migrací: jde o kontaci konkrétních dokladů, pouští se ručně.
 * Bez --apply jen čte (nic nezapisuje), takže náhled jde pustit i proti ostrým datům.
 *
 * Použití:
 *   php api/bin/purchase-discount-reclass.php                         # náhled všech kandidátů
 *   php api/bin/purchase-discount-reclass.php --supplier=1            # náhled kandidátů jedné firmy
 *   php api/bin/purchase-discount-reclass.php --supplier=1 --id=43    # náhled jednoho dokladu
 *   php api/bin/purchase-discount-reclass.php --supplier=1 --id=43 --apply
 */

require __DIR__ . '/../vendor/autoload.php';

$apply = in_array('--apply', $argv, true) && !in_array('--dry-run', $argv, true);
$supplierId = null;
$id = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--supplier=')) {
        $supplierId = (int) substr($arg, 11);
    } elseif (str_starts_with($arg, '--id=')) {
        $id = (int) substr($arg, 5);
    }
}
if (($supplierId !== null && $supplierId <= 0) || ($id !== null && $id <= 0)) {
    fwrite(STDERR, "Neplatné --supplier nebo --id.\n");
    exit(2);
}
if ($id !== null && $supplierId === null) {
    fwrite(STDERR, "--id vyžaduje --supplier.\n");
    exit(2);
}
if ($apply && $id === null) {
    fwrite(STDERR, "--apply jen s konkrétním dokladem (--supplier= --id=), hromadně se nepřeúčtovává.\n");
    exit(2);
}

$app = \MyInvoice\Bootstrap::buildApp();
$service = $app->getContainer()->get(\MyInvoice\Service\Accounting\Expense\PurchaseDiscountReclass::class);

$reports = $id !== null ? [$service->run($supplierId, $id, $apply)] : $service->candidates($supplierId);
$mode = $apply ? '' : '[NÁHLED] ';

foreach ($reports as $r) {
    printf("%sfirma #%d PF #%d — zápis #%s (%s), stav %s, slevových řádků %d\n",
        $mode, $r['supplier_id'], $r['purchase_invoice_id'], $r['entry_id'] ?? '—', $r['entry_date'] ?? '—',
        $r['state'], $r['discount_lines']);
    if ($r['posting_changes']) {
        echo '    teď:  ', implode(' | ', $r['before']), "\n";
        echo '    nově: ', implode(' | ', $r['after']), "\n";
        echo '    způsob: ', $r['strategy'] === 'replace' ? 'přepis na místě' : ($r['strategy'] ?? '—'), "\n";
    } else {
        echo "    zaúčtování beze změny\n";
    }
    if ($r['classification_changes'] > 0) {
        echo "    druh výdaje slevového řádku srovnán na zlevněnou položku: {$r['classification_changes']}\n";
    }
    if ($r['trace_fixes'] > 0) {
        echo "    doplněná cizoměnová stopa saldokontních řádků: {$r['trace_fixes']}\n";
    }
    if ($r['cards'] !== null) {
        printf("    drobný majetek: založeno %d, odebráno %d\n", count($r['cards']['created'] ?? []), count($r['cards']['pruned'] ?? []));
    }
    if ($r['message'] !== null) {
        echo '    ! ', $r['message'], "\n";
    }
}
echo "\n{$mode}Dokladů: " . count($reports) . ($apply ? ', zapsáno.' : '. Zápis: --supplier=N --id=N --apply') . "\n";
