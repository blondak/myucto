<?php

declare(strict_types=1);

/**
 * MyÚčto.cz — nativní auto-update worker.
 *
 * Stáhne production bundle z GitHub release, ověří SHA-256, rozbalí ho
 * a nasadí přes běžící instalaci, pak spustí migrace. Detaily pipeline
 * a bezpečnostní model popisuje {@see \MyInvoice\Service\Update\NativeUpdateService}.
 *
 * Spouští se detached z UI (Systém → Aktualizace → „Aktualizovat na X.Y.Z"),
 * ale jde ho zavolat i ručně — je to normální CLI skript:
 *
 *   php api/bin/native-update.php --target=5.0.5
 *   php api/bin/native-update.php --target=5.0.5 --preflight   # jen kontrola, nic nemění
 *
 * Průběh se zapisuje do `storage/upgrade-requested.json` (krok + heartbeat),
 * výsledek do `storage/upgrade-result.json`, plný log do
 * `storage/upgrade-<timestamp>.log`. UI všechny tři čte.
 *
 * Idempotentní v tom smyslu, že opakované spuštění na už nasazenou verzi
 * bundle znovu nasadí — data ani konfiguraci se to nedotkne.
 */

require __DIR__ . '/../vendor/autoload.php';

if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}
if (!defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'wb'));
}

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\Update\NativeUpdateService;

// H-02 — spravovaná instalace: verzi nasazuje provozovatel, aby na celé flotile
// běžela jedna. Zámek je i na `POST /api/admin/update/trigger`, ale ten worker
// spouští detached proces — a tenhle skript jde zavolat i ručně z shellu, takže
// kontrola musí být i tady. Odmítnutí je hlasité: uživatel se musí dozvědět
// PROČ, ne jen že se nic nestalo.
$managed = new ManagedModeGuard(Config::load(Bootstrap::rootDir()));
if ($managed->isLocked(ManagedModeGuard::CAPABILITY_SELF_UPDATE)) {
    fwrite(STDERR, $managed->explain(ManagedModeGuard::CAPABILITY_SELF_UPDATE) . "\n");
    fwrite(STDERR, "Spravovaný režim je zapnutý (cfg app.managed = true); self-update se nespustí.\n");
    exit(3);
}

$target      = null;
$requestedBy = 'cli';
$preflight   = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--target=')) {
        $target = trim(substr($arg, strlen('--target=')));
    } elseif (str_starts_with($arg, '--requested-by=')) {
        $requestedBy = trim(substr($arg, strlen('--requested-by=')));
    } elseif ($arg === '--preflight') {
        $preflight = true;
    }
}

if ($target === null || $target === '') {
    fwrite(STDERR, "Použití: php api/bin/native-update.php --target=X.Y.Z [--preflight]\n");
    exit(2);
}
if (!NativeUpdateService::isValidVersion($target)) {
    fwrite(STDERR, "Cílová verze musí být semver X.Y.Z, dostal jsem: {$target}\n");
    exit(2);
}

// Update běží dlouho (download + 10k souborů + migrace) a nesmí ho zabít
// timeout ani odpojený klient.
@set_time_limit(0);
@ignore_user_abort(true);

$service = new NativeUpdateService();

if ($preflight) {
    $result = $service->preflight($target);
    fwrite(STDOUT, ($result['ok'] ? "PREFLIGHT OK\n" : "PREFLIGHT BLOKOVÁN\n"));
    foreach ($result['blockers'] as $b) {
        fwrite(STDOUT, '  [blocker] ' . $b . "\n");
    }
    foreach ($result['warnings'] as $w) {
        fwrite(STDOUT, '  [warning] ' . $w . "\n");
    }
    exit($result['ok'] ? 0 : 1);
}

$result = $service->run($target, $requestedBy);

fwrite(STDOUT, strtoupper((string) ($result['status'] ?? 'unknown')) . ': ' . ($result['message'] ?? '') . "\n");

exit(($result['status'] ?? '') === 'applied' ? 0 : 1);
