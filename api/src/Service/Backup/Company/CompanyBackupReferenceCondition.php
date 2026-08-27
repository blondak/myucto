<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Diskriminátor vybírající cíl polymorfní reference podle jiného sloupce řádku. */
final readonly class CompanyBackupReferenceCondition
{
    private function __construct(
        public string $column,
        public string $equals,
    ) {}

    /** @param list<string> $referenceColumns */
    public static function fromArray(
        mixed $value,
        string $registryKey,
        array $referenceColumns,
    ): self {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($registryKey);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['column', 'equals']) {
            throw self::invalid($registryKey);
        }

        $column = $value['column'];
        $equals = $value['equals'];
        if (!is_string($column)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
            || in_array($column, $referenceColumns, true)
            || !is_string($equals)
            || preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D', $equals) !== 1
        ) {
            throw self::invalid($registryKey, is_string($column) ? $column : null);
        }

        return new self($column, $equals);
    }

    public function signature(): string
    {
        return $this->column . '=' . $this->equals;
    }

    private static function invalid(
        string $registryKey,
        ?string $column = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_reference_metadata_invalid',
            $registryKey,
            $column,
        );
    }
}
