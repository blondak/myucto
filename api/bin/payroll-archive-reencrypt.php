<?php

declare(strict_types=1);

/**
 * Přešifrování mzdového archivu a přebalení mzdových hodnot na aktuální klíč.
 *
 * ── Legacy plaintext (výchozí režim) ─────────────────────────────────────────
 * Výplatní pásky, mzdové listy a potvrzení uložené před zavedením šifrování
 * leží nešifrované v `storage/payroll-documents/sup-{id}/{hh}/{hash}`. Skript
 * je zašifruje datovým klíčem osoby, zapsanou kopii znovu přečte a ověří hash
 * obsahu a teprve potom originál smaže. Opakované spuštění je bez účinku.
 * Podrobnosti: PayrollArchiveReencryptionService.
 *
 * ── Rotace klíče (--rewrap) ─────────────────────────────────────────────────
 * Po výměně `app.secret_encryption_key` (starý klíč dočasně v
 * `app.secret_encryption_previous_keys`) přebalí všechny mzdové `enc:v2:`
 * hodnoty v databázi, datové klíče dokumentů a soubory exportů na nový klíč.
 * `--status` jen vypíše, kolik hodnot ještě nese starý klíč. Postup je
 * popsaný v manuálu, kapitola 999.9.4.
 *
 * Použití:
 *   php api/bin/payroll-archive-reencrypt.php --dry-run
 *   php api/bin/payroll-archive-reencrypt.php
 *   php api/bin/payroll-archive-reencrypt.php --supplier=1 --include-orphans
 *   php api/bin/payroll-archive-reencrypt.php --purge-erased
 *   php api/bin/payroll-archive-reencrypt.php --rewrap --dry-run
 *   php api/bin/payroll-archive-reencrypt.php --status
 *
 * Volby:
 *   --dry-run          nic nezapisuje, jen vypíše, co by udělal
 *   --supplier=N       jen jedna firma
 *   --limit=N          nejvýš N souborů (u --rewrap hodnot) za běh
 *   --include-orphans  zašifrovat i soubory, na které neodkazuje evidence
 *                      (firemním klíčem; nic se nemaže)
 *   --purge-erased     smazat plaintext osob po krypto-výmazu (nevratné)
 *   --rewrap           po přešifrování archivu přebalit hodnoty na aktuální klíč
 *   --status           jen stav rotace klíče (read-only)
 *   --json             výstup jako JSON
 *
 * Výstup nikdy neobsahuje obsah dokumentů ani klíče, jen počty, id firem
 * a hashe souborů. Cílovou databázi lze přepnout přes MYINVOICE_DB_NAME,
 * úložiště přes MYINVOICE_DATA_DIR.
 *
 * Návratový kód: 0 = hotovo, 1 = něco selhalo nebo čeká na ruční posouzení,
 * 2 = chybné volby.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Payroll\Document\PayrollArchiveReencryptionService;
use MyInvoice\Service\Payroll\Security\PayrollKeyRotationService;

$opts = getopt('', [
    'dry-run',
    'supplier:',
    'limit:',
    'include-orphans',
    'purge-erased',
    'rewrap',
    'status',
    'json',
    'help',
]);
if ($opts === false || array_key_exists('help', $opts)) {
    fwrite(STDERR, "Použití: php api/bin/payroll-archive-reencrypt.php [--dry-run] [--supplier=N] [--limit=N]\n"
        . "  [--include-orphans] [--purge-erased] [--rewrap] [--status] [--json]\n");
    exit(array_key_exists('help', (array) $opts) ? 0 : 2);
}

$positive = static function (string $name) use ($opts): ?int {
    if (!array_key_exists($name, $opts)) {
        return null;
    }
    $value = (string) $opts[$name];
    if (preg_match('/^[1-9][0-9]{0,9}$/D', $value) !== 1) {
        fwrite(STDERR, "--{$name} musí být kladné celé číslo.\n");
        exit(2);
    }

    return (int) $value;
};
$supplierId = $positive('supplier');
$limit = $positive('limit');
$dryRun = array_key_exists('dry-run', $opts);
$json = array_key_exists('json', $opts);

$container = Bootstrap::buildApp()->getContainer();
$archive = $container->get(PayrollArchiveReencryptionService::class);
$rotation = $container->get(PayrollKeyRotationService::class);

$out = static function (string $line) use ($json): void {
    if (!$json) {
        echo $line, "\n";
    }
};

if (array_key_exists('status', $opts)) {
    $status = $rotation->status($supplierId);
    $legacy = $archive->countLegacy($supplierId);
    if ($json) {
        echo json_encode(['legacy' => $legacy, 'rotation' => $status], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    } else {
        $out("Aktuální klíč: {$status['current_key_id']}");
        $out("Nešifrované soubory archivu: {$legacy['total']}");
        foreach ($status['targets'] as $target) {
            if ($target['stale'] > 0) {
                $out(sprintf('  %-60s starý klíč: %d%s', $target['name'], $target['stale'], $target['unknown'] > 0 ? " (NEZNÁMÝ klíč: {$target['unknown']})" : ''));
            }
        }
        $out("Hodnot pod starým klíčem: {$status['stale_total']}, z toho pod klíčem mimo konfiguraci: {$status['unknown_total']}");
    }
    exit($status['unknown_total'] > 0 ? 1 : 0);
}

$out('Mzdový archiv - ' . ($dryRun ? 'DRY-RUN' : 'ZÁPIS') . ($supplierId !== null ? " · firma {$supplierId}" : ''));

$report = $archive->run(
    $supplierId,
    $dryRun,
    array_key_exists('include-orphans', $opts),
    array_key_exists('purge-erased', $opts),
    $limit,
);

$labels = [
    PayrollArchiveReencryptionService::STATUS_ENCRYPTED => 'zašifrováno a ověřeno',
    PayrollArchiveReencryptionService::STATUS_WOULD_ENCRYPT => 'k zašifrování',
    PayrollArchiveReencryptionService::STATUS_ORPHAN_SKIPPED => 'osiřelé (bez evidence), přeskočeno',
    PayrollArchiveReencryptionService::STATUS_ERASED_SKIPPED => 'osoby po výmazu, přeskočeno',
    PayrollArchiveReencryptionService::STATUS_WOULD_PURGE => 'osoby po výmazu, ke smazání',
    PayrollArchiveReencryptionService::STATUS_ERASED_PURGED => 'osoby po výmazu, smazáno',
    PayrollArchiveReencryptionService::STATUS_INTEGRITY_MISMATCH => 'obsah neodpovídá hashi, k ručnímu posouzení',
    PayrollArchiveReencryptionService::STATUS_FAILED => 'selhalo (originál ponechán)',
    PayrollArchiveReencryptionService::STATUS_GONE => 'mezitím zmizelo',
];
foreach ($report['counts'] as $status => $count) {
    if ($count > 0) {
        $out(sprintf('  %-48s %d', $labels[$status] ?? $status, $count));
    }
}
$out("Zpracováno: {$report['processed']}, zbývá (limit): {$report['remaining']}");
foreach ($report['items'] as $item) {
    if (in_array($item['status'], [
        PayrollArchiveReencryptionService::STATUS_FAILED,
        PayrollArchiveReencryptionService::STATUS_INTEGRITY_MISMATCH,
    ], true)) {
        $out(sprintf('  ! firma %d · %s · %s%s', $item['supplier_id'], $item['storage_key'], $item['status'], isset($item['error']) ? ' · ' . $item['error'] : ''));
    }
}

$rewrap = null;
if (array_key_exists('rewrap', $opts)) {
    $rewrap = $rotation->rewrapAll($supplierId, $dryRun, $limit);
    $out('');
    $out('Přebalení na aktuální klíč - ' . ($dryRun ? 'DRY-RUN' : 'ZÁPIS'));
    foreach ($rewrap['targets'] as $target) {
        if ($target['rewrapped'] + $target['would_rewrap'] + $target['failed'] > 0) {
            $out(sprintf('  %-60s %d přebaleno, %d k přebalení, %d selhalo', $target['name'], $target['rewrapped'], $target['would_rewrap'], $target['failed']));
        }
    }
    $out("Celkem: {$rewrap['rewrapped']} přebaleno, {$rewrap['would_rewrap']} k přebalení, {$rewrap['failed']} selhalo"
        . ($rewrap['limit_reached'] ? ' (dosažen limit, spusť znovu)' : ''));
}

if ($json) {
    echo json_encode(['archive' => $report, 'rewrap' => $rewrap], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
}

$problems = $report['counts'][PayrollArchiveReencryptionService::STATUS_FAILED]
    + $report['counts'][PayrollArchiveReencryptionService::STATUS_INTEGRITY_MISMATCH]
    + ($rewrap['failed'] ?? 0);
exit($problems > 0 ? 1 : 0);
