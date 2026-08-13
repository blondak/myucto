<?php

declare(strict_types=1);

namespace MyInvoice\Tooling;

use RuntimeException;

/**
 * Rozdíl mezi připnutým a čerstvě postaveným manifestem číselníků JMHZ. Výstup je
 * strojově čitelný a slouží člověku jako podklad k rozhodnutí — nikdy se neaplikuje
 * automaticky a nečte se z něj za běhu aplikace.
 */
final class JmhzCodebookManifestDiff
{
    public const SCHEMA_VERSION = 'jmhz-codebook-change-report.v1';

    /**
     * @param array<string,mixed> $pinned
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    public static function between(array $pinned, array $candidate): array
    {
        $pinnedCodebooks = self::codebooks($pinned, 'připnutý');
        $candidateCodebooks = self::codebooks($candidate, 'kandidátský');

        $keys = array_values(array_unique(array_merge(
            array_keys($pinnedCodebooks),
            array_keys($candidateCodebooks),
        )));
        sort($keys, SORT_STRING);

        $codebooks = [];
        $totals = [
            'added_codebooks' => 0,
            'removed_codebooks' => 0,
            'changed_codebooks' => 0,
            'added_items' => 0,
            'removed_items' => 0,
            'changed_items' => 0,
        ];
        foreach ($keys as $key) {
            $before = $pinnedCodebooks[$key] ?? null;
            $after = $candidateCodebooks[$key] ?? null;
            if ($before === null) {
                ++$totals['added_codebooks'];
                $codebooks[] = self::wholeCodebook($key, 'added', $after ?? []);
                $totals['added_items'] += count(self::items($after ?? []));
                continue;
            }
            if ($after === null) {
                ++$totals['removed_codebooks'];
                $codebooks[] = self::wholeCodebook($key, 'removed', $before);
                $totals['removed_items'] += count(self::items($before));
                continue;
            }
            $entry = self::compare($key, $before, $after);
            if ($entry === null) {
                continue;
            }
            ++$totals['changed_codebooks'];
            $totals['added_items'] += count($entry['added_item_codes']);
            $totals['removed_items'] += count($entry['removed_item_codes']);
            $totals['changed_items'] += count($entry['changed_items']);
            $codebooks[] = $entry;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'pinned' => self::identity($pinned),
            'candidate' => self::identity($candidate),
            'changed' => $codebooks !== [] || self::identity($pinned) !== self::identity($candidate),
            'counts' => $totals,
            'codebooks' => $codebooks,
        ];
    }

    /** @param array<string,mixed> $report */
    public static function write(array $report, string $path): void
    {
        $json = json_encode(
            $report,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
        if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) {
            throw new RuntimeException("Report změn číselníků {$path} nelze zapsat.");
        }
    }

    /** @return array<string,mixed> */
    public static function load(string $path): array
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Manifest {$path} nelze načíst.");
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException("Manifest {$path} nemá očekávanou strukturu.");
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,array<string,mixed>>
     */
    private static function codebooks(array $manifest, string $label): array
    {
        $payload = $manifest['payload'] ?? null;
        $codebooks = is_array($payload) ? ($payload['codebooks'] ?? null) : null;
        if (!is_array($codebooks)) {
            throw new RuntimeException("Manifest ({$label}) neobsahuje číselníky.");
        }
        $result = [];
        foreach ($codebooks as $codebook) {
            if (!is_array($codebook) || !is_string($codebook['codebook_key'] ?? null)) {
                throw new RuntimeException("Manifest ({$label}) obsahuje neplatný číselník.");
            }
            /** @var array<string,mixed> $codebook */
            $result[$codebook['codebook_key']] = $codebook;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private static function identity(array $manifest): array
    {
        $payload = $manifest['payload'] ?? null;
        $payload = is_array($payload) ? $payload : [];
        $identity = [
            'manifest_sha256' => is_string($manifest['manifest_sha256'] ?? null)
                ? $manifest['manifest_sha256']
                : null,
            'schema_version' => is_string($payload['schema_version'] ?? null)
                ? $payload['schema_version']
                : null,
            'package_key' => is_string($payload['package_key'] ?? null) ? $payload['package_key'] : null,
            'overlay_key' => is_string($payload['overlay_key'] ?? null) ? $payload['overlay_key'] : null,
            'versions' => is_array($payload['versions'] ?? null) ? $payload['versions'] : null,
            'snapshot_date' => is_string($payload['snapshot_date'] ?? null) ? $payload['snapshot_date'] : null,
            'counts' => is_array($payload['counts'] ?? null) ? $payload['counts'] : null,
        ];

        return $identity;
    }

    /**
     * @param array<string,mixed> $codebook
     * @return array<string,mixed>
     */
    private static function wholeCodebook(string $key, string $status, array $codebook): array
    {
        $items = self::items($codebook);
        $codes = array_keys($items);
        sort($codes, SORT_STRING);

        return [
            'codebook_key' => $key,
            'status' => $status,
            'source_kind' => [
                'pinned' => $status === 'removed' ? self::sourceKind($codebook) : null,
                'candidate' => $status === 'added' ? self::sourceKind($codebook) : null,
            ],
            'entry_count' => [
                'pinned' => $status === 'removed' ? count($items) : null,
                'candidate' => $status === 'added' ? count($items) : null,
            ],
            'added_item_codes' => $status === 'added' ? $codes : [],
            'removed_item_codes' => $status === 'removed' ? $codes : [],
            'changed_items' => [],
        ];
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array{
     *     codebook_key:string,
     *     status:string,
     *     source_kind:array{pinned:string|null,candidate:string|null},
     *     entry_count:array{pinned:int|null,candidate:int|null},
     *     added_item_codes:list<string>,
     *     removed_item_codes:list<string>,
     *     changed_items:list<array<string,mixed>>
     * }|null
     */
    private static function compare(string $key, array $before, array $after): ?array
    {
        $beforeItems = self::items($before);
        $afterItems = self::items($after);
        $added = array_keys(array_diff_key($afterItems, $beforeItems));
        $removed = array_keys(array_diff_key($beforeItems, $afterItems));
        sort($added, SORT_STRING);
        sort($removed, SORT_STRING);

        $changed = [];
        foreach ($beforeItems as $code => $item) {
            $other = $afterItems[$code] ?? null;
            if ($other === null) {
                continue;
            }
            $beforeHash = self::rowHash($item);
            $afterHash = self::rowHash($other);
            if ($beforeHash === $afterHash) {
                continue;
            }
            $changed[] = [
                'item_code' => (string) $code,
                'pinned_row_hash' => $beforeHash,
                'candidate_row_hash' => $afterHash,
                'pinned_label' => is_string($item['label'] ?? null) ? $item['label'] : null,
                'candidate_label' => is_string($other['label'] ?? null) ? $other['label'] : null,
            ];
        }
        usort($changed, static fn (array $a, array $b): int => strcmp(
            (string) $a['item_code'],
            (string) $b['item_code'],
        ));

        $sourceKindChanged = self::sourceKind($before) !== self::sourceKind($after);
        $contentHashChanged = (($before['content_hash'] ?? null) !== ($after['content_hash'] ?? null));
        if ($added === [] && $removed === [] && $changed === [] && !$sourceKindChanged && !$contentHashChanged) {
            return null;
        }

        return [
            'codebook_key' => $key,
            'status' => 'changed',
            'source_kind' => [
                'pinned' => self::sourceKind($before),
                'candidate' => self::sourceKind($after),
            ],
            'entry_count' => [
                'pinned' => count($beforeItems),
                'candidate' => count($afterItems),
            ],
            'added_item_codes' => array_map(strval(...), $added),
            'removed_item_codes' => array_map(strval(...), $removed),
            'changed_items' => $changed,
        ];
    }

    /**
     * @param array<string,mixed> $codebook
     * @return array<string,array<string,mixed>>
     */
    private static function items(array $codebook): array
    {
        $entries = $codebook['entries'] ?? null;
        if (!is_array($entries)) {
            return [];
        }
        $result = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['item_code'] ?? null)) {
                continue;
            }
            /** @var array<string,mixed> $entry */
            $result[$entry['item_code']] = $entry;
        }

        return $result;
    }

    /** @param array<string,mixed> $codebook */
    private static function sourceKind(array $codebook): ?string
    {
        return is_string($codebook['source_kind'] ?? null) ? $codebook['source_kind'] : null;
    }

    /** @param array<string,mixed> $item */
    private static function rowHash(array $item): ?string
    {
        return is_string($item['row_hash'] ?? null) ? $item['row_hash'] : null;
    }
}
