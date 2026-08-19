<?php
/**
 * Smoke test webroot blokací (SEC-02 / SEC-06).
 *
 * Ověřuje proti BĚŽÍCÍ instanci, že citlivé cesty nejsou dostupné — nezávisle na
 * tom, jestli za tím stojí IIS (web.config), Apache (.htaccess) nebo nginx
 * (docker/nginx.conf). Všechny tři konfigurace mají držet stejnou sadu pravidel,
 * takže tenhle skript je jejich společná kontrola.
 *
 * Použití:
 *   php tools/securitySmokeTest.php                      # http://localhost
 *   php tools/securitySmokeTest.php https://dev.myucto.cz
 *
 * Exit kód 0 = vše OK, 1 = alespoň jedna cesta je vystavená (nebo veřejná cesta spadla).
 *
 * ⚠️ Nepouštět proti produkci bez domluvy — generuje to desítky 403 v access logu.
 *
 * Pozn.: seznam níže je průnik toho, co zvládnou VŠECHNY tři konfigurace. nginx
 * navíc odmítá i přímý `/api/public/index.php` a `/manual/generated/_toc.php`
 * (PHP allowlist), na IIS/Apache to 1:1 přenést nejde — blokace kořenové cesty
 * rewritu by shodila front controller, protože oba servery po rewritu pravidla
 * vyhodnocují znovu. Proto tyhle dvě cesty v testu nejsou.
 */

$base = rtrim($argv[1] ?? 'http://localhost', '/');

// Cesty, které MUSÍ být odepřené. Povolený výsledek je 403 nebo 404 — server
// smí existenci buď přiznat (403), nebo zapřít (404); obojí je fail-closed.
// Naopak 200 (obsah), 301/302 na obsah nebo 500 (= PHP se spustilo) = průšvih.
$mustDeny = [
    // VCS metadata — .git/config a .git/HEAD stačí na rekonstrukci celého repa
    '/.git/HEAD',
    '/.git/config',
    '/.gitignore',
    '/web/.git/HEAD',
    // Konfigurace a secrety
    '/.env',
    '/cfg.php',
    '/cfg.local.php',
    // Vývojové a interní složky
    '/cmd/',
    '/cmd/cron-backup.sh',
    '/docker/',
    '/docker/nginx.conf',
    '/tools/securitySmokeTest.php',
    '/private/',
    '/storage/',
    '/log/',
    '/db/',
    '/web/shared/client-route-policy.json',
    '/web/src/',
    '/web/src/main.ts',
    '/web/node_modules/',
    // Backend, který nesmí ven jako statika
    '/api/src/',
    '/api/vendor/autoload.php',
    '/api/templates/',
    '/api/bin/cron-backup.php',
    // Produkční autoloader — `api/vendor.prod/` reálně existuje na disku a holé
    // `vendor(/|$)` ho nematchlo (dostane '.', ne '/'). installed.json = kompletní
    // soupis závislostí a verzí, *.php by se navíc předalo PHP handleru.
    '/api/vendor.prod/autoload.php',
    '/api/vendor.prod/composer/installed.json',
    // Tooling manifesty a zdrojové dokumenty
    '/composer.json',
    '/composer.lock',
    // ...i pod /api — root-kotvený regex je dřív minul
    '/api/composer.json',
    '/api/composer.lock',
    '/api/phpunit.xml',
    '/package.json',
    '/web/package.json',
    '/web/pnpm-lock.yaml',
    '/web/vite.config.ts',
    '/web/tsconfig.json',
    '/Dockerfile',
    '/docker-compose.yml',
    '/README.md',
    '/AGENTS.md',
];

// Cesty, které naopak MUSÍ zůstat dostupné — pojistka, že jsme blokacemi
// nerozbili aplikaci. Očekáváme 2xx (u /api/health i 4xx z aplikace by znamenalo,
// že PHP běží, ale radši trváme na 200, ať to chytí i rozbitý routing).
// Manuál je tu schválně: blokace .md a rozšířené složkové regexy jdou hodně
// blízko k /manual a jeho statice, takže právě tady hrozí, že novou blokací
// omylem shodíme veřejnou funkčnost. /manual i /manual/ jsou dvě různé cesty
// (pretty URL přes index.php), obě musí projít.
$mustAllow = [
    '/'                                     => [200],
    '/api/openapi.yaml'                     => [200],
    '/api/health'                           => [200],
    '/manual'                               => [200],
    '/manual/'                              => [200],
    '/manual/generated/search-index.json'   => [200],
];

/**
 * Vrátí HTTP status kód, nebo null když se spojení vůbec nepovedlo.
 * `ignore_errors` je nutné, jinak file_get_contents na 4xx/5xx vrátí false
 * a status bychom z $http_response_header nepřečetli.
 */
function statusOf(string $url): ?int
{
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => 10,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'header'          => "User-Agent: myucto-security-smoke-test\r\n",
        ],
        'ssl' => [
            // Dev prostředí běží často na self-signed certu; test řeší blokace,
            // ne validitu TLS.
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);

    @file_get_contents($url, false, $ctx);
    $headers = $http_response_header ?? null;

    if (!$headers) {
        return null;
    }
    if (!preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0], $m)) {
        return null;
    }

    return (int) $m[1];
}

$failed = 0;
$checked = 0;

echo "Smoke test blokací proti: $base\n\n";

foreach ($mustDeny as $path) {
    $checked++;
    $code = statusOf($base . $path);
    $ok = in_array($code, [403, 404], true);
    if (!$ok) {
        $failed++;
    }
    printf("%s  %-40s %s\n", $ok ? 'OK  ' : 'FAIL', $path, $code === null ? 'bez odpovědi' : (string) $code);
}

echo "\n";

foreach ($mustAllow as $path => $expected) {
    $checked++;
    $code = statusOf($base . $path);
    $ok = in_array($code, $expected, true);
    if (!$ok) {
        $failed++;
    }
    printf("%s  %-40s %s (očekáváno %s)\n", $ok ? 'OK  ' : 'FAIL', $path, $code === null ? 'bez odpovědi' : (string) $code, implode('/', $expected));
}

echo "\n";
if ($failed > 0) {
    fwrite(STDERR, "Selhalo $failed z $checked kontrol.\n");
    exit(1);
}

echo "Všech $checked kontrol prošlo.\n";
exit(0);
