<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

/**
 * Klasifikace zaměstnání CZ-ISCO (ČSÚ) — jediná čtecí cesta k připnutému
 * číselníku v `api/resources/payroll/cz-isco`.
 *
 * Proč vůbec: kód CZ-ISCO byl do dneška volný text s kontrolou tvaru. Špatný,
 * ale správně tvarovaný kód (12345) tak prošel až do podání JMHZ a projevil se
 * až odmítnutím ČSSZ — tedy v nejdražším možném okamžiku. Tady se chytí hned
 * při uložení pracovního vztahu.
 *
 * Číselník rozlišuje tři stavy kódu, stejně jako {@see \MyInvoice\Service\Report\EpoOkecCodebook}:
 *   - {@see STATUS_ACTIVE}  — kód platné verze klasifikace,
 *   - {@see STATUS_RETIRED} — kód, který v některém starším vydání platil a dnes
 *     už v klasifikaci není (typicky data zadaná před revizí),
 *   - {@see STATUS_UNKNOWN} — kód, který v CZ-ISCO nikdy nebyl.
 *
 * Našeptávač nabízí jen {@see STATUS_ACTIVE}; validace zápisu tuhle trojici
 * používá k tomu, aby nové hodnoty vynutila a staré nezablokovala.
 */
final class CzIscoCodebook
{
    public const PACKAGE_KEY = 'cz-isco-2026-02-01-v1';
    public const DEFAULT_MANIFEST_SHA256 =
        '3f90eee6d536ce592c7de59532caa515b23faf9009b2e8b952cf2b94b844a871';

    public const SCHEMA_VERSION = 'cz-isco-classification.v1';
    public const CLASSIFICATION_VERSION = '2026-02-01';

    /** Kód je v platné verzi klasifikace. */
    public const STATUS_ACTIVE = 'active';
    /** Kód v CZ-ISCO byl, ale v platné verzi už není. */
    public const STATUS_RETIRED = 'retired';
    /** Kód v žádném vydání CZ-ISCO nebyl. */
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * Úrovně, které smí uživatel zadat jako kód pracovního vztahu. ČSSZ chce
     * v JMHZ podskupinu (4 místa) nebo kategorii (5 míst), ne hlavní třídu.
     */
    public const SELECTABLE_LEVELS = [4, 5];

    public const MIN_QUERY_LENGTH = 2;
    public const MAX_SEARCH_LIMIT = 50;
    public const DEFAULT_SEARCH_LIMIT = 20;

    private const DIRECTORY = 'classification-2026-02-01';

    /** @var array{manifest_sha256:string,payload:array<string,mixed>}|null */
    private ?array $manifest = null;

    /** @var array<string,array{code:string,level:int,parent_code:?string,label:string,status:string,haystack:string}>|null */
    private ?array $entries = null;

    /** @var list<array{code:string,level:int,parent_code:?string,label:string,status:string,haystack:string}>|null */
    private ?array $selectable = null;

    public function __construct(private readonly ?string $resourceRoot = null) {}

    /**
     * Stav kódu v číselníku. Vstup se nijak nenormalizuje — kód se ukládá přesně
     * tak, jak přišel, a leading zero („03101") je jeho součástí.
     */
    public function status(string $code): string
    {
        $this->load();
        $entries = $this->entries ?? [];

        return $entries[$code]['status'] ?? self::STATUS_UNKNOWN;
    }

    /** @return array{code:string,level:int,parent_code:?string,label:string,status:string}|null */
    public function find(string $code): ?array
    {
        $this->load();
        $entry = ($this->entries ?? [])[$code] ?? null;
        if ($entry === null) {
            return null;
        }
        unset($entry['haystack']);

        return $entry;
    }

    /**
     * Našeptávač: hledá podle kódu (prefix) i podle názvu (podřetězec) bez
     * ohledu na diakritiku a velikost písmen. Vrací jen zadatelné úrovně.
     *
     * @return list<array{code:string,label:string,level:int,parent_code:?string,parent_label:?string}>
     * @throws \InvalidArgumentException Prázdný / příliš krátký dotaz nebo limit mimo rozsah.
     */
    public function search(string $query, int $limit = self::DEFAULT_SEARCH_LIMIT): array
    {
        if ($limit < 1 || $limit > self::MAX_SEARCH_LIMIT) {
            throw new \InvalidArgumentException(
                'Limit našeptávače CZ-ISCO musí být od 1 do ' . self::MAX_SEARCH_LIMIT . '.',
            );
        }
        $needle = self::fold($query);
        if (mb_strlen($needle, 'UTF-8') < self::MIN_QUERY_LENGTH) {
            throw new \InvalidArgumentException(
                'Pro našeptání kódu CZ-ISCO zadejte alespoň '
                . self::MIN_QUERY_LENGTH . ' znaky — část kódu nebo názvu profese.',
            );
        }
        $this->load();

        $digits = preg_replace('/\D/', '', $query) ?? '';
        $exact = [];
        $codePrefix = [];
        $labelPrefix = [];
        $labelAnywhere = [];

        foreach ($this->selectable ?? [] as $entry) {
            if ($digits !== '' && $entry['code'] === $digits) {
                $exact[] = $entry;
                continue;
            }
            if ($digits !== '' && str_starts_with($entry['code'], $digits)) {
                $codePrefix[] = $entry;
                continue;
            }
            if (str_starts_with($entry['haystack'], $needle)) {
                $labelPrefix[] = $entry;
                continue;
            }
            if (str_contains($entry['haystack'], $needle)) {
                $labelAnywhere[] = $entry;
            }
        }

        $result = [];
        foreach ([$exact, $codePrefix, $labelPrefix, $labelAnywhere] as $bucket) {
            foreach ($bucket as $entry) {
                $result[] = [
                    'code' => $entry['code'],
                    'label' => $entry['label'],
                    'level' => $entry['level'],
                    'parent_code' => $entry['parent_code'],
                    'parent_label' => $entry['parent_code'] === null
                        ? null
                        : (($this->entries ?? [])[$entry['parent_code']]['label'] ?? null),
                ];
                if (count($result) >= $limit) {
                    return $result;
                }
            }
        }

        return $result;
    }

    /** @return array{package_key:string,manifest_sha256:string,classification_version:string,effective_from:string,legal_basis:string,licence:string,licence_url:string,source_url:string,entry_count:int} */
    public function provenance(): array
    {
        $this->load();
        $payload = $this->payload();
        /** @var array<string,mixed> $classification */
        $classification = $payload['classification'];
        /** @var array<string,mixed> $source */
        $source = $payload['source'];
        /** @var array<string,mixed> $counts */
        $counts = $payload['counts'];

        return [
            'package_key' => (string) $payload['package_key'],
            'manifest_sha256' => (string) ($this->manifest['manifest_sha256'] ?? ''),
            'classification_version' => (string) $classification['version'],
            'effective_from' => (string) $classification['effective_from'],
            'legal_basis' => (string) $classification['legal_basis'],
            'licence' => (string) $payload['licence'],
            'licence_url' => (string) $payload['licence_url'],
            'source_url' => (string) $source['url'],
            'entry_count' => (int) $counts['current'],
        ];
    }

    /** Porovnávací tvar — bez diakritiky, bez velikosti písmen, se sjednocenými mezerami. */
    public static function fold(string $value): string
    {
        $lower = mb_strtolower(trim($value), 'UTF-8');
        $folded = strtr($lower, [
            'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'ë' => 'e',
            'í' => 'i', 'ï' => 'i', 'ĺ' => 'l', 'ľ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o',
            'ö' => 'o', 'ŕ' => 'r', 'ř' => 'r', 'š' => 's', 'ś' => 's', 'ť' => 't', 'ú' => 'u',
            'ů' => 'u', 'ü' => 'u', 'ý' => 'y', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z', 'ł' => 'l',
        ]);

        return trim(preg_replace('/\s+/u', ' ', $folded) ?? $folded);
    }

    private function load(): void
    {
        if ($this->manifest !== null) {
            return;
        }
        $root = $this->resourceRoot ?? dirname(__DIR__, 3) . '/resources/payroll/cz-isco';
        $path = $root . DIRECTORY_SEPARATOR . self::DIRECTORY . DIRECTORY_SEPARATOR . 'manifest.json';
        $json = @file_get_contents($path);
        if ($json === false || $json === '') {
            throw new \RuntimeException('Manifest klasifikace CZ-ISCO nelze načíst.');
        }
        /** @var mixed $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)
            || !is_string($decoded['manifest_sha256'] ?? null)
            || !is_array($decoded['payload'] ?? null)
        ) {
            throw new \UnexpectedValueException('Manifest klasifikace CZ-ISCO nemá očekávanou strukturu.');
        }
        /** @var array{manifest_sha256:string,payload:array<string,mixed>} $decoded */
        self::validateManifest($decoded, true);

        $entries = [];
        $selectable = [];
        /** @var list<array<string,mixed>> $current */
        $current = $decoded['payload']['current'];
        foreach ($current as $row) {
            $code = (string) $row['code'];
            $entry = [
                'code' => $code,
                'level' => (int) $row['level'],
                'parent_code' => $row['parent_code'] === null ? null : (string) $row['parent_code'],
                'label' => (string) $row['label'],
                'status' => self::STATUS_ACTIVE,
                'haystack' => self::fold((string) $row['label']),
            ];
            $entries[$code] = $entry;
            if (in_array($entry['level'], self::SELECTABLE_LEVELS, true)) {
                $selectable[] = $entry;
            }
        }
        /** @var list<array<string,mixed>> $retired */
        $retired = $decoded['payload']['retired'];
        foreach ($retired as $row) {
            $code = (string) $row['code'];
            $entries[$code] = [
                'code' => $code,
                'level' => (int) $row['level'],
                'parent_code' => null,
                'label' => (string) $row['label'],
                'status' => self::STATUS_RETIRED,
                'haystack' => self::fold((string) $row['label']),
            ];
        }

        $this->entries = $entries;
        $this->selectable = $selectable;
        $this->manifest = $decoded;
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        if ($this->manifest === null) {
            throw new \LogicException('Manifest klasifikace CZ-ISCO nebyl načten.');
        }

        return $this->manifest['payload'];
    }

    /**
     * Fail-closed kontrola manifestu. Chrání přesně proti tomu, na co projekt
     * u jiného číselníku narazil: soubor se stáhne, ale je prázdný nebo osekaný
     * na hlavičku — hash i počty to odhalí dřív, než se to projeví jako
     * „ten kód neznáme" u každého zaměstnance.
     *
     * @param array{manifest_sha256:string,payload:array<string,mixed>} $manifest
     */
    public static function validateManifest(array $manifest, bool $requirePinnedHash = false): void
    {
        $payload = $manifest['payload'];
        $actual = hash('sha256', CanonicalJson::encode($payload));
        if (!hash_equals($manifest['manifest_sha256'], $actual)
            || ($requirePinnedHash && !hash_equals(self::DEFAULT_MANIFEST_SHA256, $actual))
        ) {
            throw new \UnexpectedValueException('Manifest klasifikace CZ-ISCO nemá připnutý SHA-256.');
        }
        if (($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($payload['package_key'] ?? null) !== self::PACKAGE_KEY
            || ($payload['parser_version'] ?? null) !== 1
        ) {
            throw new \UnexpectedValueException('Manifest klasifikace CZ-ISCO má neznámou identitu.');
        }
        $classification = $payload['classification'] ?? null;
        if (!is_array($classification)
            || ($classification['version'] ?? null) !== self::CLASSIFICATION_VERSION
        ) {
            throw new \UnexpectedValueException('Manifest klasifikace CZ-ISCO má neznámou verzi klasifikace.');
        }

        $current = self::rows($payload['current'] ?? null, 'current');
        $retired = self::rows($payload['retired'] ?? null, 'retired');
        $counts = $payload['counts'] ?? null;
        if (!is_array($counts)
            || ($counts['current'] ?? null) !== count($current)
            || ($counts['retired'] ?? null) !== count($retired)
        ) {
            throw new \UnexpectedValueException('Klasifikace CZ-ISCO má jiný počet položek, než manifest slibuje.');
        }
        if (!hash_equals(
            is_string($payload['content_hash'] ?? null) ? $payload['content_hash'] : '',
            hash('sha256', CanonicalJson::encode(['current' => $current, 'retired' => $retired])),
        )) {
            throw new \UnexpectedValueException('Obsah klasifikace CZ-ISCO neodpovídá otisku v manifestu.');
        }

        $byLevel = [];
        $codes = [];
        foreach ($current as $ordinal => $row) {
            $code = self::text($row, 'code');
            $level = $row['level'] ?? null;
            if (preg_match('/\A[0-9]{1,5}\z/D', $code) !== 1
                || !is_int($level)
                || $level !== strlen($code)
                || isset($codes[$code])
                || ($row['ordinal'] ?? null) !== $ordinal + 1
                || self::text($row, 'label') !== trim(self::text($row, 'label'))
            ) {
                throw new \UnexpectedValueException("Klasifikace CZ-ISCO má neplatnou položku {$code}.");
            }
            $codes[$code] = $row;
            $byLevel[(string) $level] = ($byLevel[(string) $level] ?? 0) + 1;
        }
        foreach ($current as $row) {
            $code = self::text($row, 'code');
            $parent = $row['parent_code'] ?? null;
            $expected = strlen($code) === 1 ? null : substr($code, 0, strlen($code) - 1);
            if ($parent !== $expected || ($parent !== null && !isset($codes[$parent]))) {
                throw new \UnexpectedValueException("Kód CZ-ISCO {$code} nemá platnou nadřazenou položku.");
            }
        }
        ksort($byLevel, SORT_STRING);
        if (CanonicalJson::encode(['x' => $counts['current_by_level'] ?? null])
            !== CanonicalJson::encode(['x' => $byLevel])
        ) {
            throw new \UnexpectedValueException('Klasifikace CZ-ISCO má jiné rozložení úrovní, než manifest slibuje.');
        }
        foreach ($retired as $ordinal => $row) {
            $code = self::text($row, 'code');
            if (preg_match('/\A[0-9]{1,5}\z/D', $code) !== 1
                || isset($codes[$code])
                || ($row['ordinal'] ?? null) !== $ordinal + 1
                || !is_string($row['last_version'] ?? null)
            ) {
                throw new \UnexpectedValueException("Vyřazený kód CZ-ISCO {$code} není platný záznam.");
            }
            $codes[$code] = $row;
        }
    }

    /** @return list<array<string,mixed>> */
    private static function rows(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("Pole {$field} klasifikace CZ-ISCO není seznam.");
        }
        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new \UnexpectedValueException("Pole {$field} klasifikace CZ-ISCO obsahuje neplatný řádek.");
            }
        }
        /** @var list<array<string,mixed>> $value */
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function text(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException("Pole {$field} klasifikace CZ-ISCO není text.");
        }

        return $value;
    }
}
