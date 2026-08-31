<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/** Kanonický, registry-bound plaintext uvnitř secret envelope. */
final readonly class CompanyBackupSecretPayload
{
    public const FORMAT = 'myucto-company-secret-payload';
    public const VERSION = 1;
    private const MAX_DECLARATIONS = 10_000;
    private const MAX_VALUES = 100_000;

    /**
     * @var list<array{
     *   registry_key:string,
     *   scope:CompanyBackupSecretScope,
     *   name:string,
     *   policy:TenantSecretPolicy,
     *   values:list<CompanyBackupSecretValue>
     * }>
     */
    private array $declarations;

    /**
     * @param list<array{
     *   registry_key:string,
     *   scope:CompanyBackupSecretScope,
     *   name:string,
     *   policy:TenantSecretPolicy,
     *   values:list<CompanyBackupSecretValue>
     * }> $declarations
     */
    private function __construct(
        array $declarations,
        public string $registryFingerprint,
    ) {
        $this->declarations = $declarations;
    }

    /** @param array<mixed> $values */
    public static function fromValues(
        array $values,
        TenantDataRegistrySnapshot $registry,
    ): self {
        if (!array_is_list($values) || count($values) > self::MAX_VALUES) {
            throw self::invalid();
        }
        $expected = self::registryDeclarations($registry);
        $groups = [];
        foreach ($expected as $signature => $declaration) {
            if ($declaration['required']) {
                $groups[$signature] = self::emptyGroup($declaration);
            }
        }
        foreach ($values as $value) {
            if (!$value instanceof CompanyBackupSecretValue) {
                throw self::invalid();
            }
            $signature = $value->declarationSignature();
            $declaration = $expected[$signature] ?? null;
            if ($declaration === null) {
                throw new CompanyBackupSecretPayloadException(
                    'secret_payload_scope_mismatch',
                );
            }
            $value->assertPrimaryKeyColumns($declaration['primary_key']);
            $groups[$signature] ??= self::emptyGroup($declaration);
            $groups[$signature]['values'][] = $value;
        }
        ksort($groups, SORT_STRING);
        foreach ($groups as &$group) {
            usort(
                $group['values'],
                static fn (
                    CompanyBackupSecretValue $left,
                    CompanyBackupSecretValue $right,
                ): int => strcmp(
                    $left->primaryKeySignature(),
                    $right->primaryKeySignature(),
                ),
            );
        }
        unset($group);
        return self::fromArray(self::groupsToArray(
            array_values($groups),
            $registry->fingerprint,
        ), $registry);
    }

    public static function fromJson(
        #[\SensitiveParameter] string $json,
        TenantDataRegistrySnapshot $registry,
    ): self {
        if ($json === ''
            || strlen($json)
                > CompanyBackupSecretEnvelopeDescriptor::MAX_PLAINTEXT_BYTES
        ) {
            throw self::invalid();
        }
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!hash_equals(CanonicalJson::encode($value), $json)) {
                throw self::invalid();
            }
        } catch (CompanyBackupSecretPayloadException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CompanyBackupSecretPayloadException(
                'secret_payload_invalid',
                $e,
            );
        }
        return self::fromArray($value, $registry);
    }

    public static function fromArray(
        mixed $payload,
        TenantDataRegistrySnapshot $registry,
    ): self {
        if (!is_array($payload) || array_is_list($payload)) {
            throw self::invalid();
        }
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'declarations',
            'format',
            'registry_fingerprint',
            'version',
        ] || $payload['format'] !== self::FORMAT
            || $payload['version'] !== self::VERSION
            || !is_string($payload['registry_fingerprint'])
            || preg_match(
                '/^sha256:[0-9a-f]{64}$/D',
                $payload['registry_fingerprint'],
            ) !== 1
            || !is_array($payload['declarations'])
            || !array_is_list($payload['declarations'])
            || count($payload['declarations']) > self::MAX_DECLARATIONS
        ) {
            throw self::invalid();
        }
        if (!hash_equals(
            $registry->fingerprint,
            $payload['registry_fingerprint'],
        )) {
            throw new CompanyBackupSecretPayloadException(
                'secret_payload_registry_mismatch',
            );
        }

        $expected = self::registryDeclarations($registry);
        $declarations = [];
        $seen = [];
        $orderedSignatures = [];
        $totalValues = 0;
        foreach ($payload['declarations'] as $rawDeclaration) {
            if (!is_array($rawDeclaration) || array_is_list($rawDeclaration)) {
                throw self::invalid();
            }
            $declarationKeys = array_keys($rawDeclaration);
            sort($declarationKeys, SORT_STRING);
            $registryKey = $rawDeclaration['registry_key'] ?? null;
            $scopeValue = $rawDeclaration['scope'] ?? null;
            $name = $rawDeclaration['name'] ?? null;
            $policyValue = $rawDeclaration['policy'] ?? null;
            $rawValues = $rawDeclaration['values'] ?? null;
            $scope = is_string($scopeValue)
                ? CompanyBackupSecretScope::tryFrom($scopeValue)
                : null;
            $policy = is_string($policyValue)
                ? TenantSecretPolicy::tryFrom($policyValue)
                : null;
            if ($declarationKeys !== [
                'name',
                'policy',
                'registry_key',
                'scope',
                'values',
            ] || !is_string($registryKey)
                || $scope === null
                || !is_string($name)
                || $policy === null
                || !is_array($rawValues)
                || !array_is_list($rawValues)
            ) {
                throw self::invalid();
            }
            $signature = self::signature($registryKey, $scope, $name);
            $definition = $expected[$signature] ?? null;
            if ($definition === null || $definition['policy'] !== $policy) {
                throw new CompanyBackupSecretPayloadException(
                    'secret_payload_scope_mismatch',
                );
            }
            if (isset($seen[$signature])
                || !$definition['required'] && $rawValues === []
            ) {
                throw self::invalid();
            }
            $seen[$signature] = true;
            $orderedSignatures[] = $signature;

            $values = [];
            $valueSignatures = [];
            foreach ($rawValues as $rawValue) {
                if ($totalValues === self::MAX_VALUES) {
                    throw self::invalid();
                }
                $totalValues++;
                $value = CompanyBackupSecretValue::fromArray(
                    $rawValue,
                    $registryKey,
                    $scope,
                    $name,
                    $definition['primary_key'],
                );
                $valueSignature = $value->primaryKeySignature();
                if (isset($valueSignatures[$valueSignature])) {
                    throw self::invalid();
                }
                $valueSignatures[$valueSignature] = true;
                $values[] = $value;
            }
            $sortedValueSignatures = array_keys($valueSignatures);
            sort($sortedValueSignatures, SORT_STRING);
            if (array_keys($valueSignatures) !== $sortedValueSignatures) {
                throw self::invalid();
            }
            $declarations[] = [
                'registry_key' => $registryKey,
                'scope' => $scope,
                'name' => $name,
                'policy' => $policy,
                'values' => $values,
            ];
        }

        $sortedSignatures = $orderedSignatures;
        sort($sortedSignatures, SORT_STRING);
        if ($orderedSignatures !== $sortedSignatures) {
            throw self::invalid();
        }
        foreach ($expected as $signature => $definition) {
            if ($definition['required'] && !isset($seen[$signature])) {
                throw new CompanyBackupSecretPayloadException(
                    'secret_payload_required_missing',
                );
            }
        }
        return new self(
            $declarations,
            $registry->fingerprint,
        );
    }

    /** @return list<CompanyBackupSecretValue> */
    public function values(): array
    {
        $values = [];
        foreach ($this->declarations as $declaration) {
            array_push($values, ...$declaration['values']);
        }
        return $values;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return self::groupsToArray(
            $this->declarations,
            $this->registryFingerprint,
        );
    }

    public function toJson(): string
    {
        $json = CanonicalJson::encode($this->toArray());
        if (strlen($json)
            > CompanyBackupSecretEnvelopeDescriptor::MAX_PLAINTEXT_BYTES
        ) {
            throw self::invalid();
        }
        return $json;
    }

    /**
     * @return array<string,array{
     *   registry_key:string,
     *   scope:CompanyBackupSecretScope,
     *   name:string,
     *   policy:TenantSecretPolicy,
     *   primary_key:list<string>,
     *   required:bool
     * }>
     */
    private static function registryDeclarations(
        TenantDataRegistrySnapshot $registry,
    ): array {
        $result = [];
        try {
            foreach ($registry->registry->definitionsFor($registry->profile) as $definition) {
                if ($definition->policy === TenantDataPolicy::ProtectedDomainSecret) {
                    throw new CompanyBackupSecretPayloadException(
                        'secret_payload_scope_mismatch',
                    );
                }
                if (array_key_exists('secrets', $definition->details)) {
                    $policies = CompanyBackupSecretColumnSet::fromArray(
                        $definition->details['secrets'],
                        $definition->key,
                    )->policies;
                    if (in_array(
                        TenantSecretPolicy::ProtectedDomainSecret,
                        $policies,
                        true,
                    )) {
                        CompanyBackupProtectedSecretProjection::fromDefinition(
                            $definition,
                        );
                    }
                    $primaryKey = null;
                    foreach ($policies as $name => $policy) {
                        if (!self::transferable($policy)) {
                            continue;
                        }
                        if ($definition->kind !== TenantDataObjectKind::Table) {
                            throw new CompanyBackupSecretPayloadException(
                                'secret_payload_scope_mismatch',
                            );
                        }
                        $primaryKey ??= self::primaryKey($definition);
                        self::addExpected(
                            $result,
                            $definition->key,
                            CompanyBackupSecretScope::Column,
                            $name,
                            $policy,
                            $primaryKey,
                        );
                    }
                }
                if ($definition->policy === TenantDataPolicy::OptionalCredential
                    || array_key_exists(
                        'company_backup_credential',
                        $definition->details,
                    )
                ) {
                    $projection = CompanyBackupCredentialTableProjection::fromDefinition(
                        $definition,
                    );
                    foreach ($projection->variants as $variant) {
                        self::addExpected(
                            $result,
                            $definition->key,
                            CompanyBackupSecretScope::CredentialVariant,
                            $variant['name'],
                            $variant['policy'],
                            $projection->primaryKey,
                        );
                    }
                }
            }
        } catch (CompanyBackupSecretPayloadException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CompanyBackupSecretPayloadException(
                'secret_payload_scope_mismatch',
                $e,
            );
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** @return list<string> */
    private static function primaryKey(TenantDataDefinition $definition): array
    {
        $value = $definition->details['primary_key'] ?? null;
        if (!is_array($value)
            || !array_is_list($value)
            || $value === []
            || count($value) > 16
        ) {
            throw new CompanyBackupSecretPayloadException(
                'secret_payload_scope_mismatch',
            );
        }
        $result = [];
        $seen = [];
        foreach ($value as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || isset($seen[$column])
            ) {
                throw new CompanyBackupSecretPayloadException(
                    'secret_payload_scope_mismatch',
                );
            }
            $seen[$column] = true;
            $result[] = $column;
        }
        return $result;
    }

    private static function transferable(TenantSecretPolicy $policy): bool
    {
        return in_array($policy, [
            TenantSecretPolicy::ProtectedDomainSecret,
            TenantSecretPolicy::OptionalCredential,
            TenantSecretPolicy::PersonalWithDualConsent,
        ], true);
    }

    /**
     * @param array<string,array{
     *   registry_key:string,
     *   scope:CompanyBackupSecretScope,
     *   name:string,
     *   policy:TenantSecretPolicy,
     *   primary_key:list<string>,
     *   required:bool
     * }> $result
     * @param list<string> $primaryKey
     */
    private static function addExpected(
        array &$result,
        string $registryKey,
        CompanyBackupSecretScope $scope,
        string $name,
        TenantSecretPolicy $policy,
        array $primaryKey,
    ): void {
        if (!self::transferable($policy)) {
            throw new CompanyBackupSecretPayloadException(
                'secret_payload_scope_mismatch',
            );
        }
        $signature = self::signature($registryKey, $scope, $name);
        if (isset($result[$signature])) {
            throw new CompanyBackupSecretPayloadException(
                'secret_payload_scope_mismatch',
            );
        }
        $result[$signature] = [
            'registry_key' => $registryKey,
            'scope' => $scope,
            'name' => $name,
            'policy' => $policy,
            'primary_key' => $primaryKey,
            'required' => $policy === TenantSecretPolicy::ProtectedDomainSecret,
        ];
    }

    /**
     * @param array{
     *   registry_key:string,
     *   scope:CompanyBackupSecretScope,
     *   name:string,
     *   policy:TenantSecretPolicy,
     *   primary_key:list<string>,
     *   required:bool
     * } $declaration
     * @return array{
     *   registry_key:string,
     *   scope:CompanyBackupSecretScope,
     *   name:string,
     *   policy:TenantSecretPolicy,
     *   values:list<CompanyBackupSecretValue>
     * }
     */
    private static function emptyGroup(array $declaration): array
    {
        return [
            'registry_key' => $declaration['registry_key'],
            'scope' => $declaration['scope'],
            'name' => $declaration['name'],
            'policy' => $declaration['policy'],
            'values' => [],
        ];
    }

    /**
     * @param list<array{
     *   registry_key:string,
     *   scope:CompanyBackupSecretScope,
     *   name:string,
     *   policy:TenantSecretPolicy,
     *   values:list<CompanyBackupSecretValue>
     * }> $groups
     * @return array<string,mixed>
     */
    private static function groupsToArray(
        array $groups,
        string $registryFingerprint,
    ): array {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'registry_fingerprint' => $registryFingerprint,
            'declarations' => array_map(
                static fn (array $group): array => [
                    'registry_key' => $group['registry_key'],
                    'scope' => $group['scope']->value,
                    'name' => $group['name'],
                    'policy' => $group['policy']->value,
                    'values' => array_map(
                        static fn (CompanyBackupSecretValue $value): array =>
                            $value->toArray(),
                        $group['values'],
                    ),
                ],
                $groups,
            ),
        ];
    }

    private static function signature(
        string $registryKey,
        CompanyBackupSecretScope $scope,
        string $name,
    ): string {
        return $registryKey . ':' . $scope->value . ':' . $name;
    }

    private static function invalid(): CompanyBackupSecretPayloadException
    {
        return new CompanyBackupSecretPayloadException('secret_payload_invalid');
    }
}
