<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;

/** Fingerprintovaný kontrakt cílového zapečetění jednoho protected secretu. */
final readonly class CompanyBackupProtectedSecretMaterialization
{
    /** @var array{ciphertext:string,lookup_hash:string,masked:string} */
    public array $targetColumns;

    /** @param array{ciphertext:string,lookup_hash:string,masked:string} $targetColumns */
    private function __construct(
        public string $registryKey,
        public string $secretColumn,
        public CompanyBackupProtectedSecretMaterializer $materializer,
        public PayrollSensitiveField $field,
        public string $tenantIdColumn,
        public string $entityIdColumn,
        public bool $nullable,
        array $targetColumns,
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
            'entity_id_column',
            'field',
            'materializer',
            'nullable',
            'secret_column',
            'target_columns',
            'tenant_id_column',
        ]) {
            throw self::invalid($registryKey);
        }

        $secretColumn = $value['secret_column'];
        $materializerValue = $value['materializer'];
        $fieldValue = $value['field'];
        $tenantIdColumn = $value['tenant_id_column'];
        $entityIdColumn = $value['entity_id_column'];
        $nullable = $value['nullable'];
        $targetColumns = $value['target_columns'];
        $materializer = is_string($materializerValue)
            ? CompanyBackupProtectedSecretMaterializer::tryFrom($materializerValue)
            : null;
        $field = is_string($fieldValue)
            ? PayrollSensitiveField::tryFrom($fieldValue)
            : null;
        if (!is_string($secretColumn)
            || !self::isIdentifier($secretColumn)
            || $materializer !== CompanyBackupProtectedSecretMaterializer::PayrollSensitiveV1
            || $field === null
            || !is_string($tenantIdColumn)
            || !self::isIdentifier($tenantIdColumn)
            || !is_string($entityIdColumn)
            || !self::isIdentifier($entityIdColumn)
            || $tenantIdColumn === $entityIdColumn
            || !is_bool($nullable)
            || !is_array($targetColumns)
            || array_is_list($targetColumns)
        ) {
            throw self::invalid(
                $registryKey,
                is_string($secretColumn) ? $secretColumn : null,
            );
        }
        $targetKeys = array_keys($targetColumns);
        sort($targetKeys, SORT_STRING);
        if ($targetKeys !== ['ciphertext', 'lookup_hash', 'masked']) {
            throw self::invalid($registryKey, $secretColumn);
        }

        $validatedTargets = [];
        foreach (['ciphertext', 'lookup_hash', 'masked'] as $role) {
            $column = $targetColumns[$role];
            if (!is_string($column) || !self::isIdentifier($column)) {
                throw self::invalid($registryKey, $secretColumn);
            }
            $validatedTargets[$role] = $column;
        }
        if ($validatedTargets['ciphertext'] !== $secretColumn
            || count(array_unique($validatedTargets)) !== count($validatedTargets)
        ) {
            throw self::invalid($registryKey, $secretColumn);
        }

        return new self(
            $registryKey,
            $secretColumn,
            $materializer,
            $field,
            $tenantIdColumn,
            $entityIdColumn,
            $nullable,
            $validatedTargets,
        );
    }

    public function signature(): string
    {
        return $this->secretColumn
            . ($this->nullable ? '?' : '')
            . '<-'
            . $this->materializer->value
            . ':'
            . $this->field->value
            . '@'
            . $this->tenantIdColumn
            . ','
            . $this->entityIdColumn
            . '->'
            . implode(',', $this->targetColumns);
    }

    private static function isIdentifier(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) === 1;
    }

    private static function invalid(
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
