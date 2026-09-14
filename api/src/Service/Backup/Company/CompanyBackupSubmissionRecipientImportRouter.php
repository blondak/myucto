<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use PDO;

/** Smíšený číselník: systémové řádky mapuje, vlastní řádky nechává zapsat. */
final class CompanyBackupSubmissionRecipientImportRouter
{
    /** @param array<string,mixed> $row */
    public static function isSystemRow(string $registryKey, array $row): bool
    {
        return $registryKey === CompanyBackupSubmissionRecipientsProjection::REGISTRY_KEY
            && CompanyBackupSubmissionRecipientsProjection::rowPolicy($row, $registryKey)
                === TenantDataPolicy::GlobalReference;
    }

    public static function countSystemRows(
        CompanyBackupImportSource $source,
        CompanyBackupDataObject $object,
        TenantDataDefinition $definition,
    ): int {
        CompanyBackupSubmissionRecipientsProjection::assertDefinition($definition);
        $systemRows = 0;
        $consumed = $source->consumeRows($definition->key,
            static function (array $row) use ($definition, &$systemRows): void {
                if (self::isSystemRow($definition->key, $row)) {
                    $systemRows++;
                }
            },
        );
        if ($consumed !== $object->rows) {
            throw new CompanyBackupPreflightException(
                'system_recipient_source_count_mismatch', $definition->key,
            );
        }
        return $systemRows;
    }

    public static function mapSystemRows(
        PDO $database,
        CompanyBackupImportSource $source,
        CompanyBackupDataObject $object,
        TenantDataDefinition $definition,
        CompanyBackupTargetIdentityMap $identities,
        CompanyBackupArchiveLimits $limits,
    ): int {
        CompanyBackupSubmissionRecipientsProjection::assertDefinition($definition);
        $projection = CompanyBackupSourceIdentityProjection::fromDefinition($definition, $limits);
        $systemRows = 0;
        $consumed = $source->consumeRows($definition->key,
            static function (array $row) use (
                $database, $definition, $projection, $identities, &$systemRows,
            ): void {
                if (!self::isSystemRow($definition->key, $row)) {
                    return;
                }
                $targetId = CompanyBackupSystemRecipientMatcher::match($database, $row, true);
                $targetRow = $row;
                $targetRow['id'] = $targetId;
                $sourceIdentity = $projection->identityForRow($row);
                $targetIdentity = $projection->identityForRow($targetRow);
                if ($sourceIdentity->policy !== TenantDataPolicy::GlobalReference
                    || $targetIdentity->policy !== TenantDataPolicy::GlobalReference
                    || $sourceIdentity->naturalKey === null
                    || $sourceIdentity->tenantScopedPrimaryKey !== null
                    || $sourceIdentity->referenceKeys !== []
                ) {
                    throw new CompanyBackupPreflightException(
                        'system_recipient_identity_contract_invalid', $definition->key,
                    );
                }
                $identities->add($sourceIdentity, $targetIdentity);
                $systemRows++;
            },
        );
        if ($consumed !== $object->rows) {
            throw new CompanyBackupPreflightException(
                'system_recipient_source_count_mismatch', $definition->key,
            );
        }
        return $systemRows;
    }
}
