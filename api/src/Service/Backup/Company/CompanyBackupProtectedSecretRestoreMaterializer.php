<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;

/**
 * Indexuje povinné plaintext hodnoty odemčené envelope podle původního řádku
 * a jednorázově je materializuje pod novými cílovými souřadnicemi.
 */
final class CompanyBackupProtectedSecretRestoreMaterializer
{
    /** @var array<string,TenantDataDefinition> */
    private array $definitions = [];

    /** @var array<string,CompanyBackupTableProjection> */
    private array $projections = [];

    /** @var array<string,true> */
    private array $protectedDeclarations = [];

    /** @var array<string,CompanyBackupSecretValue> */
    private array $values = [];

    /** @var array<string,true> */
    private array $consumed = [];

    private bool $closed = false;

    public function __construct(
        CompanyBackupSecretPayload $payload,
        TenantDataRegistrySnapshot $targetRegistry,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly CompanyBackupArchiveLimits $limits =
            new CompanyBackupArchiveLimits(),
    ) {
        if ($targetRegistry->profile
                !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
            || !hash_equals(
                $targetRegistry->fingerprint,
                $payload->registryFingerprint,
            )
        ) {
            throw self::error('import_protected_secret_context_mismatch');
        }

        try {
            foreach (
                $targetRegistry->registry->definitionsFor(
                    $targetRegistry->profile,
                ) as $definition
            ) {
                if (!$definition->policy->hasMachineDataPayload()) {
                    continue;
                }
                $projection = CompanyBackupTableProjection::fromDefinition(
                    $definition,
                );
                $this->definitions[$definition->key] = $definition;
                $this->projections[$definition->key] = $projection;
                foreach (
                    $projection->protectedSecretMaterializations->materializations
                    as $materialization
                ) {
                    $this->protectedDeclarations[self::declarationKey(
                        $definition->key,
                        $materialization->secretColumn,
                    )] = true;
                }
            }
        } catch (CompanyBackupDataSourceException $e) {
            throw self::error(
                'import_protected_secret_context_mismatch',
                $e->registryKey,
                $e->column,
                $e,
            );
        }

        foreach ($payload->values() as $value) {
            $declarationKey = self::declarationKey(
                $value->registryKey,
                $value->name,
            );
            if ($value->scope !== CompanyBackupSecretScope::Column
                || !isset($this->protectedDeclarations[$declarationKey])
            ) {
                continue;
            }
            $key = self::valueKey($value);
            if (isset($this->values[$key])) {
                throw self::error(
                    'import_protected_secret_duplicate',
                    $value->registryKey,
                    $value->name,
                );
            }
            $this->values[$key] = $value;
        }
    }

    /**
     * @param array<string,mixed> $sourceRow
     * @return array<string,string|null>
     */
    public function valuesFor(
        TenantDataDefinition $definition,
        array $sourceRow,
        CompanyBackupPreparedImportRow $prepared,
    ): array {
        $this->assertOpen($definition->key);
        $registered = $this->definitions[$definition->key] ?? null;
        $projection = $this->projections[$definition->key] ?? null;
        if (!$registered instanceof TenantDataDefinition
            || !$projection instanceof CompanyBackupTableProjection
            || $registered->toArray() !== $definition->toArray()
            || array_keys($prepared->row) !== $projection->dataColumns
        ) {
            throw self::error(
                'import_protected_secret_row_invalid',
                $definition->key,
            );
        }

        try {
            $identity = CompanyBackupSourceIdentityProjection::fromDefinition(
                $definition,
                $this->limits,
            );
            $sourceIdentity = $identity->identityForRow($sourceRow);
            $targetIdentity = $identity->identityForRow($prepared->row);
        } catch (CompanyBackupPreflightException $e) {
            throw self::error(
                'import_protected_secret_row_invalid',
                $definition->key,
                $e->column,
                $e,
            );
        }
        if ($sourceIdentity->toArray() !== $prepared->sourceIdentity->toArray()
            || $targetIdentity->toArray() !== $prepared->targetIdentity->toArray()
        ) {
            throw self::error(
                'import_protected_secret_row_invalid',
                $definition->key,
            );
        }

        $primaryKey = $sourceIdentity->primaryKey->values;
        ksort($primaryKey, SORT_STRING);
        $primaryKeySignature = CanonicalJson::encode($primaryKey);
        $result = [];
        $consume = [];
        foreach (
            $projection->protectedSecretMaterializations->materializations
            as $materialization
        ) {
            $key = self::indexedValueKey(
                $definition->key,
                $materialization->secretColumn,
                $primaryKeySignature,
            );
            if (isset($this->consumed[$key])) {
                throw self::error(
                    'import_protected_secret_duplicate',
                    $definition->key,
                    $materialization->secretColumn,
                );
            }
            $value = $this->values[$key] ?? null;
            try {
                $stored = $projection->protectedSecretMaterializations
                    ->materializeForColumn(
                        $materialization->secretColumn,
                        $value,
                        $sourceRow,
                        $prepared->row,
                        $this->sensitiveData,
                    );
            } catch (CompanyBackupDataSourceException $e) {
                throw self::error(
                    'import_protected_secret_materialization_failed',
                    $definition->key,
                    $materialization->secretColumn,
                    $e,
                );
            }
            foreach ($stored as $column => $storedValue) {
                if (array_key_exists($column, $result)) {
                    throw self::error(
                        'import_protected_secret_materialization_failed',
                        $definition->key,
                        $column,
                    );
                }
                $result[$column] = $storedValue;
            }
            if ($value instanceof CompanyBackupSecretValue) {
                $consume[] = $key;
            }
        }
        foreach ($consume as $key) {
            $this->consumed[$key] = true;
        }
        return $result;
    }

    public function indexedValueCount(): int
    {
        return count($this->values);
    }

    public function consumedValueCount(): int
    {
        return count($this->consumed);
    }

    public function finish(): void
    {
        $this->assertOpen();
        foreach ($this->values as $key => $value) {
            if (!isset($this->consumed[$key])) {
                throw self::error(
                    'import_protected_secret_unconsumed',
                    $value->registryKey,
                    $value->name,
                );
            }
        }
        $this->values = [];
        $this->consumed = [];
        $this->closed = true;
    }

    private function assertOpen(?string $registryKey = null): void
    {
        if ($this->closed) {
            throw self::error(
                'import_protected_secret_materializer_closed',
                $registryKey,
            );
        }
    }

    private static function declarationKey(
        string $registryKey,
        string $column,
    ): string {
        return $registryKey . "\0" . $column;
    }

    private static function valueKey(CompanyBackupSecretValue $value): string
    {
        return self::indexedValueKey(
            $value->registryKey,
            $value->name,
            $value->primaryKeySignature(),
        );
    }

    private static function indexedValueKey(
        string $registryKey,
        string $column,
        string $primaryKeySignature,
    ): string {
        return implode("\0", [
            $registryKey,
            $column,
            $primaryKeySignature,
        ]);
    }

    private static function error(
        string $errorCode,
        ?string $registryKey = null,
        ?string $column = null,
        ?\Throwable $previous = null,
    ): CompanyBackupImportWriteException {
        return new CompanyBackupImportWriteException(
            $errorCode,
            $registryKey
                ?? 'profile:' . TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            $column,
            $previous,
        );
    }
}
