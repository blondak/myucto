<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;

/** Úplná sada cílových převodů protected hodnot jedné tabulky. */
final readonly class CompanyBackupProtectedSecretMaterializationSet
{
    private const DERIVED_OMISSION = 'rederived_from_protected_secret';

    /** @var list<CompanyBackupProtectedSecretMaterialization> */
    public array $materializations;

    /** @var array<string,CompanyBackupProtectedSecretMaterialization> */
    private array $bySecretColumn;

    /** @var list<string> */
    private array $primaryKey;

    /**
     * @param list<CompanyBackupProtectedSecretMaterialization> $materializations
     * @param array<string,CompanyBackupProtectedSecretMaterialization> $bySecretColumn
     * @param list<string> $primaryKey
     */
    private function __construct(
        public string $registryKey,
        array $materializations,
        array $bySecretColumn,
        array $primaryKey,
    ) {
        $this->materializations = $materializations;
        $this->bySecretColumn = $bySecretColumn;
        $this->primaryKey = $primaryKey;
    }

    /**
     * @param list<string> $dataColumns
     * @param list<string> $primaryKey
     * @param array<string,mixed> $ownership
     * @param array<string,string> $omitColumns
     * @param array<string,TenantSecretPolicy> $secretPolicies
     * @param array<string,mixed> $secretMetadata
     */
    public static function fromArray(
        mixed $metadata,
        string $registryKey,
        array $dataColumns,
        array $primaryKey,
        array $ownership,
        array $omitColumns,
        array $secretPolicies,
        array $secretMetadata,
    ): self {
        if (!is_array($metadata) || !array_is_list($metadata)) {
            throw self::metadataError($registryKey);
        }
        $data = array_fill_keys($dataColumns, true);
        $primary = array_fill_keys($primaryKey, true);
        $claimedOutputs = [];
        $materializations = [];
        $bySecretColumn = [];
        foreach ($metadata as $value) {
            $materialization = CompanyBackupProtectedSecretMaterialization::fromArray(
                $value,
                $registryKey,
            );
            $secretColumn = $materialization->secretColumn;
            if (isset($bySecretColumn[$secretColumn])) {
                throw self::metadataError($registryKey, $secretColumn);
            }
            self::assertCoordinates(
                $materialization,
                $data,
                $primary,
                $ownership,
            );
            self::assertSecretContract(
                $materialization,
                $secretPolicies,
                $secretMetadata,
            );
            foreach ($materialization->targetColumns as $role => $column) {
                if (isset($claimedOutputs[$column])) {
                    throw self::metadataError($registryKey, $column);
                }
                if ($role !== 'ciphertext'
                    && ($omitColumns[$column] ?? null) !== self::DERIVED_OMISSION
                ) {
                    throw self::metadataError($registryKey, $column);
                }
                $claimedOutputs[$column] = true;
            }
            $materializations[] = $materialization;
            $bySecretColumn[$secretColumn] = $materialization;
        }
        foreach ($omitColumns as $column => $reason) {
            if ($reason === self::DERIVED_OMISSION && !isset($claimedOutputs[$column])) {
                throw self::metadataError($registryKey, $column);
            }
        }

        $ordered = $materializations;
        usort(
            $ordered,
            static fn (
                CompanyBackupProtectedSecretMaterialization $left,
                CompanyBackupProtectedSecretMaterialization $right,
            ): int => strcmp($left->signature(), $right->signature()),
        );
        if ($ordered !== $materializations) {
            throw self::metadataError($registryKey);
        }

        return new self(
            $registryKey,
            $materializations,
            $bySecretColumn,
            $primaryKey,
        );
    }

    /**
     * @param array<string,mixed> $sourceRow
     * @param array<string,mixed> $targetRow
     * @return array<string,string>
     */
    public function materialize(
        CompanyBackupSecretValue $value,
        array $sourceRow,
        array $targetRow,
        PayrollSensitiveData $sensitiveData,
    ): array {
        $materialization = $this->bySecretColumn[$value->name] ?? null;
        if ($value->registryKey !== $this->registryKey
            || $value->scope !== CompanyBackupSecretScope::Column
            || $materialization === null
        ) {
            throw $this->valueError($value->name);
        }
        try {
            $value->assertPrimaryKeyColumns($this->primaryKey);
        } catch (CompanyBackupSecretPayloadException $e) {
            throw $this->valueError($materialization->secretColumn, $e);
        }
        foreach ($this->primaryKey as $column) {
            if (!array_key_exists($column, $sourceRow)
                || $sourceRow[$column] !== $value->primaryKey[$column]
            ) {
                throw $this->valueError($materialization->secretColumn);
            }
        }

        foreach ($materialization->targetColumns as $column) {
            if (array_key_exists($column, $targetRow)) {
                throw $this->valueError($materialization->secretColumn);
            }
        }
        $tenantId = $targetRow[$materialization->tenantIdColumn] ?? null;
        $entityId = $targetRow[$materialization->entityIdColumn] ?? null;
        $field = $materialization->fieldForRows($sourceRow, $targetRow);
        if (!is_int($tenantId) || $tenantId < 1
            || !is_int($entityId) || $entityId < 1
            || $field === null
        ) {
            throw $this->valueError($materialization->secretColumn);
        }

        try {
            $sealed = $sensitiveData->seal(
                $value->plaintext(),
                $field,
                $tenantId,
                $entityId,
            );
        } catch (\Throwable $e) {
            throw new CompanyBackupDataSourceException(
                'secret_restore_materialization_failed',
                $this->registryKey,
                $materialization->secretColumn,
                $e,
            );
        }

        return [
            $materialization->targetColumns['ciphertext'] => $sealed->ciphertext,
            $materialization->targetColumns['lookup_hash'] => $sealed->lookupHash,
            $materialization->targetColumns['masked'] => $sealed->masked,
        ];
    }

    /**
     * Materializuje konkrétní secret řádku, včetně legitimně prázdné hodnoty.
     *
     * @param array<string,mixed> $sourceRow
     * @param array<string,mixed> $targetRow
     * @return array<string,string|null>
     */
    public function materializeForColumn(
        string $secretColumn,
        ?CompanyBackupSecretValue $value,
        array $sourceRow,
        array $targetRow,
        PayrollSensitiveData $sensitiveData,
    ): array {
        $materialization = $this->bySecretColumn[$secretColumn] ?? null;
        if ($materialization === null
            || $value !== null && $value->name !== $secretColumn
        ) {
            throw $this->valueError($secretColumn);
        }
        if ($value !== null) {
            return $this->materialize(
                $value,
                $sourceRow,
                $targetRow,
                $sensitiveData,
            );
        }
        if (!$materialization->nullable) {
            throw $this->valueError($secretColumn);
        }
        foreach ($this->primaryKey as $column) {
            if (!array_key_exists($column, $sourceRow)) {
                throw $this->valueError($secretColumn);
            }
        }
        foreach ($materialization->targetColumns as $column) {
            if (array_key_exists($column, $targetRow)) {
                throw $this->valueError($secretColumn);
            }
        }
        $tenantId = $targetRow[$materialization->tenantIdColumn] ?? null;
        $entityId = $targetRow[$materialization->entityIdColumn] ?? null;
        $field = $materialization->fieldForRows($sourceRow, $targetRow);
        if (!is_int($tenantId) || $tenantId < 1
            || !is_int($entityId) || $entityId < 1
            || $field === null
        ) {
            throw $this->valueError($secretColumn);
        }

        return [
            $materialization->targetColumns['ciphertext'] => null,
            $materialization->targetColumns['lookup_hash'] => null,
            $materialization->targetColumns['masked'] => null,
        ];
    }

    /**
     * @param array<string,true> $data
     * @param array<string,true> $primary
     * @param array<string,mixed> $ownership
     */
    private static function assertCoordinates(
        CompanyBackupProtectedSecretMaterialization $materialization,
        array $data,
        array $primary,
        array $ownership,
    ): void {
        $ownershipKeys = array_keys($ownership);
        sort($ownershipKeys, SORT_STRING);
        if ($ownershipKeys !== ['column', 'strategy']
            || ($ownership['strategy'] ?? null) !== 'supplier_id'
            || ($ownership['column'] ?? null) !== $materialization->tenantIdColumn
            || !isset($data[$materialization->tenantIdColumn])
            || !isset($data[$materialization->entityIdColumn])
            || !isset($primary[$materialization->entityIdColumn])
            || ($materialization->fieldSelector->discriminatorColumn !== null
                && !isset($data[
                    $materialization->fieldSelector->discriminatorColumn
                ]))
        ) {
            throw self::metadataError(
                $materialization->registryKey,
                $materialization->secretColumn,
            );
        }
    }

    /**
     * @param array<string,TenantSecretPolicy> $secretPolicies
     * @param array<string,mixed> $secretMetadata
     */
    private static function assertSecretContract(
        CompanyBackupProtectedSecretMaterialization $materialization,
        array $secretPolicies,
        array $secretMetadata,
    ): void {
        $column = $materialization->secretColumn;
        if (($secretPolicies[$column] ?? null)
            !== TenantSecretPolicy::ProtectedDomainSecret
        ) {
            throw self::metadataError($materialization->registryKey, $column);
        }
        $storage = CompanyBackupSecretStorageContract::fromMetadata(
            $secretMetadata[$column] ?? null,
            $materialization->registryKey,
            $column,
        );
        if ($storage->storage
            !== CompanyBackupSecretStorage::ApplicationEncryptedContext
        ) {
            throw self::metadataError($materialization->registryKey, $column);
        }
        $fixedField = $materialization->fieldSelector->fixedField;
        if ($fixedField !== null) {
            $expectedContext = 'payroll:{'
                . $materialization->tenantIdColumn
                . '}:{'
                . $materialization->entityIdColumn
                . '}:'
                . $fixedField->value;
            if ($storage->context !== $expectedContext
                || $storage->payrollSensitiveContext !== null
            ) {
                throw self::metadataError($materialization->registryKey, $column);
            }
            return;
        }
        if ($storage->context !== null
            || $storage->payrollSensitiveContext === null
            || !$storage->payrollSensitiveContext->matches(
                $materialization->tenantIdColumn,
                $materialization->entityIdColumn,
                $materialization->fieldSelector,
            )
        ) {
            throw self::metadataError($materialization->registryKey, $column);
        }
    }

    private function valueError(
        string $column,
        ?\Throwable $previous = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'secret_restore_materialization_invalid',
            $this->registryKey,
            $column,
            $previous,
        );
    }

    private static function metadataError(
        string $registryKey,
        ?string $column = null,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_protected_secret_materialization_metadata_invalid',
            $registryKey,
            $column,
        );
    }
}
