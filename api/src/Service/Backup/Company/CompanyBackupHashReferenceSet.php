<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;

/** Úplná sada mezřádkových referencí uložených přímo jako SHA-256 hash. */
final readonly class CompanyBackupHashReferenceSet
{
    /** @var list<CompanyBackupHashReference> */
    public array $references;

    /** @param list<CompanyBackupHashReference> $references */
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
            $reference = CompanyBackupHashReference::fromArray(
                $value,
                $registryKey,
            );
            if (!isset($exported[$reference->column])) {
                throw new CompanyBackupDataSourceException(
                    'data_hash_reference_source_not_exported',
                    $registryKey,
                    $reference->column,
                );
            }
            if (isset($claimed[$reference->column])) {
                throw new CompanyBackupDataSourceException(
                    'data_hash_reference_duplicate',
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
                CompanyBackupHashReference $left,
                CompanyBackupHashReference $right,
            ): int => strcmp($left->signature(), $right->signature()),
        );
        if ($ordered !== $references) {
            throw self::metadataError($registryKey);
        }
        return new self($registryKey, $references);
    }

    public function assertRegistryTargets(TenantDataRegistry $registry): void
    {
        foreach ($this->references as $reference) {
            $target = $registry->definition($reference->target);
            if ($target === null
                || !$target->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
                || !in_array(
                    $target->policy,
                    [
                        TenantDataPolicy::TenantRoot,
                        TenantDataPolicy::TenantOwned,
                        TenantDataPolicy::TenantOwnedIndirect,
                    ],
                    true,
                )
            ) {
                throw $this->targetError($reference);
            }
            try {
                $projection = CompanyBackupTableProjection::fromDefinition($target);
            } catch (CompanyBackupDataSourceException $e) {
                throw $this->targetError($reference, $e);
            }
            $declared = false;
            foreach ($projection->derivedHashes->hashes as $hash) {
                if ($hash->hashColumn === $reference->targetHashColumn) {
                    $declared = true;
                    break;
                }
            }
            if (!$declared) {
                throw $this->targetError($reference);
            }
        }
    }

    public function assertRuntimeSchema(
        CompanyBackupTableReferenceSchema $schema,
    ): void {
        $nullable = array_fill_keys($schema->nullableColumns, true);
        foreach ($this->references as $reference) {
            if (isset($nullable[$reference->column]) !== $reference->nullable) {
                throw new CompanyBackupDataSourceException(
                    'data_hash_reference_nullability_mismatch',
                    $this->registryKey,
                    $reference->column,
                );
            }
        }
    }

    /** @param array<string,mixed> $row */
    public function assertSourceRow(array $row): void
    {
        $this->process($row, null);
    }

    /**
     * @param array<string,mixed> $row
     * @param callable(CompanyBackupHashReference,string):mixed $mapper
     * @return array<string,mixed>
     */
    public function remap(array $row, callable $mapper): array
    {
        return $this->process($row, $mapper);
    }

    /**
     * @param array<string,mixed> $row
     * @param null|callable(CompanyBackupHashReference,string):mixed $mapper
     * @return array<string,mixed>
     */
    private function process(array $row, ?callable $mapper): array
    {
        foreach ($this->references as $reference) {
            if (!array_key_exists($reference->column, $row)) {
                throw $this->valueError($reference->column);
            }
            $value = $row[$reference->column];
            if ($value === null && $reference->nullable) {
                continue;
            }
            if (!self::validHash($value)) {
                throw $this->valueError($reference->column);
            }
            if ($mapper === null) {
                continue;
            }
            $mapped = $mapper($reference, $value);
            if ($mapped === CompanyBackupReferenceRemapDirective::Defer) {
                if (!$reference->nullable) {
                    throw $this->valueError($reference->column);
                }
                $row[$reference->column] = null;
                continue;
            }
            if (!self::validHash($mapped)) {
                throw $this->valueError($reference->column);
            }
            $row[$reference->column] = $mapped;
        }
        return $row;
    }

    private static function validHash(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    private function targetError(
        CompanyBackupHashReference $reference,
        ?\Throwable $previous = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_hash_reference_target_invalid',
            $this->registryKey,
            $reference->column,
            $previous,
        );
    }

    private function valueError(string $column): CompanyBackupDataSourceException
    {
        return new CompanyBackupDataSourceException(
            'data_hash_reference_value_invalid',
            $this->registryKey,
            $column,
        );
    }

    private static function metadataError(
        string $registryKey,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_hash_reference_metadata_invalid',
            $registryKey,
        );
    }
}
