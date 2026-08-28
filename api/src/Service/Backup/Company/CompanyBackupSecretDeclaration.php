<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;

/** Jedno registry místo, jehož výchozí vynechání musí být spočítané. */
final readonly class CompanyBackupSecretDeclaration
{
    public CompanyBackupSecretOmissionReason $reason;

    public function __construct(
        public string $registryKey,
        public CompanyBackupSecretScope $scope,
        public string $name,
        public TenantSecretPolicy $policy,
    ) {
        $reason = CompanyBackupSecretOmissionReason::forPolicy($policy);
        if (!TenantDataDefinition::isValidKey($registryKey)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) !== 1
            || $reason === null
        ) {
            throw new \InvalidArgumentException(
                'Deklarace vynechaného secretu není platná.',
            );
        }
        $this->reason = $reason;
    }

    public function signature(): string
    {
        return $this->registryKey . ':' . $this->scope->value . ':' . $this->name;
    }
}
