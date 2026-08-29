<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;

/** Úplná sada odvozených hashů, které se po remapu musí znovu zapečetit. */
final readonly class CompanyBackupDerivedHashSet
{
    /** @var list<CompanyBackupDerivedHash> */
    public array $hashes;

    /** @var list<CompanyBackupDerivedHash> */
    private array $refreshOrder;

    /**
     * @param list<CompanyBackupDerivedHash> $hashes
     * @param list<CompanyBackupDerivedHash> $refreshOrder
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
        $claimed = [];
        foreach ($metadata as $value) {
            $hash = CompanyBackupDerivedHash::fromArray($value, $registryKey);
            foreach ([$hash->sourceColumn, $hash->hashColumn] as $column) {
                if (!isset($exported[$column]) || isset($claimed[$column])) {
                    throw self::metadataError($registryKey, $column);
                }
                $claimed[$column] = true;
            }
            $hashes[] = $hash;
        }
        $ordered = $hashes;
        usort(
            $ordered,
            static fn (
                CompanyBackupDerivedHash $left,
                CompanyBackupDerivedHash $right,
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
        foreach ($this->hashes as $derivedHash) {
            [$source, $hash] = $this->pair($row, $derivedHash);
            if ($source === null && $hash === null && $derivedHash->nullable) {
                continue;
            }
            $canonical = $this->canonicalSource($source, $derivedHash);
            if (!is_string($hash)
                || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1
                || !hash_equals($canonical, $source)
                || !hash_equals(hash('sha256', $canonical), $hash)
            ) {
                throw $this->valueError($derivedHash);
            }
        }
        foreach ($this->hashes as $derivedHash) {
            [$source] = $this->pair($row, $derivedHash);
            if ($source === null) {
                continue;
            }
            $decoded = $this->decodedSource($source, $derivedHash);
            foreach ($derivedHash->dependencies as $dependency) {
                $dependencyHash = $row[$dependency->sourceHashColumn] ?? null;
                $embeddedHash = $this->pathValue($decoded, $dependency->path);
                if (!is_string($dependencyHash)
                    || preg_match('/^[0-9a-f]{64}$/D', $dependencyHash) !== 1
                    || !is_string($embeddedHash)
                    || !hash_equals($dependencyHash, $embeddedHash)
                ) {
                    throw $this->valueError($derivedHash);
                }
            }
        }
    }

    /**
     * Ověří zdroj ještě před změnou a zapečetí až výsledek povolené transformace.
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
        foreach ($this->refreshOrder as $derivedHash) {
            [$source, $hash] = $this->pair($row, $derivedHash);
            if ($source === null && $hash === null && $derivedHash->nullable) {
                continue;
            }
            if (!is_string($hash)
                || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1
            ) {
                throw $this->valueError($derivedHash);
            }
            $decoded = $this->decodedSource($source, $derivedHash);
            foreach ($derivedHash->dependencies as $dependency) {
                $dependencyHash = $row[$dependency->sourceHashColumn] ?? null;
                if (!is_string($dependencyHash)
                    || preg_match('/^[0-9a-f]{64}$/D', $dependencyHash) !== 1
                ) {
                    throw $this->valueError($derivedHash);
                }
                $this->replacePathValue(
                    $decoded,
                    $dependency->path,
                    $dependencyHash,
                    $derivedHash,
                );
            }
            try {
                $canonical = CanonicalJson::encode($decoded);
            } catch (\Throwable $e) {
                throw $this->valueError($derivedHash, $e);
            }
            $row[$derivedHash->sourceColumn] = $canonical;
            $row[$derivedHash->hashColumn] = hash('sha256', $canonical);
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{mixed,mixed}
     */
    private function pair(
        array $row,
        CompanyBackupDerivedHash $derivedHash,
    ): array {
        if (!array_key_exists($derivedHash->sourceColumn, $row)
            || !array_key_exists($derivedHash->hashColumn, $row)
        ) {
            throw $this->valueError($derivedHash);
        }
        $source = $row[$derivedHash->sourceColumn];
        $hash = $row[$derivedHash->hashColumn];
        if (($source === null) !== ($hash === null)
            || ($source === null && !$derivedHash->nullable)
        ) {
            throw $this->valueError($derivedHash);
        }
        return [$source, $hash];
    }

    private function canonicalSource(
        mixed $source,
        CompanyBackupDerivedHash $derivedHash,
    ): string {
        try {
            return CanonicalJson::encode($this->decodedSource($source, $derivedHash));
        } catch (CompanyBackupDataSourceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw $this->valueError($derivedHash, $e);
        }
    }

    /** @return array<mixed> */
    private function decodedSource(
        mixed $source,
        CompanyBackupDerivedHash $derivedHash,
    ): array {
        if (!is_string($source)) {
            throw $this->valueError($derivedHash);
        }
        try {
            $decoded = json_decode($source, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \UnexpectedValueException(
                    'Zdroj odvozeného hashe musí být JSON objekt nebo seznam.',
                );
            }
            return match ($derivedHash->algorithm) {
                CompanyBackupDerivedHashAlgorithm::Sha256CanonicalJson => $decoded,
            };
        } catch (\Throwable $e) {
            throw $this->valueError($derivedHash, $e);
        }
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $path
     */
    private function pathValue(array $value, array $path): mixed
    {
        $current = $value;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $path
     */
    private function replacePathValue(
        array &$value,
        array $path,
        string $replacement,
        CompanyBackupDerivedHash $derivedHash,
    ): void {
        $current =& $value;
        $last = array_pop($path);
        foreach ($path as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                throw $this->valueError($derivedHash);
            }
            $current =& $current[$segment];
        }
        if ($last === null
            || !array_key_exists($last, $current)
            || !is_string($current[$last])
            || preg_match('/^[0-9a-f]{64}$/D', $current[$last]) !== 1
        ) {
            throw $this->valueError($derivedHash);
        }
        $current[$last] = $replacement;
    }

    /**
     * @param list<CompanyBackupDerivedHash> $hashes
     * @return list<CompanyBackupDerivedHash>
     */
    private static function refreshOrder(array $hashes, string $registryKey): array
    {
        $byHashColumn = [];
        foreach ($hashes as $hash) {
            $byHashColumn[$hash->hashColumn] = $hash;
        }

        /** @var array<string,list<string>> $dependants */
        $dependants = [];
        /** @var array<string,int> $incoming */
        $incoming = [];
        foreach ($hashes as $hash) {
            $incoming[$hash->hashColumn] = 0;
        }
        foreach ($hashes as $hash) {
            foreach ($hash->dependencies as $dependency) {
                $source = $dependency->sourceHashColumn;
                if (!isset($byHashColumn[$source]) || $source === $hash->hashColumn) {
                    throw self::metadataError($registryKey, $source);
                }
                $dependants[$source][] = $hash->hashColumn;
                $incoming[$hash->hashColumn]++;
            }
        }

        $ready = array_keys(array_filter(
            $incoming,
            static fn (int $count): bool => $count === 0,
        ));
        sort($ready, SORT_STRING);
        $ordered = [];
        while ($ready !== []) {
            $column = array_shift($ready);
            $ordered[] = $byHashColumn[$column];
            foreach ($dependants[$column] ?? [] as $dependant) {
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

    private function valueError(
        CompanyBackupDerivedHash $derivedHash,
        ?\Throwable $previous = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_derived_hash_value_invalid',
            $this->registryKey,
            $derivedHash->hashColumn,
            $previous,
        );
    }

    private static function metadataError(
        string $registryKey,
        ?string $column = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_derived_hash_metadata_invalid',
            $registryKey,
            $column,
        );
    }
}
