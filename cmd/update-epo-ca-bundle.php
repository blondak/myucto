<?php

declare(strict_types=1);

/**
 * cmd/update-epo-ca-bundle.php
 *
 * Přestaví `api/resources/epo/epo-ca-bundle.pem` — trust store, proti kterému se ověřuje
 * pečeť dodejky z přímého (ZAREP) podání do EPO.
 *
 * Proč skript a ne ruční kopírování: certifikační autority certifikáty obměňují a bundle
 * je fail-closed. Až podřízená CA I.CA doslouží, ověření dodejek přestane procházet a
 * podaný výkaz skončí ve stavu „nejistý výsledek" — což se v ostrém provozu jednou už
 * stalo, jen z jiné příčiny. Proto `--check`, který se dá pustit z cronu, než to bolí.
 *
 * Pečeť EPO vydává I.CA (ověřeno na skutečné dodejce: CN=I.CA EU Qualified CA2/RSA
 * 06/2022, O=První certifikační autorita, a.s.). PostSignum je v bundlu jako pojistka
 * pro případ, že by GFŘ certifikát přeneslo k druhé české kvalifikované autoritě —
 * a protože jím bývají vydané podpisové certifikáty samotných účetních.
 *
 * Použití:
 *   php cmd/update-epo-ca-bundle.php            přestaví bundle ze zdrojů na webu CA
 *   php cmd/update-epo-ca-bundle.php --check    nic nezapíše; ohlásí drift a blížící se expirace
 *   php cmd/update-epo-ca-bundle.php --expiry-days=180
 *
 * Exit kódy: 0 = v pořádku, 1 = chyba stahování/zápisu, 2 = (jen `--check`) drift nebo
 * blížící se expirace — vhodné jako signál pro cron/CI.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

const TARGET = 'api/resources/epo/epo-ca-bundle.pem';

/** @var array<string, array<string, string>> Skupina => [popis => URL] */
const SOURCES = [
    'I.CA — kořeny (Prvni certifikacni autorita, a.s.)' => [
        'rca22_rsa.cer' => 'http://r.ica.cz/rca22_rsa.cer',
        'rca22_ecc.cer' => 'http://r.ica.cz/rca22_ecc.cer',
        'rca15_rsa.cer' => 'http://r.ica.cz/rca15_rsa.cer',
    ],
    'I.CA — kvalifikovane podrizene CA' => [
        '2qca22_rsa.cer' => 'http://q.ica.cz/2qca22_rsa.cer',
        '2qca22_ecc.cer' => 'http://q.ica.cz/2qca22_ecc.cer',
        '2qca16_rsa.cer' => 'http://q.ica.cz/2qca16_rsa.cer',
    ],
    'PostSignum — koreny (Ceska posta, s.p.) — pojistka' => [
        'postsignum_qca4_root.pem' => 'https://www.postsignum.cz/files/ca/postsignum_qca4_root.pem',
        'postsignum_root_eccr1.pem' => 'https://www.postsignum.cz/files/ca/postsignum_root_eccr1.pem',
    ],
    'PostSignum — kvalifikovane podrizene CA — pojistka' => [
        'postsignum_qca4_sub.pem' => 'https://www.postsignum.cz/files/ca/postsignum_qca4_sub.pem',
        'postsignum_qca5_sub.pem' => 'https://www.postsignum.cz/files/ca/postsignum_qca5_sub.pem',
        'postsignum_qca_eccr1ca1.pem' => 'https://www.postsignum.cz/files/ca/postsignum_qca_eccr1ca1.pem',
        'postsignum_qca_eccr1ca3.pem' => 'https://www.postsignum.cz/files/ca/postsignum_qca_eccr1ca3.pem',
    ],
];

$root = dirname(__DIR__);
$checkOnly = false;
$expiryDays = 365;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--check') {
        $checkOnly = true;
        continue;
    }
    if (str_starts_with($arg, '--expiry-days=')) {
        $expiryDays = max(1, (int) substr($arg, 14));
        continue;
    }
    fwrite(STDERR, "Neznámý argument: {$arg}\n");
    exit(1);
}

/** Stáhne URL; vrací tělo nebo null. */
function fetch(string $url): ?string
{
    $context = stream_context_create([
        'http' => ['timeout' => 30, 'user_agent' => 'MyUcto-EPO-CA-Updater/1.0', 'follow_location' => 1],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $context);
    return is_string($body) && $body !== '' ? $body : null;
}

/** DER i PEM na PEM; null, když to není certifikát. */
function toPem(string $raw): ?string
{
    if (str_contains($raw, '-----BEGIN CERTIFICATE-----')) {
        if (!preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $raw, $m)) {
            return null;
        }
        $pem = rtrim($m[0]) . "\n";
    } else {
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($raw), 64, "\n") . "-----END CERTIFICATE-----\n";
    }
    return is_array(@openssl_x509_parse($pem, false)) ? $pem : null;
}

function ascii(string $value): string
{
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
    return is_string($t) ? $t : $value;
}

/**
 * Hodnota z DN. `openssl_x509_parse()` u certifikátů s `organizationIdentifier`
 * (což české kvalifikované CA mají) `subject` nenaplní, takže se jako záloha čte
 * ze zploštělého `name` — bez toho by se v protokolu tiskly samé otazníky.
 */
function dnValue(array $parsed, string $key): string
{
    $value = $parsed['subject'][$key] ?? null;
    if (is_array($value)) {
        $value = reset($value);
    }
    if (is_scalar($value) && (string) $value !== '') {
        return (string) $value;
    }
    // `name` je DN zploštělé lomítky, takže lomítko UVNITŘ hodnoty je escapované
    // („I.CA Root CA\/RSA 05\/2022"). Naivní `[^/]+` by název usekl v půlce.
    $name = (string) ($parsed['name'] ?? '');
    if ($name !== '' && preg_match('#/' . preg_quote($key, '#') . '=((?:[^/\\\\]|\\\\.)+)#', $name, $m)) {
        return str_replace('\\/', '/', $m[1]);
    }
    return '?';
}

/** @return list<string> otisky v souboru */
function fingerprintsOf(string $pem): array
{
    if (!preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $m)) {
        return [];
    }
    $out = [];
    foreach ($m[0] as $certificate) {
        $fp = @openssl_x509_fingerprint($certificate, 'sha256');
        if (is_string($fp)) {
            $out[] = strtolower(str_replace(':', '', $fp));
        }
    }
    sort($out);
    return $out;
}

echo "Zdroje: I.CA (r.ica.cz, q.ica.cz), PostSignum (postsignum.cz)\n\n";

$lines = [
    '# EPO / Danovy portal - CA bundle pro overeni dodejky (P7S)',
    '# Pouziti: cfg.php => epo.ca_bundle_path (vychozi hodnota, viz Config::baselineDefaults)',
    '# Generuje: cmd/update-epo-ca-bundle.php - RUCNE NEEDITOVAT',
    '# Sestaveno: ' . date('Y-m-d'),
    '#',
    '# Pecet EPO vydava I.CA - overeno na skutecne dodejce z ostreho provozu:',
    '#   issuer = CN=I.CA EU Qualified CA2/RSA 06/2022, O=Prvni certifikacni autorita, a.s.',
    '',
];

$seen = [];
$failures = [];
$expiring = [];
$skippedExpired = [];
$now = time();
$count = 0;

foreach (SOURCES as $group => $urls) {
    $lines[] = '# ' . str_repeat('=', 68);
    $lines[] = '# ' . ascii($group);
    $lines[] = '# ' . str_repeat('=', 68);
    $lines[] = '';

    foreach ($urls as $name => $url) {
        $raw = fetch($url);
        if ($raw === null) {
            $failures[] = $name . ' (' . $url . ')';
            fwrite(STDERR, "  ! nelze stáhnout: {$url}\n");
            continue;
        }
        $pem = toPem($raw);
        if ($pem === null) {
            $failures[] = $name . ' (neplatný certifikát)';
            fwrite(STDERR, "  ! není certifikát: {$url}\n");
            continue;
        }
        $parsed = (array) openssl_x509_parse($pem, false);
        $fingerprint = strtolower(str_replace(':', '', (string) openssl_x509_fingerprint($pem, 'sha256')));
        if (isset($seen[$fingerprint])) {
            continue;
        }
        $seen[$fingerprint] = true;
        ++$count;

        $validTo = (int) ($parsed['validTo_time_t'] ?? 0);
        $daysLeft = (int) floor(($validTo - $now) / 86400);
        $cn = ascii(dnValue($parsed, 'CN'));
        $o = ascii(dnValue($parsed, 'O'));

        // Vypršelou kotvu do trust storu nepatří dávat: OpenSSL po ní stejně řetězec
        // neuzná, jen by natrvalo držela varování o expiraci a odnaučila lidi ho číst.
        // Archivní ověření staré dodejky se opře o certifikát pečeti, který se dnes
        // ukládá k podání (`confirmation_signer_cert`).
        if ($validTo > 0 && $daysLeft < 0) {
            $skippedExpired[] = sprintf('%s (%s), vypršel %s', $cn, $name, date('Y-m-d', $validTo));
            printf("  %-28s %-46s VYPRŠEL %s — vynechán\n", $name, substr($cn, 0, 46), date('Y-m-d', $validTo));
            unset($seen[$fingerprint]);
            --$count;
            continue;
        }

        printf("  %-28s %-46s do %s (%d dní)\n", $name, substr($cn, 0, 46), date('Y-m-d', $validTo), $daysLeft);
        if ($daysLeft <= $expiryDays) {
            $expiring[] = sprintf('%s (%s) vyprší %s, zbývá %d dní', $cn, $name, date('Y-m-d', $validTo), $daysLeft);
        }

        $lines[] = '# subject : CN=' . $cn . ', O=' . $o;
        $lines[] = '# valid   : ' . date('Y-m-d', (int) ($parsed['validFrom_time_t'] ?? 0)) . ' .. ' . date('Y-m-d', $validTo);
        $lines[] = '# sha256  : ' . $fingerprint;
        $lines[] = '# zdroj   : ' . $url;
        $lines[] = rtrim($pem);
        $lines[] = '';
    }
}

if ($count === 0) {
    fwrite(STDERR, "\nNestáhl se ani jeden certifikát — bundle se NEPŘEPISUJE.\n");
    exit(1);
}

$bundle = implode("\n", $lines);
$target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, TARGET);
$current = is_file($target) ? (string) file_get_contents($target) : '';

$before = fingerprintsOf($current);
$after = fingerprintsOf($bundle);
$added = array_diff($after, $before);
$removed = array_diff($before, $after);

echo "\ncertifikátů v novém bundlu: {$count}\n";
if ($added !== []) {
    echo "  přibylo: " . implode(', ', array_map(static fn (string $f): string => substr($f, 0, 16), $added)) . "\n";
}
if ($removed !== []) {
    echo "  ubylo  : " . implode(', ', array_map(static fn (string $f): string => substr($f, 0, 16), $removed)) . "\n";
}
if ($added === [] && $removed === []) {
    echo "  beze změny proti nasazenému bundlu\n";
}

if ($failures !== []) {
    fwrite(STDERR, "\nNestažené zdroje (bundle je neúplný):\n  - " . implode("\n  - ", $failures) . "\n");
}
if ($expiring !== []) {
    fwrite(STDERR, "\nBlíží se expirace (práh {$expiryDays} dní):\n  - " . implode("\n  - ", $expiring) . "\n");
}

if ($checkOnly) {
    echo "\n--check: nic se nezapisuje.\n";
    exit(($added !== [] || $removed !== [] || $expiring !== [] || $failures !== []) ? 2 : 0);
}

// Neúplný bundle nikdy nepřepíše funkční — fail-closed trust store by tím oslepl.
if ($failures !== []) {
    fwrite(STDERR, "\nBundle se NEPŘEPISUJE, dokud se nestáhnou všechny zdroje.\n");
    exit(1);
}

$dir = dirname($target);
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    fwrite(STDERR, "Nelze založit adresář {$dir}\n");
    exit(1);
}
if (file_put_contents($target, $bundle) === false) {
    fwrite(STDERR, "Nelze zapsat {$target}\n");
    exit(1);
}

echo "\nzapsáno: " . TARGET . ' (' . strlen($bundle) . " B)\n";
echo "Zkontroluj `git diff` a commitni.\n";
exit(0);
