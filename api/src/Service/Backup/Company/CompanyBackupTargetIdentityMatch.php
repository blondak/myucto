<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Ověřená vazba jednoho zdrojového aliasu na cílový alias a primární klíč. */
final readonly class CompanyBackupTargetIdentityMatch
{
    public function __construct(
        public CompanyBackupSourceKey $sourceKey,
        public CompanyBackupSourceKey $mappedKey,
        public CompanyBackupSourceKey $targetPrimaryKey,
        public ?string $externalRequirementId,
    ) {
        if ($sourceKey->registryKey !== $mappedKey->registryKey
            || $sourceKey->registryKey !== $targetPrimaryKey->registryKey
            || $sourceKey->columns !== $mappedKey->columns
            || ($externalRequirementId !== null
                && preg_match(
                    '/^sha256:[0-9a-f]{64}$/D',
                    $externalRequirementId,
                ) !== 1)
        ) {
            throw new \InvalidArgumentException(
                'Vazba cílové identity není platná.',
            );
        }
    }
}
