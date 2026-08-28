<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;

/** Osud registrované souborové oblasti při exportu a obnově. */
enum CompanyBackupFilePolicy: string
{
    case Required = 'required';
    case HistoricalOptional = 'historical_optional';
    case Derived = 'derived';
    case Unsupported = 'unsupported';

    public static function fromDefinition(TenantDataDefinition $definition): self
    {
        $value = $definition->details['file_policy'] ?? null;
        $policy = is_string($value) ? self::tryFrom($value) : null;
        if ($definition->kind !== TenantDataObjectKind::FileArea
            || !$definition->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
            || $policy === null
        ) {
            throw new \InvalidArgumentException(
                'Souborová oblast ' . $definition->key . ' nemá platnou file_policy.',
            );
        }
        if ($definition->policy->hasMachineDataPayload()
            && !in_array($policy, [self::Required, self::HistoricalOptional], true)
        ) {
            throw new \InvalidArgumentException(
                'Payload souborové oblasti musí mít required nebo historical_optional politiku.',
            );
        }
        return $policy;
    }
}
