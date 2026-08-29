<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;

/** Úplná sada vnořených pečetí, které se po remapu musí znovu odvodit. */
final readonly class CompanyBackupEmbeddedHashSet
{
    /** @var list<CompanyBackupEmbeddedHash> */
    public array $hashes;

    /** @var list<CompanyBackupEmbeddedHash> */
    private array $refreshOrder;

    /**
     * @param list<CompanyBackupEmbeddedHash> $hashes
     * @param list<CompanyBackupEmbeddedHash> $refreshOrder
     */
    private function __construct(
        public string $registryKey,
        array $hashes,
        array $refreshOrder,
    ) {
        $this->hashes = $hashes;
        $this->refreshOrder = $refreshOrder;
    }

    /** @param list<string> $dataColumns */
    public static function fromArray(
        mixed $metadata,
        string $registryKey,
        array $dataColumns,
    ): self {
        if (!is_array($metadata) || !array_is_list($metadata)) {
            throw self::metadataError($registryKey);
        }
        $exported = array_fill_keys($dataColumns, true);
        $hashes = [];
        $names = [];
        $hashPaths = [];
        foreach ($metadata as $value) {
            $hash = CompanyBackupEmbeddedHash::fromArray($value, $registryKey);
            $path = $hash->column . ':' . implode('.', $hash->hashPath);
            if (!isset($exported[$hash->column])
                || isset($names[$hash->name])
                || isset($hashPaths[$path])
            ) {
                throw self::metadataError($registryKey, $hash->column);
            }
            $names[$hash->name] = true;
            $hashPaths[$path] = true;
            $hashes[] = $hash;
        }
        $ordered = $hashes;
        usort(
            $ordered,
            static fn (
                CompanyBackupEmbeddedHash $left,
                CompanyBackupEmbeddedHash $right,
            ): int => strcmp($left->signature(), $right->signature()),
        );
        if ($ordered !== $hashes) {
            throw self::metadataError($registryKey);
        }

        return new self(
            $registryKey,
            $hashes,
            self::refreshOrder($hashes, $registryKey),
        );
    }

    /** @param array<string,mixed> $row */
    public function assertSourceRow(array $row): void
    {
        foreach ($this->hashes as $hash) {
            [$document] = $this->decodedColumn($row, $hash);
            if ($document === null) {
                continue;
            }
            foreach ($this->pairs($document, $hash) as $pair) {
                $expected = $this->digest(
                    $document,
                    $pair['source'],
                    $pair['bindings'],
                    $hash,
                );
                if (!self::validDigest($pair['hash'])
                    || !hash_equals($expected, $pair['hash'])
                ) {
                    throw $this->valueError($hash);
                }
            }
        }
    }

    /**
     * Ověří původní pečetě a znovu je odvodí až po povolené transformaci.
     *
     * @param array<string,mixed> $row
     * @param callable(array<string,mixed>):array<string,mixed> $transformer
     * @return array<string,mixed>
     */
    public function transform(array $row, callable $transformer): array
    {
        $this->assertSourceRow($row);
        return $this->refresh($transformer($row));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function refresh(array $row): array
    {
        foreach ($this->refreshOrder as $hash) {
            [$document, $encoded] = $this->decodedColumn($row, $hash);
            if ($document === null) {
                continue;
            }
            foreach ($this->pairs($document, $hash) as $pair) {
                if (!self::validDigest($pair['hash'])) {
                    throw $this->valueError($hash);
                }
                $this->replacePathValue(
                    $document,
                    $hash->hashPath,
                    $pair['bindings'],
                    $this->digest(
                        $document,
                        $pair['source'],
                        $pair['bindings'],
                        $hash,
                    ),
                    $hash,
                );
            }
            if ($encoded) {
                try {
                    $row[$hash->column] = CanonicalJson::encode($document);
                } catch (\Throwable $e) {
                    throw $this->valueError($hash, $e);
                }
            } else {
                $row[$hash->column] = $document;
            }
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{array<mixed>|null,bool}
     */
    private function decodedColumn(
        array $row,
        CompanyBackupEmbeddedHash $hash,
    ): array {
        if (!array_key_exists($hash->column, $row)) {
            throw $this->valueError($hash);
        }
        $raw = $row[$hash->column];
        if ($raw === null && $hash->nullable) {
            return [null, false];
        }
        if (is_array($raw)) {
            return [$raw, false];
        }
        if (!is_string($raw)) {
            throw $this->valueError($hash);
        }
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($decoded)
                || !hash_equals(CanonicalJson::encode($decoded), $raw)
            ) {
                throw new \UnexpectedValueException(
                    'Sloupec s vnořenou pečetí musí obsahovat kanonický JSON.',
                );
            }
            return [$decoded, true];
        } catch (\Throwable $e) {
            throw $this->valueError($hash, $e);
        }
    }

    /**
     * @param array<mixed> $document
     * @return list<array{
     *   bindings:list<int|string>,
     *   hash:mixed,
     *   source:mixed
     * }>
     */
    private function pairs(
        array $document,
        CompanyBackupEmbeddedHash $hash,
    ): array {
        $sources = $this->occurrences($document, $hash->sourcePath, $hash);
        $seals = $this->occurrences($document, $hash->hashPath, $hash);
        $keys = array_values(array_unique([
            ...array_keys($sources),
            ...array_keys($seals),
        ]));
        sort($keys, SORT_STRING);
        $pairs = [];
        foreach ($keys as $key) {
            $source = $sources[$key] ?? null;
            $seal = $seals[$key] ?? null;
            if ($source === null
                || $seal === null
                || $source['present'] !== $seal['present']
            ) {
                throw $this->valueError($hash);
            }
            if (!$source['present']) {
                if (!$hash->nullable) {
                    throw $this->valueError($hash);
                }
                continue;
            }
            if (($source['value'] === null) !== ($seal['value'] === null)) {
                throw $this->valueError($hash);
            }
            if ($source['value'] === null) {
                if (!$hash->nullable) {
                    throw $this->valueError($hash);
                }
                continue;
            }
            $pairs[] = [
                'bindings' => $source['bindings'],
                'hash' => $seal['value'],
                'source' => $source['value'],
            ];
        }
        return $pairs;
    }

    /**
     * @param array<mixed> $document
     * @param list<string> $path
     * @return array<string,array{
     *   bindings:list<int|string>,
     *   present:bool,
     *   value:mixed
     * }>
     */
    private function occurrences(
        array $document,
        array $path,
        CompanyBackupEmbeddedHash $hash,
    ): array {
        $result = [];
        $this->collectOccurrences($document, $path, 0, [], $hash, $result);
        return $result;
    }

    /**
     * @param list<string> $path
     * @param list<int|string> $bindings
     * @param array<string,array{
     *   bindings:list<int|string>,
     *   present:bool,
     *   value:mixed
     * }> $result
     */
    private function collectOccurrences(
        mixed $value,
        array $path,
        int $index,
        array $bindings,
        CompanyBackupEmbeddedHash $hash,
        array &$result,
    ): void {
        if ($index === count($path)) {
            $result[serialize($bindings)] = [
                'bindings' => $bindings,
                'present' => true,
                'value' => $value,
            ];
            return;
        }
        if ($value === null && $hash->nullable) {
            $result[serialize($bindings)] = [
                'bindings' => $bindings,
                'present' => false,
                'value' => null,
            ];
            return;
        }
        if (!is_array($value)) {
            throw $this->valueError($hash);
        }

        $segment = $path[$index];
        if ($segment === '*') {
            foreach ($value as $key => $item) {
                $this->collectOccurrences(
                    $item,
                    $path,
                    $index + 1,
                    [...$bindings, $key],
                    $hash,
                    $result,
                );
            }
            return;
        }
        if (!array_key_exists($segment, $value)) {
            if (!$hash->nullable) {
                throw $this->valueError($hash);
            }
            $result[serialize($bindings)] = [
                'bindings' => $bindings,
                'present' => false,
                'value' => null,
            ];
            return;
        }
        $this->collectOccurrences(
            $value[$segment],
            $path,
            $index + 1,
            $bindings,
            $hash,
            $result,
        );
    }

    /**
     * @param array<mixed> $document
     * @param list<int|string> $bindings
     */
    private function digest(
        array $document,
        mixed $source,
        array $bindings,
        CompanyBackupEmbeddedHash $hash,
    ): string {
        try {
            return match ($hash->algorithm) {
                CompanyBackupEmbeddedHashAlgorithm::Sha256CanonicalJson =>
                    hash('sha256', $this->canonicalSource($source, $hash)),
                CompanyBackupEmbeddedHashAlgorithm::Sha256CanonicalProjection =>
                    hash('sha256', CanonicalJson::encode(
                        $this->projectedSource(
                            $document,
                            $source,
                            $bindings,
                            $hash,
                        ),
                    )),
                CompanyBackupEmbeddedHashAlgorithm::Sha256ExactString =>
                    is_string($source)
                        ? hash('sha256', $source)
                        : throw new \UnexpectedValueException(
                            'Zdroj exact-string pečeti musí být řetězec.',
                        ),
            };
        } catch (CompanyBackupDataSourceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw $this->valueError($hash, $e);
        }
    }

    /**
     * @param array<mixed> $document
     * @param list<int|string> $bindings
     * @return array<string,mixed>
     */
    private function projectedSource(
        array $document,
        mixed $source,
        array $bindings,
        CompanyBackupEmbeddedHash $hash,
    ): array {
        if (!is_array($source)) {
            throw $this->valueError($hash);
        }
        $result = [];
        foreach ($hash->projection as $field) {
            $result[$field->key] = $field->hasLiteral
                ? $field->literal
                : $this->pathValue($document, $field->path, $bindings, $hash);
        }
        return $result;
    }

    /**
     * @param array<mixed> $document
     * @param list<string> $path
     * @param list<int|string> $bindings
     */
    private function pathValue(
        array $document,
        array $path,
        array $bindings,
        CompanyBackupEmbeddedHash $hash,
    ): mixed {
        $current = $document;
        $wildcard = 0;
        foreach ($path as $segment) {
            $key = $segment;
            if ($segment === '*') {
                if (!array_key_exists($wildcard, $bindings)) {
                    throw $this->valueError($hash);
                }
                $key = $bindings[$wildcard];
                $wildcard++;
            }
            if (!is_array($current) || !array_key_exists($key, $current)) {
                throw $this->valueError($hash);
            }
            $current = $current[$key];
        }
        return $current;
    }

    private function canonicalSource(
        mixed $source,
        CompanyBackupEmbeddedHash $hash,
    ): string {
        if (!is_array($source)) {
            throw $this->valueError($hash);
        }
        foreach ($hash->omitPaths as $path) {
            $this->removePath($source, $path, $hash);
        }
        return CanonicalJson::encode($source);
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $path
     */
    private function removePath(
        array &$value,
        array $path,
        CompanyBackupEmbeddedHash $hash,
    ): void {
        $current =& $value;
        $last = array_pop($path);
        foreach ($path as $segment) {
            if (!array_key_exists($segment, $current)
                || !is_array($current[$segment])
            ) {
                throw $this->valueError($hash);
            }
            $current =& $current[$segment];
        }
        if ($last === null || !array_key_exists($last, $current)) {
            throw $this->valueError($hash);
        }
        unset($current[$last]);
    }

    /**
     * @param array<mixed> $document
     * @param list<string> $path
     * @param list<int|string> $bindings
     */
    private function replacePathValue(
        array &$document,
        array $path,
        array $bindings,
        string $replacement,
        CompanyBackupEmbeddedHash $hash,
    ): void {
        $current =& $document;
        $wildcard = 0;
        $last = array_pop($path);
        foreach ($path as $segment) {
            $key = $segment;
            if ($segment === '*') {
                if (!array_key_exists($wildcard, $bindings)) {
                    throw $this->valueError($hash);
                }
                $key = $bindings[$wildcard];
                $wildcard++;
            }
            if (!array_key_exists($key, $current) || !is_array($current[$key])) {
                throw $this->valueError($hash);
            }
            $current =& $current[$key];
        }
        $key = $last;
        if ($last === '*') {
            if (!array_key_exists($wildcard, $bindings)) {
                throw $this->valueError($hash);
            }
            $key = $bindings[$wildcard];
        }
        if ($key === null
            || !array_key_exists($key, $current)
            || !self::validDigest($current[$key])
        ) {
            throw $this->valueError($hash);
        }
        $current[$key] = $replacement;
    }

    /**
     * @param list<CompanyBackupEmbeddedHash> $hashes
     * @return list<CompanyBackupEmbeddedHash>
     */
    private static function refreshOrder(array $hashes, string $registryKey): array
    {
        $byName = [];
        foreach ($hashes as $hash) {
            $byName[$hash->name] = $hash;
        }

        /** @var array<string,list<string>> $dependants */
        $dependants = [];
        /** @var array<string,int> $incoming */
        $incoming = [];
        foreach ($hashes as $hash) {
            $incoming[$hash->name] = 0;
        }
        foreach ($hashes as $hash) {
            foreach ($hash->dependencies as $dependency) {
                if (!isset($byName[$dependency]) || $dependency === $hash->name) {
                    throw self::metadataError($registryKey, $hash->column);
                }
                $dependants[$dependency][] = $hash->name;
                $incoming[$hash->name]++;
            }
        }

        $ready = array_keys(array_filter(
            $incoming,
            static fn (int $count): bool => $count === 0,
        ));
        sort($ready, SORT_STRING);
        $ordered = [];
        while ($ready !== []) {
            $name = array_shift($ready);
            $ordered[] = $byName[$name];
            foreach ($dependants[$name] ?? [] as $dependant) {
                $incoming[$dependant]--;
                if ($incoming[$dependant] === 0) {
                    $ready[] = $dependant;
                    sort($ready, SORT_STRING);
                }
            }
        }
        if (count($ordered) !== count($hashes)) {
            throw self::metadataError($registryKey);
        }
        return $ordered;
    }

    private static function validDigest(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    private function valueError(
        CompanyBackupEmbeddedHash $hash,
        ?\Throwable $previous = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_embedded_hash_value_invalid',
            $this->registryKey,
            $hash->column,
            $previous,
        );
    }

    private static function metadataError(
        string $registryKey,
        ?string $column = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_embedded_hash_metadata_invalid',
            $registryKey,
            $column,
        );
    }
}
