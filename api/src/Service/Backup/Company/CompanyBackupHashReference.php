<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;

/** Odkaz z fyzického hashového sloupce na odvozený hash jiného řádku. */
final readonly class CompanyBackupHashReference
{
    private function __construct(
        public string $column,
        public string $target,
        public string $targetHashColumn,
        public bool $nullable,
    ) {}

    public static function fromArray(mixed $value, string $registryKey): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($registryKey);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'column',
            'nullable',
            'target',
            'target_hash_column',
        ]) {
            throw self::invalid($registryKey);
        }

        $column = $value['column'];
        $target = $value['target'];
        $targetHashColumn = $value['target_hash_column'];
        $nullable = $value['nullable'];
        if (!is_string($column)
            || !self::isIdentifier($column)
            || !is_string($target)
            || !str_starts_with($target, 'table:')
            || !TenantDataDefinition::isValidKey($target)
            || !is_string($targetHashColumn)
            || !self::isIdentifier($targetHashColumn)
            || !is_bool($nullable)
        ) {
            throw self::invalid(
                $registryKey,
                is_string($column) ? $column : null,
            );
        }

        return new self($column, $target, $targetHashColumn, $nullable);
    }

    public function signature(): string
    {
        return $this->column
            . '->'
            . $this->targetTable()
            . ':'
            . $this->targetHashColumn
            . ($this->nullable ? '?' : '!');
    }

    public function targetTable(): string
    {
        return substr($this->target, strlen('table:'));
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
            'data_hash_reference_metadata_invalid',
            $registryKey,
            $column,
        );
    }
}
