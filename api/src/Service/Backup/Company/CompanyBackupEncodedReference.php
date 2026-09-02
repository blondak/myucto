<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;

/** ID reference zakódovaná v jednom skalárním řetězci s pevným prefixem. */
final readonly class CompanyBackupEncodedReference
{
    /** @var list<string> */
    public array $targetColumns;

    /** @param list<string> $targetColumns */
    private function __construct(
        public string $column,
        public ?CompanyBackupReferenceCondition $condition,
        public ?string $correlatedIdColumn,
        public CompanyBackupReferenceMapping $mapping,
        public bool $nullable,
        public string $target,
        array $targetColumns,
        public string $valuePrefix,
        public ?string $valueSuffixSeparator,
    ) {
        $this->targetColumns = $targetColumns;
    }

    public static function fromArray(mixed $value, string $registryKey): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($registryKey);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $baseKeys = [
            'column',
            'condition',
            'mapping',
            'nullable',
            'target',
            'target_columns',
            'value_prefix',
            'value_suffix_separator',
        ];
        $allowedKeys = [...$baseKeys, 'correlated_id_column'];
        sort($allowedKeys, SORT_STRING);
        if (array_diff($baseKeys, $keys) !== []
            || array_diff($keys, $allowedKeys) !== []
        ) {
            throw self::invalid($registryKey);
        }

        $column = $value['column'];
        $hasCorrelatedIdColumn = array_key_exists(
            'correlated_id_column',
            $value,
        );
        $correlatedIdColumn = $value['correlated_id_column'] ?? null;
        $mappingValue = $value['mapping'];
        $mapping = is_string($mappingValue)
            ? CompanyBackupReferenceMapping::tryFrom($mappingValue)
            : null;
        $nullable = $value['nullable'];
        $target = $value['target'];
        $targetColumns = self::identifierList(
            $value['target_columns'],
            $registryKey,
        );
        $valuePrefix = $value['value_prefix'];
        $valueSuffixSeparator = $value['value_suffix_separator'];
        if (!is_string($column)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
            || !in_array($mapping, [
                CompanyBackupReferenceMapping::TenantId,
                CompanyBackupReferenceMapping::TenantIdOrZero,
            ], true)
            || !is_bool($nullable)
            || ($hasCorrelatedIdColumn
                && (!is_string($correlatedIdColumn)
                    || !self::isIdentifier($correlatedIdColumn)
                    || $correlatedIdColumn === $column
                    || $nullable))
            || !is_string($target)
            || !str_starts_with($target, 'table:')
            || !TenantDataDefinition::isValidKey($target)
            || count($targetColumns) !== 1
            || !is_string($valuePrefix)
            || preg_match('/^[a-z][a-z0-9_-]{0,31}[:.-]$/D', $valuePrefix) !== 1
            || ($valueSuffixSeparator !== null
                && (!is_string($valueSuffixSeparator)
                    || !in_array($valueSuffixSeparator, ['.', '-', ':'], true)))
        ) {
            throw self::invalid(
                $registryKey,
                is_string($column) ? $column : null,
            );
        }
        $conditionValue = $value['condition'];
        $referenceColumns = [$column];
        if (is_string($correlatedIdColumn)) {
            $referenceColumns[] = $correlatedIdColumn;
        }
        $condition = $conditionValue === null
            ? null
            : CompanyBackupReferenceCondition::fromArray(
                $conditionValue,
                $registryKey,
                $referenceColumns,
            );

        return new self(
            $column,
            $condition,
            is_string($correlatedIdColumn) ? $correlatedIdColumn : null,
            $mapping,
            $nullable,
            $target,
            $targetColumns,
            $valuePrefix,
            is_string($valueSuffixSeparator) ? $valueSuffixSeparator : null,
        );
    }

    public function targetTable(): string
    {
        return substr($this->target, strlen('table:'));
    }

    public function signature(): string
    {
        $signature = $this->column;
        if ($this->correlatedIdColumn !== null) {
            $signature .= '=' . $this->correlatedIdColumn;
        }
        $signature .= '->'
            . $this->targetTable()
            . ':'
            . implode(',', $this->targetColumns);
        if ($this->condition !== null) {
            $signature .= '?' . $this->condition->signature();
        }
        $signature .= '@' . $this->valuePrefix;
        if ($this->valueSuffixSeparator !== null) {
            $signature .= '~' . $this->valueSuffixSeparator;
        }
        return $signature;
    }

    private static function isIdentifier(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) === 1;
    }

    /** @return list<string> */
    private static function identifierList(mixed $value, string $registryKey): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw self::invalid($registryKey);
        }
        $result = [];
        $seen = [];
        foreach ($value as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || isset($seen[$column])
            ) {
                throw self::invalid($registryKey);
            }
            $seen[$column] = true;
            $result[] = $column;
        }
        return $result;
    }

    private static function invalid(
        string $registryKey,
        ?string $column = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_encoded_reference_metadata_invalid',
            $registryKey,
            $column,
        );
    }
}
