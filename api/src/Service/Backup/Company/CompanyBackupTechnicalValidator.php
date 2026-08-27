<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryCoverageValidator;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;

/** Cílová coverage brána následovaná read-only validací nahraného balíčku. */
final readonly class CompanyBackupTechnicalValidator
{
    public function __construct(
        private CompanyBackupArchiveInspector $inspector,
        private TenantDataRegistry $targetRegistry,
        private TenantDataRegistryCoverageValidator $coverageValidator,
    ) {}

    /** @param array<mixed> $runtimeInventory */
    public function validate(
        string $archivePath,
        #[\SensitiveParameter] string $password,
        string $targetAppVersion,
        string $targetSchemaRevision,
        array $runtimeInventory,
    ): CompanyBackupTechnicalValidation {
        $coverage = $this->coverageValidator->evaluate(
            $this->targetRegistry,
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            $runtimeInventory,
        );
        if (!$coverage->isSafe()) {
            throw new CompanyBackupTechnicalValidationException(
                'target_registry_incomplete',
                $coverage,
            );
        }

        $inspection = $this->inspector->inspect(
            $archivePath,
            $password,
            $targetAppVersion,
            $targetSchemaRevision,
        );
        $targetSnapshot = TenantDataRegistrySnapshot::fromRegistry(
            $this->targetRegistry,
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
        );
        return new CompanyBackupTechnicalValidation(
            $inspection,
            $targetSnapshot,
            $targetAppVersion,
            $targetSchemaRevision,
        );
    }
}
