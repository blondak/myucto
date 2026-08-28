<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/** Jediný parser sloupcových secret politik z tenantového registru. */
final readonly class CompanyBackupSecretColumnSet
{
    /** @var array<string,TenantSecretPolicy> */
    public array $policies;

    /** @param array<string,TenantSecretPolicy> $policies */
    private function __construct(array $policies)
    {
        $this->policies = $policies;
    }

    public static function fromArray(mixed $value, string $registryKey): self
    {
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw new CompanyBackupDataSourceException(
                'data_secret_registry_invalid',
                $registryKey,
            );
        }
        $result = [];
        foreach ($value as $column => $declaration) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || !is_array($declaration)
                || array_is_list($declaration)
            ) {
                throw new CompanyBackupDataSourceException(
                    'data_secret_registry_invalid',
                    $registryKey,
                );
            }
            $policyValue = $declaration['policy'] ?? null;
            $policy = is_string($policyValue)
                ? TenantSecretPolicy::tryFrom($policyValue)
                : null;
            if ($policy === null) {
                throw new CompanyBackupDataSourceException(
                    'data_secret_registry_invalid',
                    $registryKey,
                    $column,
                );
            }
            $result[$column] = $policy;
        }
        ksort($result, SORT_STRING);
        return new self($result);
    }
}
