<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Externí identifikátory, které se přenášejí jako skalární hodnoty bez remapu. */
final readonly class CompanyBackupPreservedIdentifierSet
{
    /** @var list<string> */
    public array $columns;

    /** @param list<string> $columns */
    private function __construct(array $columns)
    {
        $this->columns = $columns;
    }

    /** @param list<string> $dataColumns */
    public static function fromArray(
        mixed $value,
        string $registryKey,
        array $dataColumns,
    ): self {
        if (!is_array($value) || !array_is_list($value)) {
            throw self::invalid($registryKey);
        }
        $exported = array_fill_keys($dataColumns, true);
        $columns = [];
        $seen = [];
        foreach ($value as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || !str_ends_with($column, '_id')
                || isset($seen[$column])
            ) {
                throw self::invalid($registryKey);
            }
            if (!isset($exported[$column])) {
                throw new CompanyBackupDataSourceException(
                    'data_preserved_identifier_source_not_exported',
                    $registryKey,
                    $column,
                );
            }
            $seen[$column] = true;
            $columns[] = $column;
        }
        $sorted = $columns;
        sort($sorted, SORT_STRING);
        if ($columns !== $sorted) {
            throw self::invalid($registryKey);
        }
        return new self($columns);
    }

    private static function invalid(string $registryKey): CompanyBackupDataSourceException
    {
        return new CompanyBackupDataSourceException(
            'data_preserved_identifier_metadata_invalid',
            $registryKey,
        );
    }
}
