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
 * Na spravované instalaci (`app.managed = true`) se bez `--operator` nespustí
 * (exit 3) — viz komentář u kontroly zámku níž. Provozovatel navíc může předat
 * konkrétní balíček místo dohledávání assetu podle tagu:
 *
 *   php api/bin/native-update.php --target=5.25.1 --operator \
 *       --bundle-url=https://github.com/…/myucto-5.25.1.tar.gz \
 *       --bundle-sha256=OTISK_64_HEX
 *
 * Návratové kódy: 0 = nasazeno, 1 = selhalo, 2 = chyba použití, 3 = zamčeno.
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

$target       = null;
$requestedBy  = null;
$preflight    = false;
$operator     = false;
$bundleUrl    = null;
$bundleSha256 = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--target=')) {
        $target = trim(substr($arg, strlen('--target=')));
    } elseif (str_starts_with($arg, '--requested-by=')) {
        $requestedBy = trim(substr($arg, strlen('--requested-by=')));
    } elseif (str_starts_with($arg, '--bundle-url=')) {
        $bundleUrl = trim(substr($arg, strlen('--bundle-url=')));
    } elseif (str_starts_with($arg, '--bundle-sha256=')) {
        $bundleSha256 = trim(substr($arg, strlen('--bundle-sha256=')));
    } elseif ($arg === '--preflight') {
        $preflight = true;
    } elseif ($arg === '--operator') {
        $operator = true;
    }
}

// `--requested-by` má u operátorské aktualizace jiný výchozí původ než u té
// z UI — do logu i do výsledku patří, kdo ji doopravdy spustil.
if ($requestedBy === null || $requestedBy === '') {
    $requestedBy = $operator ? 'operator' : 'cli';
}

// H-02 — spravovaná instalace: verzi nasazuje provozovatel, aby na celé flotile
// běžela jedna. Zámek je i na `POST /api/admin/update/trigger`, ale ten worker
// spouští detached proces — a tenhle skript jde zavolat i ručně z shellu, takže
// kontrola musí být i tady. Odmítnutí je hlasité: uživatel se musí dozvědět
// PROČ, ne jen že se nic nestalo.
//
// ⚠️ NEODSTRAŇOVAT `--operator` jako „obcházení zámku". Zámek existuje proto,
// aby si aktualizaci nespustil ZÁKAZNÍK z UI — a tam platí beze změny:
// `POST /api/admin/update/trigger` zůstává zamčený, to je zákaznická plocha.
// Na spravovanou instanci ale zákazník podle smlouvy (čl. 6.5) nemá přístup
// přes SSH ani FTP; shell má jedině provozovatel. Zámek na CLI tedy nechránil
// před ničím skutečným a zavíral jedinou cestu, kterou lze na flotilu dostat
// bezpečnostní opravu. Proto ho `--operator` vědomě obchází — hlasitě, se
// záznamem v logu, aby bylo v každém logu vidět, že aktualizaci spustil
// provozovatel, ne aplikace sama.
$managed = new ManagedModeGuard(Config::load(Bootstrap::rootDir()));
if ($managed->isLocked(ManagedModeGuard::CAPABILITY_SELF_UPDATE)) {
    if (!$operator) {
        fwrite(STDERR, $managed->explain(ManagedModeGuard::CAPABILITY_SELF_UPDATE) . "\n");
        fwrite(STDERR, "Spravovaný režim je zapnutý (cfg app.managed = true); self-update se nespustí.\n");
        fwrite(STDERR, "Provozovatel spouští aktualizaci s přepínačem --operator.\n");
        exit(3);
    }
    fwrite(STDERR, "warning: spravovaný režim je zapnutý, ale aktualizaci spustil provozovatel (--operator) "
        . "— zámek self-update se vědomě obchází (requested-by={$requestedBy}).\n");
}
// Na neřízené instalaci `--operator` nic neznamená (zámek stejně není aktivní)
// a NENÍ to chyba — provozovatel volá všude stejný příkaz.

if ($target === null || $target === '') {
    fwrite(STDERR, "Použití: php api/bin/native-update.php --target=X.Y.Z [--operator]"
        . " [--bundle-url=… --bundle-sha256=…] [--preflight] [--requested-by=…]\n");
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

// Neúplná nebo nesmyslná dvojice je chyba použití (exit 2), ne selhání
// nasazení — ať operátor pozná překlep v příkazu od skutečného problému.
try {
    $service->useBundleOverride($bundleUrl, $bundleSha256);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

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
