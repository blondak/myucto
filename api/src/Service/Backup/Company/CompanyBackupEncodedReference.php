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
        if ($keys !== [
            'column',
            'condition',
            'mapping',
            'nullable',
            'target',
            'target_columns',
            'value_prefix',
            'value_suffix_separator',
        ]) {
            throw self::invalid($registryKey);
        }

        $column = $value['column'];
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
        $condition = $conditionValue === null
            ? null
            : CompanyBackupReferenceCondition::fromArray(
                $conditionValue,
                $registryKey,
                [$column],
            );

        return new self(
            $column,
            $condition,
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
        $signature = $this->column
            . '->'
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
