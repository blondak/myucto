<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;

/** Kanonická souřadnice jednoho řádku ve zdrojovém payloadu. */
final readonly class CompanyBackupSourceKey
{
    /** @var list<string> */
    public array $columns;

    /** @var array<string,int|string> */
    public array $values;

    /**
     * @param list<string> $columns
     * @param array<string,int|string> $values
     */
    private function __construct(
        public string $registryKey,
        array $columns,
        array $values,
        public string $id,
    ) {
        $this->columns = $columns;
        $this->values = $values;
    }

    /**
     * @param list<string> $columns
     * @param array<string,mixed> $row
     */
    public static function fromRow(
        string $registryKey,
        array $columns,
        array $row,
    ): self {
        $values = [];
        foreach ($columns as $column) {
            if (!array_key_exists($column, $row)) {
                throw new CompanyBackupPreflightException(
                    'source_key_value_invalid',
                    $registryKey,
                    $column,
                );
            }
            $values[$column] = $row[$column];
        }
        return self::fromValues($registryKey, $values);
    }

    /** @param array<mixed> $values */
    public static function fromValues(string $registryKey, array $values): self
    {
        if (!str_starts_with($registryKey, 'table:')
            || !TenantDataDefinition::isValidKey($registryKey)
            || $values === []
            || array_is_list($values)
        ) {
            throw new CompanyBackupPreflightException(
                'source_key_metadata_invalid',
                $registryKey,
            );
        }

        $columns = [];
        $validatedValues = [];
        foreach ($values as $column => $value) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
            ) {
                throw new CompanyBackupPreflightException(
                    'source_key_metadata_invalid',
                    $registryKey,
                );
            }
            if ((!is_int($value) && !is_string($value))
                || is_string($value) && $value === ''
                || is_int($value)
                    && ($column === 'id' || str_ends_with($column, '_id'))
                    && $value < 1
            ) {
                throw new CompanyBackupPreflightException(
                    'source_key_value_invalid',
                    $registryKey,
                    $column,
                );
            }
            $columns[] = $column;
            $validatedValues[$column] = $value;
        }

        return new self(
            $registryKey,
            $columns,
            $validatedValues,
            'sha256:' . CanonicalJson::sha256([
                'registry_key' => $registryKey,
                'columns' => $columns,
                'values' => array_values($validatedValues),
            ]),
        );
    }

    public function equals(self $other): bool
    {
        return $this->registryKey === $other->registryKey
            && $this->columns === $other->columns
            && $this->values === $other->values;
    }
}
