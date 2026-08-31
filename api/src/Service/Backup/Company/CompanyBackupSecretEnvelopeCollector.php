<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PDO;

/** Sestaví a zapečetí všechny povinné protected hodnoty z jednoho DB snapshotu. */
final readonly class CompanyBackupSecretEnvelopeCollector
{
    public function __construct(
        private CompanyBackupProtectedSecretSource $source,
        private CompanyBackupSecretEnvelopeCipher $cipher =
            new CompanyBackupSecretEnvelopeCipher(),
        private ?CompanyBackupOptionalSecretSource $optionalSource = null,
    ) {}

    public function collect(
        PDO $snapshot,
        TenantDataRegistrySnapshot $registry,
        int $supplierId,
        #[\SensitiveParameter] string $password,
        string $backupId,
        ?CompanyBackupSecretSelection $selection = null,
    ): CompanyBackupSealedSecretEnvelope {
        if ($supplierId < 1) {
            throw new \InvalidArgumentException(
                'Firma secret envelope musí mít kladné ID.',
            );
        }

        $selection ??= CompanyBackupSecretSelection::none($registry);
        if (!hash_equals(
            $registry->fingerprint,
            $selection->registryFingerprint,
        )) {
            throw new CompanyBackupSecretEnvelopeException(
                'secret_selection_registry_mismatch',
            );
        }

        $values = [];
        $hasRequiredProjection = false;
        foreach ($registry->registry->definitionsFor($registry->profile) as $definition) {
            if (!array_key_exists('secrets', $definition->details)) {
                continue;
            }
            $policies = CompanyBackupSecretColumnSet::fromArray(
                $definition->details['secrets'],
                $definition->key,
            )->policies;
            if (!in_array(
                TenantSecretPolicy::ProtectedDomainSecret,
                $policies,
                true,
            )) {
                continue;
            }
            $hasRequiredProjection = true;
            $projection = CompanyBackupProtectedSecretProjection::fromDefinition(
                $definition,
            );
            $allowedColumns = array_fill_keys($projection->columns, true);
            foreach ($this->source->values(
                $snapshot,
                $supplierId,
                $projection,
            ) as $value) {
                if (!$value instanceof CompanyBackupSecretValue) {
                    throw new CompanyBackupDataSourceException(
                        'secret_source_value_invalid',
                        $projection->registryKey,
                    );
                }
                if ($value->registryKey !== $projection->registryKey
                    || $value->scope !== CompanyBackupSecretScope::Column
                    || !isset($allowedColumns[$value->name])
                ) {
                    throw new CompanyBackupSecretPayloadException(
                        'secret_payload_scope_mismatch',
                    );
                }
                $value->assertPrimaryKeyColumns($projection->primaryKey);
                $values[] = $value;
            }
        }
        if (!$selection->isEmpty()) {
            if ($this->optionalSource === null) {
                throw new CompanyBackupSecretEnvelopeException(
                    'secret_selection_source_required',
                );
            }
            $selectedByRegistryKey = [];
            foreach ($selection->entries() as $entry) {
                $selectedByRegistryKey[$entry->registryKey][] = $entry;
            }
            foreach ($selectedByRegistryKey as $registryKey => $entries) {
                $definition = $registry->registry->definition($registryKey);
                if ($definition === null) {
                    throw new \LogicException(
                        'Secret selection ztratila registry objekt.',
                    );
                }
                $projection = CompanyBackupOptionalSecretProjection::fromSelection(
                    $definition,
                    $entries,
                );
                $expected = [];
                foreach ($projection->entries as $entry) {
                    $expected[$entry->valueSignature()] = true;
                }
                $seen = [];
                foreach ($this->optionalSource->values(
                    $snapshot,
                    $supplierId,
                    $projection,
                ) as $value) {
                    if (!$value instanceof CompanyBackupSecretValue) {
                        throw new CompanyBackupDataSourceException(
                            'secret_source_value_invalid',
                            $projection->registryKey,
                        );
                    }
                    $signature = $value->declarationSignature() . ':'
                        . $value->primaryKeySignature();
                    if (!isset($expected[$signature]) || isset($seen[$signature])) {
                        throw new CompanyBackupSecretPayloadException(
                            'secret_payload_scope_mismatch',
                        );
                    }
                    $seen[$signature] = true;
                    $values[] = $value;
                }
                if (array_keys($seen) !== array_keys($expected)) {
                    throw new CompanyBackupDataSourceException(
                        'secret_selected_value_missing',
                        $projection->registryKey,
                    );
                }
            }
        }
        if (!$hasRequiredProjection && $selection->isEmpty()) {
            throw new CompanyBackupSecretEnvelopeException(
                'secret_envelope_not_required',
            );
        }

        $payload = CompanyBackupSecretPayload::fromValues($values, $registry);
        $plaintext = $payload->toJson();
        unset($payload, $values);
        try {
            return $this->cipher->seal(
                $plaintext,
                $password,
                $backupId,
                $registry->fingerprint,
            );
        } finally {
            self::wipe($plaintext);
        }
    }

    private static function wipe(string &$value): void
    {
        $sensitive = $value;
        $value = '';
        if ($sensitive !== '' && function_exists('sodium_memzero')) {
            sodium_memzero($sensitive);
        }
    }
}
