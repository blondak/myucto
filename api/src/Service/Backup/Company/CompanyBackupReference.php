<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;

/** Jedna deklarovaná sémantická reference a její politika obnovy. */
final readonly class CompanyBackupReference
{
    private const ACTOR_FALLBACKS = ['null', 'restore_actor'];

    /** @var list<string> */
    public array $columns;

    /** @var list<string> */
    public array $targetColumns;

    /** @var list<string> */
    public array $nullableColumns;

    /** @var list<string> */
    public array $fallbacks;

    /**
     * @param list<string> $columns
     * @param list<string> $targetColumns
     * @param list<string> $nullableColumns
     * @param list<string> $fallbacks
     */
    private function __construct(
        array $columns,
        public string $target,
        array $targetColumns,
        public CompanyBackupReferenceMapping $mapping,
        public CompanyBackupReferenceConstraint $constraint,
        array $nullableColumns,
        array $fallbacks,
    ) {
        $this->columns = $columns;
        $this->targetColumns = $targetColumns;
        $this->nullableColumns = $nullableColumns;
        $this->fallbacks = $fallbacks;
    }

    public static function fromArray(mixed $value, string $registryKey): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($registryKey);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'columns',
            'constraint',
            'fallbacks',
            'mapping',
            'nullable_columns',
            'target',
            'target_columns',
        ]) {
            throw self::invalid($registryKey);
        }

        $columns = self::identifierList($value['columns'], $registryKey, false);
        $targetColumns = self::identifierList(
            $value['target_columns'],
            $registryKey,
            false,
        );
        $nullableColumns = self::identifierList(
            $value['nullable_columns'],
            $registryKey,
            true,
        );
        $target = $value['target'];
        $mappingValue = $value['mapping'];
        $constraintValue = $value['constraint'];
        $fallbacks = $value['fallbacks'];
        $mapping = is_string($mappingValue)
            ? CompanyBackupReferenceMapping::tryFrom($mappingValue)
            : null;
        $constraint = is_string($constraintValue)
            ? CompanyBackupReferenceConstraint::tryFrom($constraintValue)
            : null;
        if (!is_string($target)
            || !str_starts_with($target, 'table:')
            || !TenantDataDefinition::isValidKey($target)
            || $mapping === null
            || $constraint === null
            || !is_array($fallbacks)
            || !array_is_list($fallbacks)
            || count($columns) !== count($targetColumns)
        ) {
            throw self::invalid($registryKey);
        }

        $nullableSet = array_fill_keys($nullableColumns, true);
        $canonicalNullable = array_values(array_filter(
            $columns,
            static fn (string $column): bool => isset($nullableSet[$column]),
        ));
        if ($nullableColumns !== $canonicalNullable) {
            throw self::invalid($registryKey);
        }

        $validatedFallbacks = [];
        $seenFallbacks = [];
        foreach ($fallbacks as $fallback) {
            if (!is_string($fallback)
                || !in_array($fallback, self::ACTOR_FALLBACKS, true)
                || isset($seenFallbacks[$fallback])
            ) {
                throw self::invalid($registryKey);
            }
            $seenFallbacks[$fallback] = true;
            $validatedFallbacks[] = $fallback;
        }
        $sortedFallbacks = $validatedFallbacks;
        sort($sortedFallbacks, SORT_STRING);
        if ($validatedFallbacks !== $sortedFallbacks
            || ($mapping !== CompanyBackupReferenceMapping::Actor
                && $validatedFallbacks !== [])
            || (in_array('null', $validatedFallbacks, true)
                && $nullableColumns !== $columns)
        ) {
            throw self::invalid($registryKey);
        }
        if ($mapping === CompanyBackupReferenceMapping::Actor
            && (count($columns) !== 1 || $target !== 'table:users' || $targetColumns !== ['id'])
        ) {
            throw self::invalid($registryKey);
        }

        return new self(
            $columns,
            $target,
            $targetColumns,
            $mapping,
            $constraint,
            $nullableColumns,
            $validatedFallbacks,
        );
    }

    public function targetTable(): string
    {
        return substr($this->target, strlen('table:'));
    }

    public function signature(): string
    {
        return implode(',', $this->columns)
            . '->'
            . $this->targetTable()
            . ':'
            . implode(',', $this->targetColumns);
    }

    public function firstColumn(): string
    {
        return $this->columns[0];
    }

    /** @return list<string> */
    private static function identifierList(
        mixed $value,
        string $registryKey,
        bool $mayBeEmpty,
    ): array {
        if (!is_array($value) || !array_is_list($value) || !$mayBeEmpty && $value === []) {
            throw self::invalid($registryKey);
        }
        $result = [];
        $seen = [];
        foreach ($value as $identifier) {
            if (!is_string($identifier)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $identifier) !== 1
                || isset($seen[$identifier])
            ) {
                throw self::invalid($registryKey);
            }
            $seen[$identifier] = true;
            $result[] = $identifier;
        }
        return $result;
    }

    private static function invalid(string $registryKey): CompanyBackupDataSourceException
    {
        return new CompanyBackupDataSourceException(
            'data_reference_metadata_invalid',
            $registryKey,
        );
    }
}
