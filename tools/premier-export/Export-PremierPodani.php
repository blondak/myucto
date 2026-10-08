<?php

declare(strict_types=1);

/**
 * Výpis odeslaných podání ze zálohy PREMIER (.icab / .izip / složka s tabulkami .dbf) do korpusu.
 *
 *   php Export-PremierPodani.php --source <icab|izip|složka> --out <korpus> [--xsd <api\xsd>] [--work <tmp>]
 *
 * PREMIER ukládá každé odeslané podání doslova do tabulky MZ_VREP (memo POZNAMKA = odeslaný obsah,
 * POZNAMKA2 = odpověď příjemce včetně protokolu) a věty v tabulkách MZ_JMHZ*, MZ_ELDP, MZ_NEMPRI,
 * MZ_HDPN, MZ_PRIZP, MZ_PRISO se na ně odkazují sloupcem ID_ZPRAVY (= MZ_VREP.ID). XML se proto
 * nerekonstruuje, jen se vyjme beze změny a zvaliduje proti XSD připnutým v api/xsd.
 *
 * Výstup: raw/*.json, xml/<typ>/, text/<typ>/, response/<typ>/, receipts/<typ>/, index.csv, links.csv,
 * xml/VALIDATION.md, raw/_meta.json. V kódu nejsou žádná data klienta.
 */

require __DIR__ . '/PremierRawDbf.php';

const TABLES = [
    'MZ_VREP', 'MZ_JMHZ', 'MZ_JMHZ2', 'MZ_JMHZ3', 'MZ_JMHZ4', 'MZ_JMHZ5', 'MZ_JMHZ6',
    'MZ_ELDP', 'MZ_ELPOL', 'MZ_NEMPRI', 'MZ_HDPN', 'MZ_HDPNP', 'MZ_PRIZP', 'MZ_PRISO', 'MZ_ISPV',
    'MZ_DUSP', 'MZ_DUSPH', 'MZ_DANZA', 'MZ_DANZ4', 'MZ_DANSR', 'ZAZNAMY', 'DS_PREM', 'DS_PREMP', 'PERSONAL',
];

/** Typ zprávy (MZ_VREP.TYP_ZPRAVY) => [oblast, prvek pro počet vět]; neuvedené = oblast "jine". */
const TYPE_SCOPE = [
    'JMHZ25' => ['payroll', 'jmhz'], 'PVPOJ23' => ['payroll', null], 'PVPOJ25' => ['payroll', null],
    'REGZEC25' => ['payroll', 'employee'], 'PREZEC26' => ['payroll', 'employee'], 'ONZ22' => ['payroll', 'employee'],
    'ELDP09' => ['payroll', 'eldp09'], 'NEMPRI20' => ['payroll', 'datovaVeta'], 'NEMPRI25' => ['payroll', 'datovaVeta'],
    'HZUPN20' => ['payroll', 'FormularHZUPN'], 'VPDPP24' => ['payroll', 'formularVpdpp'],
    'DZMH25' => ['query', null], 'DZNP25' => ['query', null], 'DZDPN20-V2' => ['query', null],
    'HOZ' => ['health', 'oznameniZamestnavatele'], 'PPPZ' => ['health', null], 'PPZ' => ['health', null],
    'POTVRZENI-PRIJMU' => ['payroll', null],
    'DPZVD6' => ['tax', null], 'DPSVD2' => ['tax', null], 'DPHDP3' => ['vat', null], 'DPHKH1' => ['vat', null],
];

/** root|namespace => XSD relativně k adresáři xsd. */
const XSD_BY_ROOT = [
    'jmhz|http://schemas.cssz.cz/JMHZ/podani/1.0' => 'jmhz/jmhz-1.4.3.6/jmhzPodani.xsd',
    'REGZEC|http://schemas.cssz.cz/REGZEC/2025' => 'jmhz/regzec-1.4.0.4/REGZEC25.xsd',
    'PREZEC|http://schemas.cssz.cz/PREZEC/2026' => 'jmhz/prezec-1.2/PREZEC26 1.2.xsd',
    'NEMPRI|http://schemas.cssz.cz/nem/NEMPRI25' => 'cssz/nempri25-1.0/NEMPRI25.xsd',
    'PodaniHZUPN|http://schemas.cssz.cz/nem/HZUPN20' => 'cssz/hzupn20-1.2/HZUPN20 v1.2.xsd',
    'DZMH|http://schemas.cssz.cz/JMHZ/dotazNaStav/2025' => 'jmhz/dzmh-1.1/DZMH25.xsd',
    'Pisemnost|DPZVD6' => 'dpzvd6.xsd',
    'Pisemnost|DPSVD2' => 'dpsvd2.xsd',
    'Pisemnost|DPHDP3' => 'dphdp3.xsd',
];

function arg(string $name, ?string $default = null): ?string
{
    global $argv;
    foreach ($argv as $i => $a) {
        if ($a === '--' . $name && isset($argv[$i + 1])) {
            return $argv[$i + 1];
        }
    }
    return $default;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function ensureDir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fail('Nelze vytvořit ' . $dir);
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/** Vrátí složku s tabulkami; archiv rozbalí do $work. */
function prepareSource(string $source, string $work): string
{
    if (is_dir($source)) {
        return $source;
    }
    if (!is_file($source)) {
        fail('Zdroj neexistuje: ' . $source);
    }
    rrmdir($work);
    ensureDir($work);
    $sig = (string) file_get_contents($source, false, null, 0, 4);
    if ($sig === 'MSCF') {
        $repo = dirname(__DIR__, 2) . '/api/src/Service/Migration/Premier';
        require_once $repo . '/PremierException.php';
        require_once $repo . '/Dbf/CabinetExtractor.php';
        $cab = new MyInvoice\Service\Migration\Premier\Dbf\CabinetExtractor($source);
        $cab->extract($work, static fn (string $n): ?string => basename($n), 4 * 1024 * 1024 * 1024);
        return $work;
    }
    if (str_starts_with($sig, 'PK')) {
        $zip = new ZipArchive();
        if ($zip->open($source) !== true) {
            fail('Archiv nejde otevřít.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '/')) {
                continue;
            }
            file_put_contents($work . '/' . basename($name), (string) $zip->getFromIndex($i));
        }
        $zip->close();
        return $work;
    }
    fail('Neznámý formát zdroje (čeká se CAB, ZIP nebo složka).');
}

function openTable(string $dir, string $name): ?PremierRawDbf
{
    foreach (glob($dir . '/*') ?: [] as $p) {
        if (strcasecmp(basename($p), $name . '.dbf') === 0) {
            return new PremierRawDbf($p);
        }
    }
    return null;
}

function jsonWrite(string $path, mixed $data): void
{
    ensureDir(dirname($path));
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
}

function csvLine(array $cells): string
{
    return implode(',', array_map(static function ($c): string {
        $c = (string) $c;
        return preg_match('/[",\r\n]/', $c) === 1 ? '"' . str_replace('"', '""', $c) . '"' : $c;
    }, $cells));
}

function safeName(string $s): string
{
    $s = preg_replace('/[^A-Za-z0-9._-]+/', '_', $s) ?? '';
    return trim($s, '_') !== '' ? trim($s, '_') : 'x';
}

/** Zpráva validátoru bez hodnot a jmenných prostorů (aby šly sčítat stejné chyby a nenesly osobní údaje). */
function normalizeError(string $m): string
{
    $m = preg_replace('/\{[^}]*\}/', '', $m) ?? $m;
    $m = preg_replace("/'[^']*'/", "'…'", $m) ?? $m;
    $m = preg_replace('/\[facet[^\]]*\]/', '', $m) ?? $m;
    $m = preg_replace('/\s+/', ' ', $m) ?? $m;
    return trim(mb_substr($m, 0, 220));
}

/** @return array{0:string,1:string,2:?DOMDocument} [root, ns, dom] */
function parseXml(string $xml): array
{
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = @$dom->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok || $dom->documentElement === null) {
        return ['', '', null];
    }
    return [$dom->documentElement->localName, (string) $dom->documentElement->namespaceURI, $dom];
}

function countSentences(?DOMDocument $dom, ?string $element): ?int
{
    if ($dom === null || $element === null) {
        return null;
    }
    if ($element === 'jmhz') {
        $n = $dom->getElementsByTagNameNS('*', 'formulareOsob');
        if ($n->length === 0) {
            return 0;
        }
        $c = 0;
        foreach ($n->item(0)->childNodes as $ch) {
            $c += $ch->nodeType === XML_ELEMENT_NODE ? 1 : 0;
        }
        return $c;
    }
    return $dom->getElementsByTagNameNS('*', $element)->length;
}

/** Období z obsahu podání, když ho tabulka nenese. */
function periodFromPayload(?DOMDocument $dom, string $type): string
{
    if ($dom === null) {
        return '';
    }
    $text = static function (string $tag) use ($dom): string {
        $n = $dom->getElementsByTagNameNS('*', $tag);
        return $n->length > 0 ? trim($n->item(0)->textContent) : '';
    };
    if ($type === 'JMHZ25') {
        $y = $text('rok');
        $m = $text('mesic');
        return preg_match('/^\d{4}$/', $y) === 1 && is_numeric($m) ? sprintf('%s-%02d', $y, (int) $m) : '';
    }
    if (str_starts_with($type, 'PVPOJ')) {
        $y = $text('rok');
        $m = $text('mesic');
        return preg_match('/^\d{4}$/', $y) === 1 && is_numeric($m) ? sprintf('%s-%02d', $y, (int) $m) : '';
    }
    if ($type === 'VPDPP24') {
        $y = $text('obdobiRok');
        $m = $text('obdobiMesic');
        return preg_match('/^\d{4}$/', $y) === 1 && is_numeric($m) ? sprintf('%s-%02d', $y, (int) $m) : '';
    }
    if ($type === 'REGZEC25' || $type === 'PREZEC26' || $type === 'ONZ22') {
        $dates = [];
        foreach ($dom->getElementsByTagNameNS('*', 'employee') as $e) {
            if (preg_match('/^(\d{4}-\d{2})-\d{2}$/', $e->getAttribute('dat'), $m) === 1) {
                $dates[] = $m[1];
            }
        }
        sort($dates);
        return $dates[0] ?? '';
    }
    if ($type === 'HZUPN20') {
        return preg_match('/^(\d{4}-\d{2})/', $text('datumVystaveni'), $m) === 1 ? $m[1] : '';
    }
    if ($type === 'ELDP09') {
        $n = $dom->getElementsByTagNameNS('*', 'eldp09');
        $y = $n->length > 0 ? $n->item(0)->getAttribute('yer') : '';
        return preg_match('/^\d{4}$/', $y) === 1 ? $y : '';
    }
    return '';
}

/** @return array{0:?string,1:string} [relativní XSD nebo null, poznámka] */
function chooseXsd(string $root, string $ns, ?DOMDocument $dom): array
{
    $key = $root . '|' . $ns;
    if (isset(XSD_BY_ROOT[$key])) {
        return [XSD_BY_ROOT[$key], ''];
    }
    if ($root === 'pvpoj') {
        return [null, 'samostatný PVPOJ (' . $ns . ') nemá připnuté XSD; jmhz/PVPOJ.xsd je jiný jmenný prostor, vložený do JMHZ'];
    }
    if ($root === 'Pisemnost' && $dom !== null) {
        foreach ($dom->documentElement->childNodes as $ch) {
            if ($ch->nodeType === XML_ELEMENT_NODE && isset(XSD_BY_ROOT['Pisemnost|' . $ch->localName])) {
                return [XSD_BY_ROOT['Pisemnost|' . $ch->localName], ''];
            }
        }
    }
    return [null, 'v api/xsd není připnuté XSD pro ' . $root . ($ns !== '' ? ' (' . $ns . ')' : '')];
}

/** @return array{0:bool,1:list<string>} */
function validateDom(DOMDocument $dom, string $xsdFile): array
{
    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $ok = @$dom->schemaValidate($xsdFile);
    $errors = [];
    foreach (libxml_get_errors() as $e) {
        $errors[] = normalizeError($e->message);
    }
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return [(bool) $ok, $errors];
}

/** Vnořené base64 dokumenty z odpovědi (protokoly, potvrzení): jen to, co po dekódování začíná %PDF. */
function embeddedPdfs(string $response): array
{
    $found = [];
    if (preg_match_all('/(?:&lt;|<)obsah(?:&gt;|>)([A-Za-z0-9+\/=\s]{200,})(?:&lt;|<)\/obsah/', $response, $m) > 0) {
        foreach ($m[1] as $b64) {
            $bin = base64_decode(preg_replace('/\s+/', '', $b64) ?? '', true);
            if ($bin !== false && str_starts_with($bin, '%PDF')) {
                $found[] = $bin;
            }
        }
    }
    return $found;
}

/** Přijetí z odpovědi ČSSZ (<accepted>); '' = odpověď tento tvar nemá. */
function acceptedFlag(string $response): string
{
    return preg_match('/<accepted>\s*(True|False)\s*<\/accepted>/i', $response, $m) === 1 ? strtolower($m[1]) : '';
}

// -------------------------------------------------------------------------------------------------

$source = arg('source') ?? fail('Chybí --source');
$out = rtrim(arg('out') ?? fail('Chybí --out'), '\\/');
$xsdRoot = rtrim(arg('xsd', dirname(__DIR__, 2) . '/api/xsd'), '\\/');
$work = arg('work', sys_get_temp_dir() . '/premier-export-' . getmypid());
$firm = arg('firm', '');

$dir = prepareSource($source, $work);
ensureDir($out);

$meta = [
    'source_file' => basename($source),
    'source_sha256' => is_file($source) ? hash_file('sha256', $source) : null,
    'source_size' => is_file($source) ? filesize($source) : null,
    'exported_at' => date('Y-m-d H:i:s'),
    'xsd_root' => 'api/xsd',
    'tables' => [],
];

// --- surové tabulky ---
$cache = [];
foreach (TABLES as $name) {
    $t = openTable($dir, $name);
    if ($t === null) {
        $meta['tables'][$name] = ['present' => false];
        continue;
    }
    $rows = [];
    $bodies = $name === 'MZ_VREP';
    foreach ($t->rows($bodies) as $r) {
        if ($name === 'ZAZNAMY') {
            $r['ZAZNAM'] = null; // binární archiv sestavy, ne podání
        }
        $rows[] = $r;
    }
    $cache[$name] = $rows;
    $meta['tables'][$name] = ['present' => true, 'rows' => count($rows), 'fields' => count($t->fieldNames())];
}

// --- osoby: INTER => osobní číslo (jen identifikátor vztahu, bez jmen) ---
$personNo = [];
foreach ($cache['PERSONAL'] ?? [] as $r) {
    $personNo[(int) ($r['INTER'] ?? 0)] = (string) ($r['CISLO'] ?? '');
}

// --- podání z MZ_VREP ---
$vrep = $cache['MZ_VREP'] ?? [];
$vrepMeta = [];
$index = [];
$validation = [];
$errorCounts = [];
$toolDecoder = openTable($dir, 'MZ_VREP');
$byId = [];

foreach ($vrep as $row) {
    $id = strtoupper((string) ($row['ID'] ?? ''));
    $type = (string) ($row['TYP_ZPRAVY'] ?? '');
    if (str_contains($type, 'příjem')) {
        $type = 'POTVRZENI-PRIJMU';
    }
    $typeDir = safeName($type !== '' ? $type : 'bez-typu');
    [$scope] = TYPE_SCOPE[$type] ?? ['jine', null];
    $sentElement = TYPE_SCOPE[$type][1] ?? null;
    $rawReq = (string) ($row['POZNAMKA'] ?? '');
    $rawResp = (string) ($row['POZNAMKA2'] ?? '');
    $sent = (string) ($row['DAT_ZPRAVY'] ?? '');
    $period = (isset($row['ROK'], $row['MESIC']) && (int) $row['ROK'] > 0 && (int) $row['MESIC'] > 0)
        ? sprintf('%04d-%02d', (int) $row['ROK'], (int) $row['MESIC']) : '';
    $stem0 = $type . '_' . substr($id, 0, 8);

    // odeslaný obsah
    $reqUtf = $toolDecoder->toUtf8($rawReq);
    $strip = false;
    if (preg_match('/^(\?|\x{FEFF})(?=\s*<\?xml)/u', $reqUtf) === 1) {
        $reqUtf = (string) preg_replace('/^(\?|\x{FEFF})/u', '', $reqUtf);
        $strip = true;
    }
    $isXml = preg_match('/^\s*<\?xml|^\s*<[A-Za-z]/', $reqUtf) === 1;
    $xmlRel = '';
    $textRel = '';
    $root = '';
    $ns = '';
    $dom = null;
    $payloadSha = $rawReq === '' ? '' : hash('sha256', $rawReq);
    $declEnc = '';
    if ($isXml) {
        [$root, $ns, $dom] = parseXml($reqUtf);
        if (preg_match('/^\s*<\?xml[^>]*encoding="([^"]+)"/i', $reqUtf, $m) === 1) {
            $declEnc = $m[1];
        }
        if ($period === '') {
            $period = periodFromPayload($dom, $type);
        }
    }
    if ($period === '' && str_starts_with($type, 'NEMPRI')
        && preg_match('/_(20\d{2})(0[1-9]|1[0-2])_/', (string) ($row['SOUBOR'] ?? ''), $m) === 1) {
        $period = $m[1] . '-' . $m[2];
    }
    $stem = ($period !== '' ? $period : 'bez-obdobi') . '_' . $stem0;

    if ($rawReq !== '') {
        if ($isXml) {
            $xmlRel = 'xml/' . $typeDir . '/' . $stem . '.xml';
            ensureDir($out . '/xml/' . $typeDir);
            file_put_contents($out . '/' . $xmlRel, $reqUtf);
        } else {
            // netextový obsah (soubory zdravotních pojišťoven) beze změny kódování (DOS, ne Windows-1250)
            $textRel = 'text/' . $typeDir . '/' . $stem . '.txt';
            ensureDir($out . '/text/' . $typeDir);
            file_put_contents($out . '/' . $textRel, $rawReq);
        }
    }

    // odpověď
    $respRel = '';
    $receipts = 0;
    if ($rawResp !== '') {
        $respUtf = $toolDecoder->toUtf8($rawResp);
        $respRel = 'response/' . $typeDir . '/' . $stem . '.txt';
        ensureDir($out . '/response/' . $typeDir);
        file_put_contents($out . '/' . $respRel, $respUtf);
        foreach (embeddedPdfs($respUtf) as $k => $bin) {
            ensureDir($out . '/receipts/' . $typeDir);
            file_put_contents($out . '/receipts/' . $typeDir . '/' . $stem . ($k > 0 ? '_' . ($k + 1) : '') . '.pdf', $bin);
            $receipts++;
        }
    }
    $accepted = acceptedFlag($rawResp !== '' ? $respUtf : '');
    $rejection = '';
    if ($rawResp !== '' && preg_match('/<errorText>([^<]+)<\/errorText>/', $respUtf, $m) === 1) {
        $rejection = trim((string) preg_replace('/\s+/', ' ', html_entity_decode($m[1])));
        $rejection = mb_substr($rejection, 0, 400);
    }

    // validace
    $xsdRel = '';
    $valid = '';
    $note = '';
    if ($xmlRel !== '') {
        if ($dom === null) {
            $valid = 'nevalidni-xml';
            $note = 'XML se nepodařilo načíst';
        } else {
            [$xsdRelOrNull, $why] = chooseXsd($root, $ns, $dom);
            if ($xsdRelOrNull === null) {
                $valid = 'bez-xsd';
                $note = $why;
            } else {
                $xsdRel = $xsdRelOrNull;
                $xsdFile = $xsdRoot . '/' . $xsdRel;
                if (!is_file($xsdFile)) {
                    $valid = 'bez-xsd';
                    $note = 'chybí soubor ' . $xsdRel;
                } else {
                    [$ok, $errs] = validateDom($dom, $xsdFile);
                    $valid = $ok ? 'ok' : 'chyba';
                    $counts = array_count_values($errs);
                    $note = $ok ? '' : implode(' | ', array_slice(array_keys($counts), 0, 3));
                    if (!$ok) {
                        foreach ($counts as $msg => $c) {
                            $errorCounts[$type][$msg] = ($errorCounts[$type][$msg] ?? 0) + $c;
                        }
                    }
                }
            }
        }
    } elseif ($textRel !== '') {
        $valid = 'nexml';
        $note = 'netextový obsah (soubor pro zdravotní pojišťovnu)';
    }
    if ($strip) {
        $note = trim($note . ' [odstraněn úvodní "?" za BOM]');
    }
    if ($declEnc !== '' && strcasecmp($declEnc, 'utf-8') !== 0) {
        $note = trim($note . ' [deklarované kódování ' . $declEnc . ']');
    }

    $sentences = countSentences($dom, $sentElement);
    $vrepMeta[] = [
        'ID' => $id, 'TYP_ZPRAVY' => $type, 'ORG' => $row['ORG'] ?? null, 'ORG2' => $row['ORG2'] ?? null,
        'SOUBOR' => $row['SOUBOR'] ?? null, 'STAV' => $row['STAV'] ?? null, 'STAV_ZPR' => $row['STAV_ZPR'] ?? null,
        'DAT_ZPRAVY' => $sent, 'MESIC' => $row['MESIC'] ?? null, 'ROK' => $row['ROK'] ?? null,
        'DAVKA_CI' => $row['DAVKA_CI'] ?? null, 'VS' => $row['VS'] ?? null, 'ID_TRAN' => $row['ID_TRAN'] ?? null,
        'ID_PASS' => $row['ID_PASS'] ?? null, 'ID_ZDR' => $row['ID_ZDR'] ?? null, 'OZN_CHYBY' => $row['OZN_CHYBY'] ?? null,
        'FROM_DS' => $row['FROM_DS'] ?? null, 'TS' => $row['TS'] ?? null,
        'request_file' => $xmlRel !== '' ? $xmlRel : $textRel, 'request_bytes' => strlen($rawReq), 'request_sha256' => $payloadSha,
        'response_file' => $respRel, 'response_bytes' => strlen($rawResp), 'receipts_pdf' => $receipts,
    ];
    $byId[$id] = count($index);
    $index[] = [
        'firm' => $firm, 'source' => 'premier-vrep', 'vrep_id' => $id, 'type' => $type, 'org' => (string) ($row['ORG'] ?? ''), 'scope' => $scope,
        'period' => $period, 'sent_at' => $sent, 'status_code' => (string) ($row['STAV'] ?? ''),
        'status_text' => (string) ($row['STAV_ZPR'] ?? ''), 'accepted' => $accepted, 'response_error' => $rejection, 'file_name' => (string) ($row['SOUBOR'] ?? ''),
        'sentences' => $sentences === null ? '' : (string) $sentences, 'xml_path' => $xmlRel !== '' ? $xmlRel : $textRel,
        'response_path' => $respRel, 'receipts' => (string) $receipts, 'xsd' => $xsdRel, 'validation' => $valid,
        'validation_note' => $note, 'bytes' => (string) strlen($rawReq), 'sha256' => $payloadSha, 'linked_records' => '',
    ];
}

// --- volitelně hotová XML podání z jiného zdroje (např. soubory vygenerované PREMIEREM a podané ručně) ---
$extraDir = arg('extra-xml');
if ($extraDir !== null && is_dir($extraDir)) {
    foreach (glob(rtrim($extraDir, '\\/') . '/*.xml') ?: [] as $file) {
        $bytes = (string) file_get_contents($file);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $bytes) ?? $bytes;
        [$root, $ns, $dom] = parseXml($text);
        $type = $root === 'jmhz' ? 'JMHZ25' : strtoupper($root !== '' ? $root : 'NEZNAMY');
        [$scope, $sentElement] = TYPE_SCOPE[$type] ?? ['jine', null];
        $period = periodFromPayload($dom, $type);
        $sha = hash('sha256', $bytes);
        $rel = 'xml/' . safeName($type) . '/' . ($period !== '' ? $period : 'bez-obdobi') . '_' . $type . '_soubor_' . substr($sha, 0, 8) . '.xml';
        ensureDir($out . '/xml/' . safeName($type));
        file_put_contents($out . '/' . $rel, $bytes);
        $xsdRel = '';
        $valid = $dom === null ? 'nevalidni-xml' : 'bez-xsd';
        $note = '';
        if ($dom !== null) {
            [$xsdRelOrNull, $why] = chooseXsd($root, $ns, $dom);
            $note = $why;
            if ($xsdRelOrNull !== null && is_file($xsdRoot . '/' . $xsdRelOrNull)) {
                $xsdRel = $xsdRelOrNull;
                [$ok, $errs] = validateDom($dom, $xsdRoot . '/' . $xsdRel);
                $valid = $ok ? 'ok' : 'chyba';
                $note = $ok ? '' : implode(' | ', array_slice(array_keys(array_count_values($errs)), 0, 3));
            }
        }
        $index[] = [
            'firm' => $firm, 'source' => 'extra-soubor', 'vrep_id' => '', 'type' => $type, 'org' => '', 'scope' => $scope,
            'period' => $period, 'sent_at' => preg_match('/_(\d{4})(\d{2})(\d{2})\.xml$/i', basename($file), $dm) === 1 ? "$dm[1]-$dm[2]-$dm[3]" : '', 'status_code' => '', 'status_text' => '',
            'accepted' => '', 'response_error' => '', 'file_name' => basename($file),
            'sentences' => (($n = countSentences($dom, $sentElement)) === null ? '' : (string) $n), 'xml_path' => $rel, 'response_path' => '',
            'receipts' => '0', 'xsd' => $xsdRel, 'validation' => $valid, 'validation_note' => trim($note . ' [zdroj mimo zálohu, bajty beze změny]'),
            'bytes' => (string) strlen($bytes), 'sha256' => $sha, 'linked_records' => '',
        ];
    }
}

// --- vazby vět na podání a osoby ---
$links = [];
$addLink = static function (string $table, array $r, string $field, string $vid, string $key) use (&$links, &$index, $byId, $personNo): void {
    $vid = strtoupper(trim($vid));
    if ($vid === '' || !isset($byId[$vid])) {
        return;
    }
    $inter = (int) ($r[$key] ?? 0);
    $links[] = [
        'vrep_id' => $vid, 'type' => $index[$byId[$vid]]['type'], 'table' => $table, 'record_id' => (string) ($r['ID'] ?? ''),
        'field' => $field, 'inter' => $inter > 0 ? (string) $inter : '', 'personal_no' => $inter > 0 ? ($personNo[$inter] ?? '') : '',
    ];
};
$hdpnInter = [];
foreach ($cache['MZ_HDPN'] ?? [] as $r) {
    $hdpnInter[strtoupper((string) ($r['ID'] ?? ''))] = (int) ($r['INTER'] ?? 0);
}
$jmhz2Msg = [];
foreach ($cache['MZ_JMHZ2'] ?? [] as $r) {
    $jmhz2Msg[strtoupper((string) ($r['ID'] ?? ''))] = $r;
}
foreach (['MZ_JMHZ' => [['ID_ZPRAVY', ''], ['ID_ZPRAVY2', '']], 'MZ_JMHZ2' => [['ID_ZPRAVY', 'INT_ZAM']], 'MZ_ELDP' => [['ID_ZPRAVY', 'INTER']],
    'MZ_HDPN' => [['ID_ZPRAVY', 'INTER'], ['ID_ZPRAVY2', 'INTER']], 'MZ_PRIZP' => [['ID_ZPRAVY', 'INTER']], 'MZ_PRISO' => [['ID_ZPRAVY', 'INTER']]] as $table => $defs) {
    foreach ($cache[$table] ?? [] as $r) {
        foreach ($defs as [$field, $key]) {
            $addLink($table, $r, $field, (string) ($r[$field] ?? ''), $key !== '' ? $key : 'x');
        }
    }
}
foreach ($cache['MZ_NEMPRI'] ?? [] as $r) {
    $r['__INTER'] = $hdpnInter[strtoupper((string) ($r['ID_HDPN'] ?? ''))] ?? 0;
    $addLink('MZ_NEMPRI', $r, 'ID_ZPRAVY', (string) ($r['ID_ZPRAVY'] ?? ''), '__INTER');
}
foreach (['MZ_JMHZ3', 'MZ_JMHZ5'] as $table) {
    foreach ($cache[$table] ?? [] as $r) {
        $parent = $jmhz2Msg[strtoupper((string) ($r['ID_JMHZ2'] ?? ''))] ?? null;
        if ($parent !== null) {
            $addLink($table, $r, 'ID_JMHZ2>ID_ZPRAVY', (string) ($parent['ID_ZPRAVY'] ?? ''), 'INT_ZAM');
        }
    }
}
// pokrytí: kolik vět strukturovaných tabulek má odeslaný obsah v MZ_VREP
$coverage = [];
foreach (['MZ_JMHZ' => ['ID_ZPRAVY'], 'MZ_JMHZ2' => ['ID_ZPRAVY'], 'MZ_ELDP' => ['ID_ZPRAVY'], 'MZ_NEMPRI' => ['ID_ZPRAVY'],
    'MZ_HDPN' => ['ID_ZPRAVY', 'ID_ZPRAVY2'], 'MZ_PRIZP' => ['ID_ZPRAVY'], 'MZ_PRISO' => ['ID_ZPRAVY']] as $table => $fields) {
    $total = 0;
    $with = 0;
    $emptyRef = 0;
    $dangling = 0;
    foreach ($cache[$table] ?? [] as $r) {
        $total++;
        $hit = false;
        $any = false;
        foreach ($fields as $f) {
            $v = strtoupper(trim((string) ($r[$f] ?? '')));
            if ($v === '') {
                continue;
            }
            $any = true;
            $hit = $hit || isset($byId[$v]);
        }
        $with += $hit ? 1 : 0;
        $emptyRef += $any ? 0 : 1;
        $dangling += ($any && !$hit) ? 1 : 0;
    }
    if (isset($cache[$table])) {
        $coverage[$table] = ['rows' => $total, 'with_payload' => $with, 'no_reference' => $emptyRef, 'reference_without_payload' => $dangling];
    }
}
$meta['coverage'] = $coverage;
$perMsg = [];
foreach ($links as $l) {
    $perMsg[$l['vrep_id']][$l['table']] = ($perMsg[$l['vrep_id']][$l['table']] ?? 0) + 1;
}
foreach ($index as $i => $ix) {
    $parts = [];
    foreach ($perMsg[$ix['vrep_id']] ?? [] as $t => $c) {
        $parts[] = $t . ':' . $c;
    }
    $index[$i]['linked_records'] = implode(';', $parts);
}

// --- zápis ---
$skipRaw = ['MZ_VREP' => true];
foreach ($cache as $name => $rows) {
    if (isset($skipRaw[$name])) {
        continue;
    }
    jsonWrite($out . '/raw/' . $name . '.json', $rows);
}
jsonWrite($out . '/raw/MZ_VREP.json', $vrepMeta);
jsonWrite($out . '/raw/_meta.json', $meta);

usort($index, static fn (array $a, array $b): int => [$a['type'], $a['period'], $a['sent_at']] <=> [$b['type'], $b['period'], $b['sent_at']]);
$fh = fopen($out . '/index.csv', 'wb');
fwrite($fh, "\xEF\xBB\xBF");
$head = array_keys($index[0] ?? ['firm' => '']);
fwrite($fh, csvLine($head) . "\r\n");
foreach ($index as $ix) {
    fwrite($fh, csvLine($ix) . "\r\n");
}
fclose($fh);

$fh = fopen($out . '/links.csv', 'wb');
fwrite($fh, "\xEF\xBB\xBF" . csvLine(['vrep_id', 'type', 'table', 'record_id', 'field', 'inter', 'personal_no']) . "\r\n");
foreach ($links as $l) {
    fwrite($fh, csvLine($l) . "\r\n");
}
fclose($fh);

// --- VALIDATION.md ---
$tally = [];
foreach ($index as $ix) {
    if ($ix['scope'] === 'payroll' || $ix['scope'] === 'query' || $ix['scope'] === 'tax' || $ix['scope'] === 'vat') {
        $tally[$ix['type']][$ix['validation'] ?: 'bez-obsahu'] = ($tally[$ix['type']][$ix['validation'] ?: 'bez-obsahu'] ?? 0) + 1;
    }
}
ksort($tally);
$md = ['# Validace odeslaných podání', '', 'Každé XML z `xml/<typ>/` se validuje `DOMDocument::schemaValidate` proti XSD připnutému v `api/xsd` (výběr podle kořenového prvku a jmenného prostoru). Typy bez připnutého XSD jsou `bez-xsd`. Obsah se neupravuje; jediný zásah je odstranění úvodního znaku `?` (po BOM) před `<?xml`.', '',
    '| Typ | ok | chyba | bez-xsd | nexml | ostatní |', '|---|---|---|---|---|---|'];
foreach ($tally as $type => $c) {
    $other = array_sum($c) - ($c['ok'] ?? 0) - ($c['chyba'] ?? 0) - ($c['bez-xsd'] ?? 0) - ($c['nexml'] ?? 0);
    $md[] = sprintf('| %s | %d | %d | %d | %d | %d |', $type, $c['ok'] ?? 0, $c['chyba'] ?? 0, $c['bez-xsd'] ?? 0, $c['nexml'] ?? 0, $other);
}
$md[] = '';
if ($errorCounts !== []) {
    $md[] = '## Chyby validace (bez hodnot, seřazeno podle četnosti)';
    $md[] = '';
    foreach ($errorCounts as $type => $errs) {
        arsort($errs);
        $md[] = '### ' . $type;
        $md[] = '';
        foreach (array_slice($errs, 0, 12, true) as $msg => $c) {
            $md[] = '- ' . $c . 'x ' . $msg;
        }
        $md[] = '';
    }
}
$md[] = '## Soubory bez XSD (důvod)';
$md[] = '';
$why = [];
foreach ($index as $ix) {
    if ($ix['validation'] === 'bez-xsd') {
        $why[$ix['type'] . ': ' . $ix['validation_note']] = ($why[$ix['type'] . ': ' . $ix['validation_note']] ?? 0) + 1;
    }
}
ksort($why);
foreach ($why as $k => $c) {
    $md[] = '- ' . $c . 'x ' . $k;
}
ensureDir($out . '/xml');
file_put_contents($out . '/xml/VALIDATION.md', implode("\n", $md) . "\n");

unset($toolDecoder, $t);
gc_collect_cycles();
if (!is_dir($source)) {
    rrmdir($work);
}
echo 'Hotovo: ' . count($index) . ' podání, ' . count($links) . " vazeb\n";
