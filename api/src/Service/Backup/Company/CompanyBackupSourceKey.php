<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;

/** Kanonická souřadnice jednoho řádku ve zdrojovém payloadu. */
final readonly class CompanyBackupSourceKey
{
    public const DEFAULT_MAX_BYTES = 65_536;

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
        int $maxBytes = self::DEFAULT_MAX_BYTES,
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
        return self::fromValues($registryKey, $values, $maxBytes);
    }

    /** @param array<mixed> $values */
    public static function fromValues(
        string $registryKey,
        array $values,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
    ): self {
        if (!str_starts_with($registryKey, 'table:')
            || !TenantDataDefinition::isValidKey($registryKey)
            || $values === []
            || array_is_list($values)
            || $maxBytes < 1
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

        $hashInput = [
            'registry_key' => $registryKey,
            'columns' => $columns,
            'values' => array_values($validatedValues),
        ];
        $canonical = CanonicalJson::encode($hashInput);
        if (strlen($canonical) > $maxBytes) {
            throw new CompanyBackupPreflightException(
                'source_key_size_exceeded',
                $registryKey,
            );
        }

        return new self(
            $registryKey,
            $columns,
            $validatedValues,
            'sha256:' . hash('sha256', $canonical),
        );
    }

    public static function fromArray(
        mixed $value,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
    ): self {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Uložený zdrojový klíč není objekt.');
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['columns', 'id', 'registry_key', 'values']) {
            throw new \InvalidArgumentException(
                'Uložený zdrojový klíč nemá přesná pole.',
            );
        }
        $registryKey = $value['registry_key'];
        $columns = $value['columns'];
        $values = $value['values'];
        $id = $value['id'];
        if (!is_string($registryKey)
            || !is_array($columns)
            || !array_is_list($columns)
            || !is_array($values)
            || !array_is_list($values)
            || count($columns) !== count($values)
            || !is_string($id)
        ) {
            throw new \InvalidArgumentException(
                'Uložený zdrojový klíč má neplatné typy.',
            );
        }
        $sourceKey = [];
        $seenColumns = [];
        foreach ($columns as $index => $column) {
            if (!is_string($column) || isset($seenColumns[$column])) {
                throw new \InvalidArgumentException(
                    'Uložený zdrojový klíč má neplatný sloupec.',
                );
            }
            $seenColumns[$column] = true;
            $sourceKey[$column] = $values[$index];
        }
        try {
            $result = self::fromValues($registryKey, $sourceKey, $maxBytes);
        } catch (CompanyBackupPreflightException $e) {
            throw new \InvalidArgumentException(
                'Uložený zdrojový klíč není platný.',
                0,
                $e,
            );
        }
        if (!hash_equals($result->id, $id)) {
            throw new \InvalidArgumentException(
                'Uložený zdrojový klíč má neplatný otisk.',
            );
        }
        return $result;
    }

    /** @return array{id:string,registry_key:string,columns:list<string>,values:list<int|string>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'registry_key' => $this->registryKey,
            'columns' => $this->columns,
            'values' => array_values($this->values),
        ];
    }

    public function equals(self $other): bool
    {
        return $this->registryKey === $other->registryKey
            && $this->columns === $other->columns
            && $this->values === $other->values;
    }
}
