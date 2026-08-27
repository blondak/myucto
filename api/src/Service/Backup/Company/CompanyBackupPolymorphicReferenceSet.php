<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;

/** Sada úplných diskriminovaných kontraktů pro polymorfní sloupce tabulky. */
final readonly class CompanyBackupPolymorphicReferenceSet
{
    /** @var list<CompanyBackupPolymorphicReference> */
    public array $references;

    /** @param list<CompanyBackupPolymorphicReference> $references */
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
        foreach ($metadata as $value) {
            $reference = CompanyBackupPolymorphicReference::fromArray($value, $registryKey);
            if (!isset($exported[$reference->column])) {
                throw new CompanyBackupDataSourceException(
                    'data_polymorphic_reference_source_not_exported',
                    $registryKey,
                    $reference->column,
                );
            }
            if (!isset($exported[$reference->discriminatorColumn])) {
                throw new CompanyBackupDataSourceException(
                    'data_polymorphic_reference_source_not_exported',
                    $registryKey,
                    $reference->discriminatorColumn,
                );
            }
            if (isset($claimed[$reference->column])) {
                throw new CompanyBackupDataSourceException(
                    'data_polymorphic_reference_duplicate',
                    $registryKey,
                    $reference->column,
                );
            }
            $claimed[$reference->column] = true;
            $references[] = $reference;
        }
        $ordered = $references;
        usort(
            $ordered,
            static fn (
                CompanyBackupPolymorphicReference $left,
                CompanyBackupPolymorphicReference $right,
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
            static fn (CompanyBackupPolymorphicReference $reference): string =>
                $reference->column,
            $this->references,
        );
    }

    public function assertRegistryTargets(TenantDataRegistry $registry): void
    {
        foreach ($this->references as $reference) {
            foreach ($reference->cases as $case) {
                if ($case->mapping === CompanyBackupPolymorphicReferenceMapping::Preserve) {
                    continue;
                }
                $target = $case->target === null ? null : $registry->definition($case->target);
                if ($target === null
                    || !$target->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
                    || !in_array($target->policy, [
                        TenantDataPolicy::TenantRoot,
                        TenantDataPolicy::TenantOwned,
                        TenantDataPolicy::TenantOwnedIndirect,
                    ], true)
                    || $case->targetColumns !== $this->targetPrimaryKey($target, $reference)
                ) {
                    throw new CompanyBackupDataSourceException(
                        'data_polymorphic_reference_target_invalid',
                        $this->registryKey,
                        $reference->column,
                    );
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param callable(CompanyBackupPolymorphicReferenceCase,int):(int|null) $mapper
     * @return array<string,mixed>
     */
    public function remap(array $row, callable $mapper): array
    {
        foreach ($this->references as $reference) {
            if (!array_key_exists($reference->column, $row)
                || !array_key_exists($reference->discriminatorColumn, $row)
            ) {
                throw $this->valueError($reference->column);
            }
            $discriminator = $row[$reference->discriminatorColumn];
            $case = is_string($discriminator)
                ? $reference->caseFor($discriminator)
                : null;
            if ($case === null) {
                throw $this->valueError($reference->column);
            }
            $value = $row[$reference->column];
            if ($value === null) {
                if (!$reference->nullable) {
                    throw $this->valueError($reference->column);
                }
                continue;
            }
            if (!is_int($value) || $value <= 0) {
                throw $this->valueError($reference->column);
            }
            $mapped = $case->remap($value, $mapper);
            if ($mapped === null || $mapped <= 0) {
                throw $this->valueError($reference->column);
            }
            $row[$reference->column] = $mapped;
        }
        return $row;
    }

    /** @return list<string> */
    private function targetPrimaryKey(
        TenantDataDefinition $target,
        CompanyBackupPolymorphicReference $reference,
    ): array {
        $value = $target->details['primary_key'] ?? null;
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new CompanyBackupDataSourceException(
                'data_polymorphic_reference_target_invalid',
                $this->registryKey,
                $reference->column,
            );
        }
        $result = [];
        $seen = [];
        foreach ($value as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || isset($seen[$column])
            ) {
                throw new CompanyBackupDataSourceException(
                    'data_polymorphic_reference_target_invalid',
                    $this->registryKey,
                    $reference->column,
                );
            }
            $seen[$column] = true;
            $result[] = $column;
        }
        return $result;
    }

    private static function metadataError(string $registryKey): CompanyBackupDataSourceException
    {
        return new CompanyBackupDataSourceException(
            'data_polymorphic_reference_metadata_invalid',
            $registryKey,
        );
    }

    private function valueError(string $column): CompanyBackupDataSourceException
    {
        return new CompanyBackupDataSourceException(
            'data_polymorphic_reference_value_invalid',
            $this->registryKey,
            $column,
        );
    }
}
