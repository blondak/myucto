<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;

/** Úplná sada odvozených hashů, které se po remapu musí znovu zapečetit. */
final readonly class CompanyBackupDerivedHashSet
{
    /** @var list<CompanyBackupDerivedHash> */
    public array $hashes;

    /** @param list<CompanyBackupDerivedHash> $hashes */
    private function __construct(
        public string $registryKey,
        array $hashes,
    ) {
        $this->hashes = $hashes;
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

        return new self($registryKey, $hashes);
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
        foreach ($this->hashes as $derivedHash) {
            [$source, $hash] = $this->pair($row, $derivedHash);
            if ($source === null && $hash === null && $derivedHash->nullable) {
                continue;
            }
            if (!is_string($hash)
                || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1
            ) {
                throw $this->valueError($derivedHash);
            }
            $canonical = $this->canonicalSource($source, $derivedHash);
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
                CompanyBackupDerivedHashAlgorithm::Sha256CanonicalJson =>
                    CanonicalJson::encode($decoded),
            };
        } catch (\Throwable $e) {
            throw $this->valueError($derivedHash, $e);
        }
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
