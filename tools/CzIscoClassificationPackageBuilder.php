<?php

declare(strict_types=1);

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PhpOffice\PhpSpreadsheet\IOFactory;

require dirname(__DIR__) . '/api/vendor/autoload.php';

/**
 * Staví deterministický manifest klasifikace zaměstnání CZ-ISCO z jediného
 * oficiálního XLSX ČSÚ (systematická část).
 *
 * Soubor drží jeden list na každou vydanou verzi klasifikace. Builder z něj
 * vytáhne *aktuální* verzi (poslední list) jako závaznou množinu kódů a ze
 * starších listů odvodí seznam **vyřazených** kódů — ty aplikace nesmí nabízet,
 * ale musí je tolerovat u dat, která vznikla, když ještě platily.
 */
final class CzIscoClassificationPackageBuilder
{
    public const PACKAGE_KEY = 'cz-isco-2026-02-01-v1';
    public const SCHEMA_VERSION = 'cz-isco-classification.v1';
    public const PARSER_VERSION = 1;

    public const SOURCE_FILENAME = 'klasifikace_zamestnani_systematicka_cast_2026_02_01.xlsx';
    public const SOURCE_SHA256 =
        '2f9327f942fc54f3b302003380429501bda94b6d9728502c6a4352bd9d126ad5';
    public const SOURCE_BYTES = 278999;
    public const SOURCE_URL =
        'https://csu.gov.cz/docs/107516/ae2997c3-bf4b-b7c4-0626-82fb1078e81e/'
        . 'klasifikace_zamestnani_systematicka_cast_2026_02_01.xlsx';
    public const RETRIEVED_ON = '2026-08-15';

    public const LEGAL_BASIS = 'Sdělení ČSÚ č. 5/2026 Sb. ze dne 16. ledna 2026';
    public const LICENCE = 'CC BY 4.0';
    public const LICENCE_URL =
        'https://csu.gov.cz/podminky_pro_vyuzivani_a_dalsi_zverejnovani_statistickych_udaju_csu';

    /**
     * Listy XLSX v pořadí od nejstarší verze po aktuální. Názvy jsou připnuté
     * včetně překlepu ČSÚ v posledním listu („CZ-ICSO"); jiná sada listů znamená
     * jiné vydání a builder musí spadnout, ne tiše načíst něco jiného.
     *
     * @var list<array{sheet:string,version:string}>
     */
    private const VERSIONS = [
        ['sheet' => 'CZ-ISCO do 31.12.2017', 'version' => '2011-01-01'],
        ['sheet' => 'CZ-ISCO od 1.1.2018', 'version' => '2018-01-01'],
        ['sheet' => 'CZ-ISCO od 1.7.2020', 'version' => '2020-07-01'],
        ['sheet' => 'CZ-ISCO od 1.7.2022', 'version' => '2022-07-01'],
        ['sheet' => 'CZ-ISCO od 1.1.2025', 'version' => '2025-01-01'],
        ['sheet' => 'CZ-ICSO od 1.2.2026', 'version' => '2026-02-01'],
    ];

    public function build(string $sourceDirectory, string $outputPath): void
    {
        $sourcePath = $sourceDirectory . DIRECTORY_SEPARATOR . self::SOURCE_FILENAME;
        $this->verifySource($sourcePath);

        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($sourcePath);

        $sheetNames = $spreadsheet->getSheetNames();
        foreach (self::VERSIONS as $index => $version) {
            if (($sheetNames[$index] ?? null) !== $version['sheet']) {
                throw new RuntimeException(sprintf(
                    'List %d XLSX se jmenuje "%s", očekáván "%s" — zdroj ČSÚ změnil strukturu.',
                    $index,
                    (string) ($sheetNames[$index] ?? ''),
                    $version['sheet'],
                ));
            }
        }

        /** @var array<string,array<string,string>> $byVersion */
        $byVersion = [];
        foreach (self::VERSIONS as $index => $version) {
            $byVersion[$version['version']] = $this->readSheet($spreadsheet->getSheet($index));
        }

        $currentVersion = self::VERSIONS[count(self::VERSIONS) - 1]['version'];
        $current = $byVersion[$currentVersion];
        $this->assertHierarchy($current);

        $currentEntries = [];
        $ordinal = 0;
        foreach ($current as $rawCode => $label) {
            $code = (string) $rawCode;
            $level = strlen($code);
            $currentEntries[] = [
                'ordinal' => ++$ordinal,
                'code' => $code,
                'level' => $level,
                'parent_code' => $level === 1 ? null : substr($code, 0, $level - 1),
                'label' => $label,
            ];
        }

        $retired = [];
        foreach ($byVersion as $version => $entries) {
            if ($version === $currentVersion) {
                continue;
            }
            foreach ($entries as $rawCode => $label) {
                $code = (string) $rawCode;
                if (isset($current[$code])) {
                    continue;
                }
                $existing = $retired[$code] ?? null;
                if ($existing === null || $existing['last_version'] < $version) {
                    $retired[$code] = [
                        'code' => $code,
                        'level' => strlen($code),
                        'label' => $label,
                        'last_version' => $version,
                    ];
                }
            }
        }
        ksort($retired, SORT_STRING);
        $retiredEntries = [];
        $ordinal = 0;
        foreach ($retired as $entry) {
            $retiredEntries[] = ['ordinal' => ++$ordinal] + $entry;
        }

        $byLevel = [];
        foreach ($currentEntries as $entry) {
            $key = (string) $entry['level'];
            $byLevel[$key] = ($byLevel[$key] ?? 0) + 1;
        }
        ksort($byLevel, SORT_STRING);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'package_key' => self::PACKAGE_KEY,
            'parser_version' => self::PARSER_VERSION,
            'publisher' => 'Český statistický úřad',
            'licence' => self::LICENCE,
            'licence_url' => self::LICENCE_URL,
            'usage_policy' => 'authoritative_validation_and_suggest',
            'source' => [
                'filename' => self::SOURCE_FILENAME,
                'sha256' => self::SOURCE_SHA256,
                'byte_length' => self::SOURCE_BYTES,
                'url' => self::SOURCE_URL,
                'retrieved_on' => self::RETRIEVED_ON,
            ],
            'classification' => [
                'version' => $currentVersion,
                'effective_from' => $currentVersion,
                'legal_basis' => self::LEGAL_BASIS,
                'sheet_name' => self::VERSIONS[count(self::VERSIONS) - 1]['sheet'],
            ],
            'versions' => array_map(
                static fn (array $version): array => [
                    'version' => $version['version'],
                    'sheet_name' => $version['sheet'],
                ],
                self::VERSIONS,
            ),
            'counts' => [
                'current' => count($currentEntries),
                'current_by_level' => $byLevel,
                'retired' => count($retiredEntries),
            ],
            'content_hash' => hash('sha256', CanonicalJson::encode([
                'current' => $currentEntries,
                'retired' => $retiredEntries,
            ])),
            'current' => $currentEntries,
            'retired' => $retiredEntries,
        ];

        $manifest = [
            'manifest_sha256' => hash('sha256', CanonicalJson::encode($payload)),
            'payload' => $payload,
        ];

        $json = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
        if (file_put_contents($outputPath, $json . "\n") === false) {
            throw new RuntimeException('Manifest CZ-ISCO nelze zapsat.');
        }

        fwrite(STDOUT, sprintf(
            "CZ-ISCO %s: %d platných položek %s, %d vyřazených, manifest_sha256=%s\n",
            $currentVersion,
            count($currentEntries),
            json_encode($byLevel, JSON_THROW_ON_ERROR),
            count($retiredEntries),
            $manifest['manifest_sha256'],
        ));
    }

    private function verifySource(string $path): void
    {
        $hash = hash_file('sha256', $path);
        $bytes = filesize($path);
        if (!is_string($hash) || !hash_equals(self::SOURCE_SHA256, $hash) || $bytes !== self::SOURCE_BYTES) {
            throw new RuntimeException(sprintf(
                'Zdroj CZ-ISCO %s neodpovídá připnutému otisku (%s / %s bajtů).',
                self::SOURCE_FILENAME,
                is_string($hash) ? $hash : 'nečitelný',
                var_export($bytes, true),
            ));
        }
    }

    /** @return array<string,string> */
    private function readSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        $entries = [];
        foreach ($sheet->toArray(null, true, false, false) as $row) {
            $code = trim((string) ($row[0] ?? ''));
            $label = trim((string) ($row[1] ?? ''));
            if ($code === '' || $code === 'kód') {
                continue;
            }
            if (preg_match('/\A[0-9]{1,5}\z/D', $code) !== 1) {
                throw new RuntimeException("List {$sheet->getTitle()} obsahuje neplatný kód \"{$code}\".");
            }
            if ($label === '') {
                throw new RuntimeException("Kód {$code} na listu {$sheet->getTitle()} nemá název.");
            }
            if (isset($entries[$code])) {
                throw new RuntimeException("Kód {$code} je na listu {$sheet->getTitle()} duplicitní.");
            }
            $entries[$code] = preg_replace('/\s+/u', ' ', $label) ?? $label;
        }
        if (count($entries) < 1500) {
            throw new RuntimeException(sprintf(
                'List %s vrátil jen %d položek — zdroj je prázdný nebo osekaný.',
                $sheet->getTitle(),
                count($entries),
            ));
        }
        ksort($entries, SORT_STRING);

        return $entries;
    }

    /** @param array<string,string> $entries */
    private function assertHierarchy(array $entries): void
    {
        foreach ($entries as $rawCode => $_label) {
            $code = (string) $rawCode;
            $level = strlen($code);
            if ($level === 1) {
                continue;
            }
            $parent = substr($code, 0, $level - 1);
            if (!isset($entries[$parent])) {
                throw new RuntimeException("Kód {$code} nemá v klasifikaci nadřazený kód {$parent}.");
            }
        }
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $root = dirname(__DIR__) . '/api/resources/payroll/cz-isco/classification-2026-02-01';
    (new CzIscoClassificationPackageBuilder())->build($root, $root . '/manifest.json');
}
