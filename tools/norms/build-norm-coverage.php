<?php

declare(strict_types=1);

/**
 * Regeneruje api/resources/payroll/norms/<formular>.json (normativni pokryti podani mezd).
 *
 * Vstup (soukromy, gitignorovany adresar private/normy):
 *   matice/<formular>.json    pozadavky vytazene z oficialnich dokumentu CSSZ/MPSV/ZP
 *   pokryti/<formular>.json   stav pokryti po zpetnem auditu (JMHZ: pokryti/jmhz-1..6.json)
 *   pokryti/ZPETNY-AUDIT-DEFEKTY.json   overene otevrene defekty (volitelne)
 * Vystup: pouze api/resources/payroll/norms/ (<formular>.json a pri prvnim behu baseline.json).
 * Format: kazdy soubor ma meta, test_refs (unikatni "Plne\Kvalifikovana\Trida::metoda" nebo samotna
 * trida) a requirements; polozka requirements.tests je seznam indexu do test_refs.
 * Do vystupu se nedostavaji osobni data, cisla radku kodu ani dlouhe citace.
 *
 * Pouziti:
 *   php tools/norms/build-norm-coverage.php [--private=<cesta k private/normy>] [--raise-baseline]
 *
 * Vychozi --private je <koren repa>/private/normy. Baseline (ratchet pro PayrollNormCoverageTest)
 * se vytvori jen pokud chybi; s --raise-baseline se hodnoty pouze zvysuji, nikdy nesnizuji.
 * Snizit baseline jde jen rucni upravou souboru ve stejnem PR.
 *
 * Nova oficialni verze DV/EDV/XSD: znovu vygenerovat matici, namapovat pokryti
 * a upravit FORMS nize (xsd_dir, official_sources).
 */

const MAX_TOTAL_BYTES = 5 * 1024 * 1024;

$root = dirname(__DIR__, 2);
$privateDir = $root . '/private/normy';
$raiseBaseline = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--private=')) {
        $privateDir = rtrim(substr($arg, 10), '/\\');
    } elseif ($arg === '--raise-baseline') {
        $raiseBaseline = true;
    } else {
        fwrite(STDERR, "Neznamy argument: {$arg}\n");
        exit(2);
    }
}
$outDir = $root . '/api/resources/payroll/norms';

/** Formular => [pokryti soubory, xsd_dir, oficialni zdroje (dokument + verze)] */
const FORMS = [
    'nempri25' => [
        'coverage' => ['nempri25'],
        'xsd_dir' => 'api/xsd/cssz/nempri25-1.0',
        'sources' => [
            'XSD NEMPRI25 1.0 (30. 5. 2025) + baseTypes2 1.0',
            'DV NEMPRI25 v1.0 (aktualizace 9. 3. 2026)',
            'Logicke kontroly LK 1-16, Vseobecne zasady, zakon 187/2006 Sb.',
        ],
    ],
    'hzupn20' => [
        'coverage' => ['hzupn20'],
        'xsd_dir' => 'api/xsd/cssz/hzupn20-1.2',
        'sources' => [
            'XSD HZUPN20 1.2 (10. 4. 2025)',
            'DV HZUPN20 v1.12 (10. 4. 2025)',
            'LK_ZAMEL_NEM20 (22. 11. 2019)',
        ],
    ],
    'regzec25' => [
        'coverage' => ['regzec25'],
        'xsd_dir' => 'api/xsd/jmhz/regzec-1.4.0.4',
        'sources' => [
            'XSD REGZEC25 1.4.0.4 (27. 7. 2026)',
            'EDV 1.4.0.6 (12. 6. 2026)',
            'Zasady 1.4.6, zakon 323/2025 Sb., NV 417/2025 Sb.',
        ],
    ],
    'prezec26' => [
        'coverage' => ['prezec26'],
        'xsd_dir' => 'api/xsd/jmhz/prezec-1.2',
        'sources' => [
            'XSD PREZEC26 1.2',
            'EDV 1.4.0.6 (12. 6. 2026)',
            'zakon 323/2025 Sb.',
        ],
    ],
    'jmhz' => [
        'coverage' => ['jmhz-1', 'jmhz-2', 'jmhz-3', 'jmhz-4', 'jmhz-5', 'jmhz-6'],
        'xsd_dir' => 'api/xsd/jmhz/jmhz-1.4.3.6',
        'sources' => [
            'XSD JMHZ 1.4.3.6 (3. 9. 2026)',
            'Katalog kontrol 1.4.2.10 (23. 9. 2026)',
            'Datovy slovnik 1.4.1.6 (12. 6. 2026)',
            'Datove scenare 1.4.0.2 (19. 3. 2026)',
            'Pokyny k vyplneni MH 1.4.14',
            'Pravidla podani JMHZ 1.4.5',
            'zakon 323/2025 Sb., NV 417/2025 Sb.',
        ],
    ],
    'eldp' => [
        'coverage' => ['eldp'],
        'xsd_dir' => 'api/xsd/jmhz/jmhz-1.4.3.6',
        'sources' => [
            'ELDP12 Struktura a testy v1.04, Logicke testy v1.02, Ciselniky v1.03 (samostatny XSD ELDP12 neni pripnut)',
            'XSD JMHZ 1.4.3.6 eldpType, Katalog kontrol 1.4.2.10',
            'Vseobecne zasady a Metodicka pomucka ELDP I/2026',
            'zakon 582/1991 Sb. (znění 1. 7. 2026), zakon 360/2025 Sb.',
        ],
    ],
    'ozuspoj' => [
        'coverage' => ['ozuspoj'],
        'xsd_dir' => 'api/xsd/cssz/ozuspoj-1.2',
        'sources' => [
            'XSD OZUSPOJ23 1.2 (29. 6. 2023)',
            'DV OZUSPOJ23 v1.0 (27. 2. 2023)',
            'zakon 589/1992 Sb. (sleva na pojistnem)',
        ],
    ],
    'hoz' => [
        'coverage' => ['hoz'],
        'xsd_dir' => 'api/xsd/zp/2025-v8',
        'sources' => [
            'XSD hromadneOznameniZamestnavatele rev. 08 (8. 12. 2025, platne od 1. 1. 2026)',
            'zakon 48/1997 Sb. (§ 10, § 44b), CPZP technicka informace',
        ],
    ],
    'prehled-zp' => [
        'coverage' => ['prehled-zp'],
        'xsd_dir' => 'api/xsd/zp/2025-v8',
        'sources' => [
            'XSD prehledPlatbyZamestnavatele rev. 08 (8. 12. 2025, platne od 1. 1. 2026)',
            'zakon 592/1992 Sb. (§ 25 odst. 3), CPZP technicka informace',
        ],
    ],
];

const STATUSES = [
    'implemented_tested', 'implemented_untested', 'missing', 'violated',
    'not_applicable', 'unclear', 'accepted_gap',
];
const GAP_STATUSES = ['missing', 'violated', 'unclear', 'accepted_gap'];

function clean(string $s): string
{
    $s = str_replace(["\u{2014}", "\u{2013}", "\r"], ['-', '-', ''], $s);

    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

function short(?string $s, int $max): string
{
    $s = clean((string) $s);
    if (mb_strlen($s) <= $max) {
        return $s;
    }

    return rtrim(mb_substr($s, 0, $max - 3)) . '...';
}

function loadJson(string $path): mixed
{
    if (!is_file($path)) {
        fwrite(STDERR, "Chybi vstup: {$path}\n");
        exit(1);
    }
    $data = json_decode((string) file_get_contents($path), true);
    if ($data === null) {
        fwrite(STDERR, "Neplatny JSON: {$path}\n");
        exit(1);
    }

    return $data;
}

/** @return array{files: array<string, string>, methods: array<string, list<string>>} */
function indexTests(string $root): array
{
    $files = [];
    $methods = [];
    $base = $root . '/api/tests';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $rel = 'api/tests/' . str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
        $src = (string) file_get_contents($f->getPathname());
        $fqcn = 'MyInvoice\\Tests\\' . str_replace('/', '\\', substr($rel, strlen('api/tests/'), -4));
        $files[$rel] = $fqcn;
        if (preg_match_all('/function\s+(test[A-Za-z0-9_]*)\s*\(/', $src, $m)) {
            foreach (array_unique($m[1]) as $name) {
                $methods[$name][] = $rel;
                $files[$rel . '#' . $name] = $fqcn;
            }
        }
    }

    return ['files' => $files, 'methods' => $methods];
}

/**
 * Z volneho textu test_ref vybere jen existujici reference.
 *
 * @return list<string>
 */
function normalizeTests(string $ref, array $index): array
{
    $ref = clean($ref);
    while (($n = preg_replace('/\([^()]*\)/u', ' ', $ref)) !== null && $n !== $ref) {
        $ref = $n;
    }
    preg_match_all('#(api/tests/[A-Za-z0-9_/\\\\]+\.php)|(?<![A-Za-z0-9_])(test[A-Za-z0-9_]*)#', $ref, $m, PREG_SET_ORDER);
    $current = null;
    $pending = false;
    $out = [];
    foreach ($m as $tok) {
        if (($tok[1] ?? '') !== '') {
            if ($pending && $current !== null) {
                $out[] = $index['files'][$current];
            }
            $current = str_replace('\\', '/', $tok[1]);
            $current = isset($index['files'][$current]) ? $current : null;
            $pending = $current !== null;
            continue;
        }
        $name = $tok[2];
        $file = null;
        if ($current !== null && isset($index['files'][$current . '#' . $name])) {
            $file = $current;
        } elseif (count($index['methods'][$name] ?? []) === 1) {
            $file = $index['methods'][$name][0];
        }
        if ($file !== null) {
            $out[] = $index['files'][$file] . '::' . $name;
            $pending = false;
        }
    }
    if ($pending && $current !== null) {
        $out[] = $index['files'][$current];
    }
    $out = array_values(array_unique($out));
    sort($out, SORT_STRING);

    return $out;
}

function sourceText(mixed $src, array $abbr = []): string
{
    if (is_string($src)) {
        return short($src, 110);
    }
    if (!is_array($src) || $src === []) {
        return '';
    }
    $extra = '';
    if (array_is_list($src)) {
        if (count($src) > 1) {
            $extra = ' (+' . (count($src) - 1) . ')';
        }
        $src = $src[0];
        if (is_string($src)) {
            return short($src, 100) . $extra;
        }
    }
    $doc = $src['document'] ?? $src['doc'] ?? $src['id'] ?? $src['title'] ?? '';
    $doc = $abbr[$doc] ?? $doc;
    $parts = [short((string) $doc, 70)];
    if (!empty($src['version'])) {
        $parts[] = short((string) $src['version'], 20);
    }
    $sec = $src['section'] ?? $src['ref'] ?? $src['loc'] ?? $src['locator'] ?? '';
    if (is_string($sec) && $sec !== '') {
        $parts[] = short($sec, 50);
    }

    return implode(', ', array_filter($parts, static fn ($p) => $p !== '')) . $extra;
}

function severityOf(array $r): string
{
    $sev = $r['severity_if_violated'] ?? null;

    return is_string($sev) && $sev !== '' ? $sev : 'unspecified';
}

function encodeForm(array $doc): string
{
    $lines = [];
    foreach ($doc['requirements'] as $r) {
        $lines[] = '    ' . json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    $meta = json_encode($doc['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $refs = [];
    foreach ($doc['test_refs'] as $ref) {
        $refs[] = '    ' . json_encode($ref, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    return "{\n  \"meta\": {$meta},\n  \"test_refs\": [\n" . implode(",\n", $refs)
        . "\n  ],\n  \"requirements\": [\n" . implode(",\n", $lines) . "\n  ]\n}\n";
}

$index = indexTests($root);

$defects = [];
$defectFile = $privateDir . '/pokryti/ZPETNY-AUDIT-DEFEKTY.json';
if (is_file($defectFile)) {
    foreach (loadJson($defectFile) as $d) {
        $defects[(string) $d['form']][(string) $d['req']] = (string) $d['sev'];
    }
}

if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Nelze vytvorit {$outDir}\n");
    exit(1);
}

$total = 0;
$counts = [];
$downgraded = [];
foreach (FORMS as $form => $cfg) {
    $matrix = loadJson("{$privateDir}/matice/{$form}.json");
    $reqs = $matrix['requirements'] ?? $matrix;
    $coverage = [];
    foreach ($cfg['coverage'] as $cov) {
        foreach (loadJson("{$privateDir}/pokryti/{$cov}.json") as $c) {
            $coverage[$c['req_id']] = $c;
        }
    }

    $abbr = [];
    $declared = $matrix['sources'] ?? $matrix['meta']['sources'] ?? [];
    foreach (is_array($declared) ? $declared : [] as $key => $def) {
        if (!is_array($def)) {
            continue;
        }
        $key = is_string($key) ? $key : (string) ($def['id'] ?? '');
        $title = short((string) ($def['title'] ?? ''), 56);
        if ($key !== '' && $title !== '') {
            $abbr[$key] = $title . ((!empty($def['version']) && mb_strlen((string) $def['version']) <= 16) ? ' ' . $def['version'] : '');
        }
    }

    $rows = [];
    foreach ($reqs as $r) {
        $id = (string) $r['id'];
        $c = $coverage[$id] ?? null;
        if ($c === null) {
            fwrite(STDERR, "Bez pokryti: {$form} {$id}\n");
            exit(1);
        }
        $status = (string) $c['status'];
        $tests = normalizeTests((string) ($c['test_ref'] ?? ''), $index);
        if ($status === 'implemented_tested' && $tests === []) {
            $status = 'implemented_untested';
            $downgraded[] = $id;
        }
        $row = ['id' => $id];
        if (!empty($r['xpath'])) {
            $row['xpath'] = short((string) $r['xpath'], 140);
        }
        $row['kind'] = (string) ($r['kind'] ?? 'semantics');
        if (!empty($r['condition'])) {
            $row['condition'] = short((string) $r['condition'], 110);
        }
        $row['rule'] = short((string) ($r['rule'] ?? ''), 160);
        $src = sourceText($r['source'] ?? null, $abbr);
        if ($src !== '') {
            $row['source'] = $src;
        }
        $row['severity'] = severityOf($r);
        $row['status'] = $status;
        if ($tests !== []) {
            $row['tests'] = $tests;
        }
        if (in_array($status, GAP_STATUSES, true)) {
            $gap = short((string) ($c['note'] ?? ''), 200);
            if ($gap === '') {
                $gap = 'bez zduvodneni v auditu';
            }
            $row['gap'] = $gap;
        }
        if (isset($defects[$form][$id])) {
            $row['defect'] = $defects[$form][$id];
        }
        $rows[] = $row;
        $counts[$form][$status] = ($counts[$form][$status] ?? 0) + 1;
    }
    usort($rows, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
    $pool = [];
    foreach ($rows as $row) {
        foreach ($row['tests'] ?? [] as $t) {
            $pool[$t] = true;
        }
    }
    $pool = array_keys($pool);
    sort($pool, SORT_STRING);
    $poolIndex = array_flip($pool);
    foreach ($rows as &$row) {
        if (isset($row['tests'])) {
            $row['tests'] = array_map(static fn (string $t): int => $poolIndex[$t], $row['tests']);
            sort($row['tests']);
        }
    }
    unset($row);

    $doc = [
        'meta' => [
            'form' => $form,
            'xsd_dir' => $cfg['xsd_dir'],
            'official_sources' => $cfg['sources'],
            'requirements' => count($rows),
        ],
        'test_refs' => $pool,
        'requirements' => $rows,
    ];
    $json = encodeForm($doc);
    file_put_contents("{$outDir}/{$form}.json", $json);
    $total += strlen($json);
    $unknownDefects = array_diff(array_keys($defects[$form] ?? []), array_column($rows, 'id'));
    if ($unknownDefects !== []) {
        fwrite(STDERR, "Defekt na neexistujici pozadavek ({$form}): " . implode(', ', $unknownDefects) . "\n");
        exit(1);
    }
}

$baselineFile = "{$outDir}/baseline.json";
$baseline = is_file($baselineFile) ? (json_decode((string) file_get_contents($baselineFile), true) ?: []) : [];
$current = [];
foreach (FORMS as $form => $_) {
    $current[$form] = $counts[$form]['implemented_tested'] ?? 0;
}
if ($baseline === [] || $raiseBaseline) {
    foreach ($current as $form => $n) {
        $baseline['implemented_tested'][$form] = max($n, $baseline['implemented_tested'][$form] ?? 0);
    }
    ksort($baseline['implemented_tested']);
    file_put_contents($baselineFile, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

foreach ($counts as $form => $byStatus) {
    ksort($byStatus);
    echo str_pad($form, 12) . json_encode($byStatus) . "\n";
}
echo 'Celkem bajtu: ' . $total . "\n";
if ($downgraded !== []) {
    echo 'Snizene na implemented_untested (zadny existujici test): ' . count($downgraded) . "\n";
}
if ($total > MAX_TOTAL_BYTES) {
    fwrite(STDERR, "Prekrocen limit 5 MB\n");
    exit(1);
}
