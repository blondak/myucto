<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;

/** Jedno explicitní pole kanonické projekce fyzického DB řádku. */
final readonly class CompanyBackupDerivedHashProjectionField
{
    private function __construct(
        public string $key,
        public ?string $column,
        public ?string $jsonColumn,
        public bool $hasLiteral,
        public mixed $literal,
    ) {}

    public static function fromArray(mixed $value, string $registryKey): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($registryKey);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['column', 'key']
            && $keys !== ['json_column', 'key']
            && $keys !== ['key', 'literal']
        ) {
            throw self::invalid($registryKey);
        }
        $key = $value['key'];
        if (!is_string($key) || !self::isIdentifier($key)) {
            throw self::invalid($registryKey);
        }
        if (array_key_exists('literal', $value)) {
            $literal = $value['literal'];
            if (!is_string($literal)
                && !is_int($literal)
                && !is_bool($literal)
                && $literal !== null
            ) {
                throw self::invalid($registryKey);
            }
            return new self($key, null, null, true, $literal);
        }

        $column = $value['column'] ?? null;
        $jsonColumn = $value['json_column'] ?? null;
        if (($column !== null && (!is_string($column) || !self::isIdentifier($column)))
            || ($jsonColumn !== null
                && (!is_string($jsonColumn) || !self::isIdentifier($jsonColumn)))
            || ($column === null) === ($jsonColumn === null)
        ) {
            throw self::invalid($registryKey);
        }
        return new self($key, $column, $jsonColumn, false, null);
    }

    public function sourceColumn(): ?string
    {
        return $this->column ?? $this->jsonColumn;
    }

    public function signature(): string
    {
        if ($this->hasLiteral) {
            return $this->key . '=literal:' . CanonicalJson::encode($this->literal);
        }
        if ($this->column !== null) {
            return $this->key . '=column:' . $this->column;
        }
        return $this->key . '=json_column:' . $this->jsonColumn;
    }

    private static function isIdentifier(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) === 1;
    }

    private static function invalid(
        string $registryKey,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_derived_hash_metadata_invalid',
            $registryKey,
        );
    }
}
