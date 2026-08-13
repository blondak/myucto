<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class JmhzExternalCodebookCatalog
{
    public const DEFAULT_OVERLAY_KEY =
        'jmhz-external-codebooks-cisob-2026_czemalfa-2026-08-13-v1';
    public const DEFAULT_MANIFEST_SHA256 =
        '851a6405fd05840743f521e3d1cee250db26b8573653e3b51b34391d79e68c4b';

    private const DIRECTORY = 'external-codebooks-2026-08-13';
    private const SNAPSHOT_DATE = '2026-08-13';
    private const EFFECTIVE_FROM = '2026-01-01';
    private const VERIFIED_THROUGH = '2026-08-13';

    /** @var array{manifest_sha256:string,payload:array<string,mixed>}|null */
    private ?array $manifest = null;
    /** @var array<string, array<string, array<string,mixed>>>|null */
    private ?array $entries = null;

    public function __construct(
        private readonly JmhzSpecPackageCatalog $specPackages,
        private readonly ?string $resourceRoot = null,
    ) {}

    private function load(): void
    {
        if ($this->manifest !== null) {
            return;
        }
        $root = $this->resourceRoot ?? dirname(__DIR__, 5) . '/resources/payroll/jmhz';
        $directory = $root . DIRECTORY_SEPARATOR . self::DIRECTORY;
        $json = file_get_contents($directory . DIRECTORY_SEPARATOR . 'manifest.json');
        if ($json === false) {
            throw new \RuntimeException('Manifest externích číselníků JMHZ nelze načíst.');
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_string($decoded['manifest_sha256'] ?? null)
            || !is_array($decoded['payload'] ?? null)
        ) {
            throw new \UnexpectedValueException('Manifest externích číselníků JMHZ nemá očekávanou strukturu.');
        }
        self::validateManifest($decoded, true);
        $base = $decoded['payload']['base_spec'];
        $spec = $this->specPackages->load($base['package_key'], $base['manifest_sha256']);
        if (!hash_equals($base['manifest_sha256'], $spec['manifest_sha256'])) {
            throw new \UnexpectedValueException('Overlay externích číselníků neodpovídá základní specifikaci JMHZ.');
        }
        $this->verifySources($directory, $decoded['payload']);
        $entries = [];
        foreach ($decoded['payload']['codebooks'] as $codebook) {
            $key = $codebook['codebook_key'];
            foreach ($codebook['entries'] as $entry) {
                $entries[$key][$entry['item_code']] = $entry;
            }
        }
        $this->entries = $entries;
        $this->manifest = $decoded;
    }

    /** @return array{manifest_sha256:string,payload:array<string,mixed>} */
    public function manifest(): array
    {
        $this->load();
        if ($this->manifest === null) {
            throw new \LogicException('Manifest externích číselníků JMHZ nebyl načten.');
        }
        return $this->manifest;
    }

    /** @return array<string,mixed> */
    public function requireMunicipality(string $code, string $name, string $validOn): array
    {
        $this->load();
        $this->assertCovered('obce', $validOn);
        $entry = $this->codebookEntries('obce')[$code] ?? null;
        if ($entry === null || !$this->entryCovers($entry, $validOn)) {
            throw new JmhzCodebookValueException("Kód obce {$code} není v připnutém číselníku CISOB platný.");
        }
        if (!hash_equals($entry['label'], $name)) {
            throw new JmhzCodebookValueException(
                "Název obce neodpovídá připnutému číselníku CISOB pro kód {$code}.",
            );
        }

        return $entry;
    }

    /** @return array<string,mixed> */
    public function requireCountry(string $code, string $validOn): array
    {
        $this->load();
        $this->assertCovered('stat', $validOn);
        $entry = $this->codebookEntries('stat')[$code] ?? null;
        if ($entry === null || !$this->entryCovers($entry, $validOn)) {
            throw new JmhzCodebookValueException("Kód státu {$code} není v připnutém číselníku CZEMALFA platný.");
        }

        return $entry;
    }

    /** @return array<string,mixed> */
    public function requireKnownMunicipality(string $code, string $name, string $termEffectiveOn): array
    {
        $this->assertTermNotBeforeOverlay($termEffectiveOn);
        return $this->requireMunicipality($code, $name, self::SNAPSHOT_DATE);
    }

    /** @return array<string,mixed> */
    public function requireKnownCountry(string $code, string $termEffectiveOn): array
    {
        $this->assertTermNotBeforeOverlay($termEffectiveOn);
        return $this->requireCountry($code, self::SNAPSHOT_DATE);
    }

    /** @return list<array{code:string,label:string}> */
    public function countries(string $validOn): array
    {
        $this->load();
        $this->assertCovered('stat', $validOn);
        $result = [];
        foreach ($this->codebookEntries('stat') as $entry) {
            if ($this->entryCovers($entry, $validOn)) {
                $result[] = ['code' => $entry['item_code'], 'label' => $entry['label']];
            }
        }
        usort(
            $result,
            static fn (array $left, array $right): int => strnatcasecmp($left['label'], $right['label'])
                ?: strcmp($left['code'], $right['code']),
        );

        return $result;
    }

    /** @return list<array{code:string,label:string}> */
    public function searchMunicipalities(string $query, string $validOn, int $limit): array
    {
        $this->load();
        $this->assertCovered('obce', $validOn);
        if ($limit < 1 || $limit > 50) {
            throw new \InvalidArgumentException('Limit vyhledávání obcí musí být od 1 do 50.');
        }
        $needle = mb_strtolower(trim($query), 'UTF-8');
        if (mb_strlen($needle, 'UTF-8') < 2) {
            throw new \InvalidArgumentException('Pro vyhledání obce zadejte alespoň dva znaky.');
        }
        $result = [];
        foreach ($this->codebookEntries('obce') as $entry) {
            if (!$this->entryCovers($entry, $validOn)
                || (!str_contains($entry['item_code'], $needle)
                    && !str_contains(mb_strtolower($entry['label'], 'UTF-8'), $needle))
            ) {
                continue;
            }
            $result[] = ['code' => $entry['item_code'], 'label' => $entry['label']];
        }
        usort(
            $result,
            static fn (array $left, array $right): int => strnatcasecmp($left['label'], $right['label'])
                ?: strcmp($left['code'], $right['code']),
        );

        return array_slice($result, 0, $limit);
    }

    /** @return list<array{code:string,label:string}> */
    public function searchKnownMunicipalities(string $query, int $limit): array
    {
        return $this->searchMunicipalities($query, self::SNAPSHOT_DATE, $limit);
    }

    /** @return array{overlay_key:string,manifest_sha256:string,snapshot_date:string,effective_from:string,verified_through:string,base_spec_manifest_sha256:string} */
    public function provenance(): array
    {
        $this->load();
        if ($this->manifest === null) {
            throw new \LogicException('Manifest externích číselníků JMHZ nebyl načten.');
        }
        $payload = $this->manifest['payload'];
        $municipalities = $payload['codebooks'][0];

        return [
            'overlay_key' => $payload['overlay_key'],
            'manifest_sha256' => $this->manifest['manifest_sha256'],
            'snapshot_date' => $payload['snapshot_date'],
            'effective_from' => $municipalities['effective_from'],
            'verified_through' => $municipalities['verified_through'],
            'base_spec_manifest_sha256' => $payload['base_spec']['manifest_sha256'],
        ];
    }

    /** @param array{manifest_sha256:string,payload:array<string,mixed>} $manifest */
    public static function validateManifest(array $manifest, bool $requirePinnedHash = false): void
    {
        $payload = $manifest['payload'];
        $actualHash = hash('sha256', CanonicalJson::encode($payload));
        if (!hash_equals($manifest['manifest_sha256'], $actualHash)
            || ($requirePinnedHash && !hash_equals(self::DEFAULT_MANIFEST_SHA256, $actualHash))
        ) {
            throw new \UnexpectedValueException('Manifest externích číselníků JMHZ nemá připnutý SHA-256.');
        }
        if (($payload['schema_version'] ?? null) !== 'jmhz-external-codebook-overlay.v1'
            || ($payload['overlay_key'] ?? null) !== self::DEFAULT_OVERLAY_KEY
            || ($payload['snapshot_date'] ?? null) !== self::SNAPSHOT_DATE
            || ($payload['parser_version'] ?? null) !== 1
            || ($payload['usage_policy'] ?? null) !== 'authoritative_validation_only'
        ) {
            throw new \UnexpectedValueException('Manifest externích číselníků JMHZ má neznámou identitu.');
        }
        $base = $payload['base_spec'] ?? null;
        if (!is_array($base)
            || ($base['package_key'] ?? null) !== JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY
            || ($base['manifest_sha256'] ?? null) !== JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256
        ) {
            throw new \UnexpectedValueException('Manifest externích číselníků JMHZ má neplatný základ.');
        }
        $codebooks = self::list($payload['codebooks'] ?? null, 'codebooks');
        $actualCounts = ['codebooks' => count($codebooks), 'municipalities' => 0, 'countries' => 0, 'entries' => 0];
        $keys = [];
        foreach ($codebooks as $codebook) {
            $key = self::string($codebook, 'codebook_key');
            if (!in_array($key, ['obce', 'stat'], true) || isset($keys[$key])) {
                throw new \UnexpectedValueException("Overlay obsahuje neplatný nebo duplicitní číselník {$key}.");
            }
            $keys[$key] = true;
            if (($codebook['effective_from'] ?? null) !== self::EFFECTIVE_FROM
                || ($codebook['effective_to'] ?? null) !== null
                || ($codebook['verified_through'] ?? null) !== self::VERIFIED_THROUGH
            ) {
                throw new \UnexpectedValueException("Číselník {$key} má neplatné období ověření.");
            }
            $entries = self::list($codebook['entries'] ?? null, "codebooks.{$key}.entries");
            if (($codebook['entry_count'] ?? null) !== count($entries)
                || !hash_equals(
                    self::string($codebook, 'content_hash'),
                    hash('sha256', CanonicalJson::encode(['entries' => $entries])),
                )
            ) {
                throw new \UnexpectedValueException("Číselník {$key} má neplatný počet nebo hash položek.");
            }
            $codes = [];
            foreach ($entries as $ordinal => $entry) {
                $code = self::string($entry, 'item_code');
                $pattern = $key === 'obce' ? '/\A[0-9]{6}\z/D' : '/\A[A-Z]{2}\z/D';
                if (preg_match($pattern, $code) !== 1 || isset($codes[$code])
                    || ($entry['ordinal'] ?? null) !== $ordinal + 1
                    || self::string($entry, 'label') !== trim(self::string($entry, 'label'))
                    || !self::date($entry['valid_from'] ?? null)
                    || (($entry['valid_to'] ?? null) !== null && !self::date($entry['valid_to']))
                    || (($entry['valid_to'] ?? null) !== null && $entry['valid_to'] < $entry['valid_from'])
                ) {
                    throw new \UnexpectedValueException("Číselník {$key} má neplatnou položku {$code}.");
                }
                $codes[$code] = true;
                $rowHash = self::string($entry, 'row_hash');
                $withoutHash = $entry;
                unset($withoutHash['row_hash']);
                if (!hash_equals($rowHash, hash('sha256', CanonicalJson::encode($withoutHash)))) {
                    throw new \UnexpectedValueException("Položka {$key}/{$code} má neplatný hash.");
                }
            }
            $countKey = $key === 'obce' ? 'municipalities' : 'countries';
            $actualCounts[$countKey] = count($entries);
            $actualCounts['entries'] += count($entries);
        }
        if (array_keys($keys) !== ['obce', 'stat']
            || CanonicalJson::encode($payload['counts'] ?? null) !== CanonicalJson::encode($actualCounts)
        ) {
            throw new \UnexpectedValueException('Overlay externích číselníků JMHZ nemá úplný obsah.');
        }
    }

    /** @param array<string,mixed> $payload */
    private function verifySources(string $directory, array $payload): void
    {
        foreach (self::list($payload['sources'] ?? null, 'sources') as $source) {
            $filename = self::string($source, 'filename');
            if (basename($filename) !== $filename) {
                throw new \UnexpectedValueException('Zdroj overlay externích číselníků má neplatnou cestu.');
            }
            $path = $directory . DIRECTORY_SEPARATOR . $filename;
            $hash = hash_file('sha256', $path);
            if (!is_string($hash) || !hash_equals(self::string($source, 'sha256'), $hash)
                || ($source['byte_length'] ?? null) !== filesize($path)
            ) {
                throw new \UnexpectedValueException("Zdroj externího číselníku {$filename} neodpovídá manifestu.");
            }
        }
    }

    private function assertCovered(string $codebookKey, string $validOn): void
    {
        if ($this->manifest === null) {
            throw new \LogicException('Manifest externích číselníků JMHZ nebyl načten.');
        }
        if (!self::date($validOn)) {
            throw new \InvalidArgumentException('Datum platnosti externího číselníku JMHZ není platné.');
        }
        foreach ($this->manifest['payload']['codebooks'] as $codebook) {
            if ($codebook['codebook_key'] === $codebookKey) {
                if ($validOn < $codebook['effective_from'] || $validOn > $codebook['verified_through']) {
                    throw new JmhzCodebookUnavailableException(
                        "Číselník JMHZ {$codebookKey} není pro datum {$validOn} ověřený.",
                    );
                }
                return;
            }
        }
        throw new JmhzCodebookUnavailableException("Číselník JMHZ {$codebookKey} není v overlay dostupný.");
    }

    private function assertTermNotBeforeOverlay(string $termEffectiveOn): void
    {
        if (!self::date($termEffectiveOn) || $termEffectiveOn < self::EFFECTIVE_FROM) {
            throw new JmhzCodebookUnavailableException(
                "Externí číselníky JMHZ nejsou pro datum {$termEffectiveOn} účinné.",
            );
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function codebookEntries(string $codebookKey): array
    {
        $allEntries = $this->entries;
        $entries = is_array($allEntries) ? ($allEntries[$codebookKey] ?? null) : null;
        if (!is_array($entries)) {
            throw new JmhzCodebookUnavailableException(
                "Číselník JMHZ {$codebookKey} není v overlay dostupný.",
            );
        }
        return $entries;
    }

    /** @param array<string,mixed> $entry */
    private function entryCovers(array $entry, string $validOn): bool
    {
        return $entry['valid_from'] <= $validOn
            && ($entry['valid_to'] === null || $entry['valid_to'] >= $validOn);
    }

    /** @return list<array<string,mixed>> */
    private static function list(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("Pole {$field} overlay externích číselníků není seznam.");
        }
        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new \UnexpectedValueException("Pole {$field} obsahuje neplatný řádek.");
            }
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException("Pole {$field} overlay externích číselníků není text.");
        }

        return $value;
    }

    private static function date(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }
}
