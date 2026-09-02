<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;

/** Úplná sada podmíněných ID referencí zakódovaných ve skalárních řetězcích. */
final readonly class CompanyBackupEncodedReferenceSet
{
    /** @var list<CompanyBackupEncodedReference> */
    public array $references;

    /** @param list<CompanyBackupEncodedReference> $references */
    private function __construct(
        public string $registryKey,
        array $references,
    ) {
        $this->references = $references;
    }

    /** @param list<string> $dataColumns */
    public static function fromArray(
        mixed $metadata,
        string $registryKey,
        array $dataColumns,
    ): self {
        if (!is_array($metadata) || !array_is_list($metadata)) {
            throw self::metadataError($registryKey);
        }
        $exported = array_fill_keys($dataColumns, true);
        $references = [];
        $claimed = [];
        $claimedCorrelations = [];
        foreach ($metadata as $value) {
            $reference = CompanyBackupEncodedReference::fromArray(
                $value,
                $registryKey,
            );
            if (!isset($exported[$reference->column])) {
                throw new CompanyBackupDataSourceException(
                    'data_encoded_reference_source_not_exported',
                    $registryKey,
                    $reference->column,
                );
            }
            $correlatedIdColumn = $reference->correlatedIdColumn;
            if ($correlatedIdColumn !== null
                && !isset($exported[$correlatedIdColumn])
            ) {
                throw new CompanyBackupDataSourceException(
                    'data_encoded_reference_correlation_source_not_exported',
                    $registryKey,
                    $correlatedIdColumn,
                );
            }
            if ($correlatedIdColumn !== null
                && (isset($claimed[$correlatedIdColumn])
                    || isset($claimedCorrelations[$correlatedIdColumn]))
            ) {
                throw new CompanyBackupDataSourceException(
                    'data_encoded_reference_duplicate',
                    $registryKey,
                    $correlatedIdColumn,
                );
            }
            $conditionColumn = $reference->condition?->column;
            if ($conditionColumn !== null && !isset($exported[$conditionColumn])) {
                throw new CompanyBackupDataSourceException(
                    'data_encoded_reference_condition_source_not_exported',
                    $registryKey,
                    $conditionColumn,
                );
            }
            if (isset($claimed[$reference->column])
                || isset($claimedCorrelations[$reference->column])
            ) {
                throw new CompanyBackupDataSourceException(
                    'data_encoded_reference_duplicate',
                    $registryKey,
                    $reference->column,
                );
            }
            $claimed[$reference->column] = true;
            if ($correlatedIdColumn !== null) {
                $claimedCorrelations[$correlatedIdColumn] = true;
            }
            $references[] = $reference;
        }
        $ordered = $references;
        usort(
            $ordered,
            static fn (
                CompanyBackupEncodedReference $left,
                CompanyBackupEncodedReference $right,
            ): int => strcmp($left->signature(), $right->signature()),
        );
        if ($ordered !== $references) {
            throw self::metadataError($registryKey);
        }
        return new self($registryKey, $references);
    }

    /** @return list<string> */
    public function classifiedColumns(): array
    {
        return array_map(
            static fn (CompanyBackupEncodedReference $reference): string =>
                $reference->column,
            $this->references,
        );
    }

    public function assertRegistryTargets(TenantDataRegistry $registry): void
    {
        foreach ($this->references as $reference) {
            $target = $registry->definition($reference->target);
            if ($target === null
                || !$target->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
                || !in_array($target->policy, [
                    TenantDataPolicy::TenantRoot,
                    TenantDataPolicy::TenantOwned,
                    TenantDataPolicy::TenantOwnedIndirect,
                ], true)
                || $reference->targetColumns
                    !== $this->targetPrimaryKey($target, $reference)
            ) {
                throw $this->targetError($reference);
            }
        }
    }

    /** @param array<string,mixed> $row */
    public function assertSourceRow(array $row): void
    {
        foreach ($this->references as $reference) {
            if (!$this->conditionMatches($row, $reference)) {
                continue;
            }
            if (!array_key_exists($reference->column, $row)) {
                throw $this->valueError($reference);
            }
            $correlation = $this->correlation($row, $reference);
            if (!$correlation['active']) {
                continue;
            }
            $value = $row[$reference->column];
            if ($value === null && $reference->nullable) {
                continue;
            }
            $source = $this->sourceValue($value, $reference);
            if ($source === null
                || ($correlation['identifier'] !== null
                    && $source['identifier'] !== $correlation['identifier'])
            ) {
                throw $this->valueError($reference);
            }
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param callable(CompanyBackupEncodedReference,int):(int|null) $mapper
     * @return array<string,mixed>
     */
    public function remap(array $row, callable $mapper): array
    {
        $this->assertSourceRow($row);
        foreach ($this->references as $reference) {
            if (!$this->conditionMatches($row, $reference)) {
                continue;
            }
            $correlation = $this->correlation($row, $reference);
            if (!$correlation['active']) {
                continue;
            }
            $value = $row[$reference->column];
            if ($value === null && $reference->nullable) {
                continue;
            }
            $source = $this->sourceValue($value, $reference);
            if ($source === null) {
                throw $this->valueError($reference);
            }
            $mapped = $mapper($reference, $source['identifier']);
            $minimum = $reference->mapping
                === CompanyBackupReferenceMapping::TenantIdOrZero
                ? 0
                : 1;
            if (!is_int($mapped) || $mapped < $minimum) {
                throw $this->valueError($reference);
            }
            if ($reference->correlatedIdColumn !== null) {
                $row[$reference->correlatedIdColumn] = $mapped;
            }
            $row[$reference->column] = $reference->valuePrefix
                . $mapped
                . $source['suffix'];
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{active:bool,identifier:?int}
     */
    private function correlation(
        array $row,
        CompanyBackupEncodedReference $reference,
    ): array {
        $column = $reference->correlatedIdColumn;
        if ($column === null) {
            return ['active' => true, 'identifier' => null];
        }
        if (!array_key_exists($column, $row)) {
            throw $this->valueError($reference);
        }
        $value = $row[$column];
        if ($value === null) {
            return ['active' => false, 'identifier' => null];
        }
        $minimum = $reference->mapping
            === CompanyBackupReferenceMapping::TenantIdOrZero
            ? 0
            : 1;
        if (!is_int($value) || $value < $minimum) {
            throw $this->valueError($reference);
        }
        return ['active' => true, 'identifier' => $value];
    }

    /** @param array<string,mixed> $row */
    private function conditionMatches(
        array $row,
        CompanyBackupEncodedReference $reference,
    ): bool {
        $condition = $reference->condition;
        if ($condition === null) {
            return true;
        }
        $value = $row[$condition->column] ?? null;
        if (!is_string($value)) {
            throw $this->valueError($reference);
        }
        return $value === $condition->equals;
    }

    /** @return array{identifier:int,suffix:string}|null */
    private function sourceValue(
        mixed $value,
        CompanyBackupEncodedReference $reference,
    ): ?array {
        if (!is_string($value)) {
            return null;
        }
        $digits = $reference->mapping
            === CompanyBackupReferenceMapping::TenantIdOrZero
            ? '(?:0|[1-9][0-9]*)'
            : '[1-9][0-9]*';
        $suffix = $reference->valueSuffixSeparator === null
            ? ''
            : '('
                . preg_quote($reference->valueSuffixSeparator, '/')
                . '[a-z0-9][a-z0-9_.-]{0,127})';
        if (preg_match(
            '/^' . preg_quote($reference->valuePrefix, '/')
                . '(' . $digits . ')' . $suffix . '$/D',
            $value,
            $matches,
        ) !== 1) {
            return null;
        }
        $minimum = $reference->mapping
            === CompanyBackupReferenceMapping::TenantIdOrZero
            ? 0
            : 1;
        $identifier = filter_var(
            $matches[1],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $minimum]],
        );
        return is_int($identifier)
            ? [
                'identifier' => $identifier,
                'suffix' => $matches[2] ?? '',
            ]
            : null;
    }

    /** @return list<string> */
    private function targetPrimaryKey(
        TenantDataDefinition $target,
        CompanyBackupEncodedReference $reference,
    ): array {
        $value = $target->details['primary_key'] ?? null;
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw $this->targetError($reference);
        }
        $result = [];
        $seen = [];
        foreach ($value as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || isset($seen[$column])
            ) {
                throw $this->targetError($reference);
            }
            $seen[$column] = true;
            $result[] = $column;
        }
        return $result;
    }

    private static function metadataError(
        string $registryKey,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_encoded_reference_metadata_invalid',
            $registryKey,
        );
    }

    private function targetError(
        CompanyBackupEncodedReference $reference,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_encoded_reference_target_invalid',
            $this->registryKey,
            $reference->column,
        );
    }

    private function valueError(
        CompanyBackupEncodedReference $reference,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_encoded_reference_value_invalid',
            $this->registryKey,
            $reference->column,
        );
    }
}
