<?php

declare(strict_types=1);

/**
 * cmd/check-openapi-coverage.php
 *
 * Audit drift mezi Slim routes (api/src/Routes.php) a api/openapi.yaml.
 * Reportuje:
 *   - routes v kódu, které nejsou dokumentované → riziko, že je integrátoři minou
 *   - paths v openapi.yaml, které už v kódu neexistují → mrtvá dokumentace
 *
 * Záměrně ignoruje:
 *   - /api/admin/* — interní endpointy, plán je nedokumentovat
 *   - /api/auth/setup*, /api/auth/login, /logout, /forgot, /reset, /change-password,
 *     /totp/*, /me — UI/wizard scope, integrace přes bearer je neřeší
 *   - /api/public/approval/* — pro koncové zákazníky, ne pro integrace
 *   - /api/payroll/* — interní session-only mzdová agenda; veřejné API je až
 *     explicitně kurátorovaný read-only subset pod /api/v1/*
 *   - /api/openapi.yaml, /api/docs, /api/health, /api/version — self-reference / triviální
 *   - mutace na /api/settings/*, /api/suppliers (POST/PUT/DELETE) a /api/admin/update/*
 *   - /api/maintenance/* (sample data, admin), /api/settings/{pdf-signing,signing,
 *     email-profiles,bank-email-notices} — admin konfigurace, ne pro integrace
 *
 * Extrakce routes: skupiny $app->group('/prefix', fn) se párují závorkově
 * (per-group scope). NEporovnávat $g-> globálně přes všechny prefixy —
 * to cross-multiplikuje routes a generuje falešné nálezy.
 *
 * Exit kódy:
 *   0 = bez nálezů
 *   1 = mismatch nalezen (CI warning, ne fail)
 */

$root = dirname(__DIR__);
require $root . '/api/vendor/autoload.php';

$routesFile  = $root . '/api/src/Routes.php';
$openapiFile = $root . '/api/openapi.yaml';

if (!is_file($routesFile))  { fwrite(STDERR, "ERR: missing $routesFile\n"); exit(2); }
if (!is_file($openapiFile)) { fwrite(STDERR, "ERR: missing $openapiFile\n"); exit(2); }

// --- 1) Extract routes z Routes.php ----------------------------------------
$src = (string) file_get_contents($routesFile);
$len = strlen($src);
$routes = [];

// 1a) Najdi každou skupinu $app->group('/prefix', function ($g) { ... }) a spáruj
//     složené závorky, aby se $g-> routes přiřadily jen VLASTNÍ skupině.
//     (Dřív se naivně párovaly všechny $g-> v souboru pod každý prefix →
//      cross-multiplikace routes přes všechny group prefixy = falešné nálezy.)
$groupRanges = []; // [prefix, bodyStart, bodyEnd]
$offset = 0;
while (preg_match(
    '/\$app->group\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*function\s*\([^)]*\)\s*(?:use\s*\([^)]*\)\s*)?\{/',
    substr($src, $offset),
    $gm,
    PREG_OFFSET_CAPTURE
)) {
    $prefix   = $gm[1][0];
    $bracePos = $offset + $gm[0][1] + strlen($gm[0][0]) - 1; // pozice otevírací {
    $depth = 0; $end = $bracePos;
    for ($i = $bracePos; $i < $len; $i++) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') { if (--$depth === 0) { $end = $i; break; } }
    }
    $groupRanges[] = [$prefix, $bracePos, $end];
    $offset = $end + 1;
}
foreach ($groupRanges as [$prefix, $start, $end]) {
    $body = substr($src, $start, $end - $start + 1);
    if (preg_match_all(
        '/\$g->(get|post|put|patch|delete|any)\s*\(\s*[\'"]([^\'"]+)[\'"]/i',
        $body,
        $im,
        PREG_SET_ORDER
    )) {
        foreach ($im as $hit) {
            $routes[] = ['method' => strtoupper($hit[1]), 'path' => $prefix . $hit[2]];
        }
    }
}

// 1b) Plain $app-> routes MIMO skupiny — vymaskuj těla skupin, ať se
//     $g-> volání nechytnou jako $app-> (a naopak zůstanou jen top-level routes).
$masked = $src;
foreach ($groupRanges as [$prefix, $start, $end]) {
    $masked = substr_replace($masked, str_repeat(' ', $end - $start + 1), $start, $end - $start + 1);
}
if (preg_match_all(
    '/\$app->(get|post|put|patch|delete|any)\s*\(\s*[\'"]([^\'"]+)[\'"]/i',
    $masked,
    $m,
    PREG_SET_ORDER
)) {
    foreach ($m as $hit) {
        $routes[] = ['method' => strtoupper($hit[1]), 'path' => $hit[2]];
    }
}

// Normalize placeholdery: `{id:[0-9]+}` → `{id}`, `{entity_type:invoice|work_report}` → `{entity_type}`
// Musí umět i jednu úroveň vnořených složených závorek (regex kvantifikátory typu
// `{date:\d{4}-\d{2}-\d{2}}` nebo `{batchId:[a-fA-F0-9]{32}}`) — jinak se placeholder
// oseká na první vnitřní `}` a zbytek regexu zůstane v cestě jako text.
foreach ($routes as &$r) {
    $r['path'] = preg_replace('/\{(\w+):(?:[^{}]|\{[^{}]*\})*\}/', '{$1}', $r['path']);
}
unset($r);

// Dedupe (identický method+path se může objevit víckrát)
$seen = [];
$routes = array_values(array_filter($routes, static function ($r) use (&$seen) {
    $k = $r['method'] . ' ' . $r['path'];
    if (isset($seen[$k])) return false;
    $seen[$k] = true;
    return true;
}));

// --- 2) Endpoints, které vědomě neaudituji ---------------------------------
$skipPrefixes = [
    '/api/admin/',
    '/api/public/',
    '/api/auth/setup',
    '/api/auth/login',
    '/api/auth/logout',
    '/api/auth/me',
    '/api/auth/forgot',
    '/api/auth/reset',
    '/api/auth/change-password',
    '/api/auth/totp/',
    '/api/auth/tokens',            // session-only, nelze volat bearer-em
    '/api/payroll/',               // interní session-only mzdový bounded context
    '/api/settings/email-branding/', // admin UI tooling (logo upload, preview)
    '/api/maintenance/',           // správa sample dat, admin-only (RoleMiddleware)
    '/api/settings/pdf-signing',   // admin konfigurace el. podpisu (certifikáty)
    '/api/settings/signing',       // admin podpisové profily + credentials
    '/api/settings/bank-email-notices', // admin: parsování bankovních e-mailů
];
$skipExact = [
    '/api/openapi.yaml',
    '/api/docs',
    '/api/reference',
    '/api/scalar',
    '/api/health',          // dokumentované, ale alias /api/v1/health
    '/api/version',
    '/api/invoices/preview-varsymbol', // admin tooling
    '/api/invoices/{id}/send-test',
    '/api/invoices/{id}/reminder-test',
    '/api/invoices/{id}/request-approval-test',
    '/api/settings/email-profiles', // admin: konfigurace odesílacích e-mail profilů
    '/api/{path}',  // catch-all 404 fallback
];

$shouldSkip = function (string $path) use ($skipPrefixes, $skipExact): bool {
    foreach ($skipPrefixes as $p) if (str_starts_with($path, $p)) return true;
    return in_array($path, $skipExact, true);
};

// Settings/suppliers mutace (POST/PUT/DELETE) — záměrně mimo public API
$isSettingsMutation = function (string $method, string $path): bool {
    if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        if (preg_match('#^/api/(settings|suppliers)(/|$)#', $path)) return true;
    }
    return false;
};

// --- 3) Načti openapi.yaml -------------------------------------------------
$yaml = (string) file_get_contents($openapiFile);
// Mini parser: nepoužíváme Symfony Yaml (není v deps), stačí grep paths
preg_match_all('/^  (\/api\/v1\/[^:]+):/m', $yaml, $pm);
$specPaths = [];
foreach ($pm[1] as $p) {
    // Strip /api/v1 prefix → "/api/..." (aby šlo porovnat s routes)
    $normalized = '/api' . substr($p, strlen('/api/v1'));
    $specPaths[] = $normalized;
}

// Pro každý path zjistíme, jaké metody jsou v něm definované
$specByPath = [];
$lines = explode("\n", $yaml);
$currentPath = null;
foreach ($lines as $line) {
    if (preg_match('/^  (\/api\/v1\/[^:]+):/', $line, $m)) {
        $currentPath = '/api' . substr($m[1], strlen('/api/v1'));
        $specByPath[$currentPath] = [];
        continue;
    }
    if ($currentPath !== null && preg_match('/^    (get|post|put|patch|delete):/i', $line, $m)) {
        $specByPath[$currentPath][] = strtoupper($m[1]);
    }
    // Reset když narazíme na další top-level klíč (1 mezera nebo žádná)
    if (preg_match('/^[a-z]/', $line)) {
        $currentPath = null;
    }
}

// --- 4) Porovnání ---------------------------------------------------------
$missingInSpec = []; // route v kódu, chybí v specu
$staleInSpec   = []; // path v specu, chybí v kódu

foreach ($routes as $r) {
    if ($shouldSkip($r['path']))                     continue;
    if ($isSettingsMutation($r['method'], $r['path'])) continue;
    if ($r['method'] === 'ANY')                      continue; // 404 fallback

    $found = isset($specByPath[$r['path']]) && in_array($r['method'], $specByPath[$r['path']], true);
    if (!$found) {
        $missingInSpec[] = $r['method'] . ' ' . $r['path'];
    }
}

// Routes existing in code (any method), indexed by path
$codeByPath = [];
foreach ($routes as $r) {
    $codeByPath[$r['path']][] = $r['method'];
}
foreach ($specByPath as $path => $methods) {
    if (!isset($codeByPath[$path])) {
        $staleInSpec[] = '(no methods) ' . $path;
        continue;
    }
    foreach ($methods as $method) {
        if (!in_array($method, $codeByPath[$path], true)) {
            $staleInSpec[] = $method . ' ' . $path;
        }
    }
}

// --- 5) Report -------------------------------------------------------------
$has = static fn (array $a) => count($a) > 0;
$pad = static fn (string $s, int $n) => str_pad($s, $n);

echo "OpenAPI ↔ routes coverage\n";
echo "==========================\n";
echo "Routes scanned (after filters): " . count(array_filter(
    $routes,
    static fn ($r) => !$shouldSkip($r['path']) && !$isSettingsMutation($r['method'], $r['path']) && $r['method'] !== 'ANY'
)) . "\n";
echo "Spec paths: " . count($specPaths) . " (each may have multiple methods)\n\n";

if (!$has($missingInSpec) && !$has($staleInSpec)) {
    echo "✓ No drift.\n";
    exit(0);
}

if ($has($missingInSpec)) {
    echo "Missing in openapi.yaml (" . count($missingInSpec) . "):\n";
    foreach ($missingInSpec as $row) echo "  - $row\n";
    echo "\n";
}
if ($has($staleInSpec)) {
    echo "Stale in openapi.yaml — not in code (" . count($staleInSpec) . "):\n";
    foreach ($staleInSpec as $row) echo "  - $row\n";
    echo "\n";
}

echo "Exit 1 — drift detected (warning only, CI nezablokuje).\n";
exit(1);
