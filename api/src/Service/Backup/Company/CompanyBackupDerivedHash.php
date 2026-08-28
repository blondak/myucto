<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Jedna fingerprintovaná vazba JSON sloupce na jeho odvozený hash. */
final readonly class CompanyBackupDerivedHash
{
    private function __construct(
        public CompanyBackupDerivedHashAlgorithm $algorithm,
        public string $hashColumn,
        public bool $nullable,
        public string $sourceColumn,
    ) {}

    public static function fromArray(mixed $value, string $registryKey): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($registryKey);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['algorithm', 'hash_column', 'nullable', 'source_column']) {
            throw self::invalid($registryKey);
        }

        $algorithmValue = $value['algorithm'];
        $algorithm = is_string($algorithmValue)
            ? CompanyBackupDerivedHashAlgorithm::tryFrom($algorithmValue)
            : null;
        $hashColumn = $value['hash_column'];
        $nullable = $value['nullable'];
        $sourceColumn = $value['source_column'];
        if ($algorithm === null
            || !is_string($hashColumn)
            || !self::isIdentifier($hashColumn)
            || !is_bool($nullable)
            || !is_string($sourceColumn)
            || !self::isIdentifier($sourceColumn)
            || $sourceColumn === $hashColumn
        ) {
            throw self::invalid(
                $registryKey,
                is_string($hashColumn) ? $hashColumn : null,
            );
        }

        return new self($algorithm, $hashColumn, $nullable, $sourceColumn);
    }

    public function signature(): string
    {
        return $this->hashColumn
            . '<-'
            . $this->algorithm->value
            . ':'
            . $this->sourceColumn
            . ($this->nullable ? '?' : '!');
    }

    private static function isIdentifier(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) === 1;
    }

    private static function invalid(
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
