<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;

/** Odkaz z hashové hodnoty uvnitř JSON na odvozený hash jiného řádku. */
final readonly class CompanyBackupEmbeddedHashReference
{
    /** @var list<string> */
    public array $path;

    /** @param list<string> $path */
    private function __construct(
        public string $column,
        array $path,
        public string $target,
        public string $targetHashColumn,
        public bool $nullable,
    ) {
        $this->path = $path;
    }

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
            'path',
            'target',
            'target_hash_column',
        ]) {
            throw self::invalid($registryKey);
        }

        $column = $value['column'];
        $path = self::path($value['path'], $registryKey);
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

        return new self(
            $column,
            $path,
            $target,
            $targetHashColumn,
            $nullable,
        );
    }

    public function signature(): string
    {
        return $this->column
            . ':'
            . implode('.', $this->path)
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

    /** @return list<string> */
    private static function path(mixed $value, string $registryKey): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw self::invalid($registryKey);
        }
        $path = [];
        foreach ($value as $segment) {
            if (!is_string($segment)
                || ($segment !== '*' && !self::isIdentifier($segment))
            ) {
                throw self::invalid($registryKey);
            }
            $path[] = $segment;
        }
        return $path;
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
            'data_embedded_hash_reference_metadata_invalid',
            $registryKey,
            $column,
        );
    }
}
