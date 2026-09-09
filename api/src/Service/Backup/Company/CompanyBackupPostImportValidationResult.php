<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;

/** Neměnný důkaz registry, souborových a doménových kontrol před commitem. */
final readonly class CompanyBackupPostImportValidationResult
{
    public string $bindingSha256;

    public function __construct(
        public int $supplierId,
        public string $targetRegistryFingerprint,
        public string $dataPreflightBindingSha256,
        public string $filePublicationPlanBindingSha256,
        public CompanyBackupPostImportInvariantReport $invariantReport,
        public int $checkedTableCount,
        public int $checkedTenantRows,
        public int $mappedGlobalRows,
        public int $presentFileCount,
        public int $missingFileCount,
        public ?string $manualConfigurationBindingSha256 = null,
    ) {
        if ($supplierId < 1
            || ($manualConfigurationBindingSha256 !== null
                && preg_match('/^[0-9a-f]{64}$/D', $manualConfigurationBindingSha256) !== 1)
            || preg_match(
                '/^sha256:[0-9a-f]{64}$/D',
                $targetRegistryFingerprint,
            ) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $dataPreflightBindingSha256) !== 1
            || preg_match(
                '/^[0-9a-f]{64}$/D',
                $filePublicationPlanBindingSha256,
            ) !== 1
            || $invariantReport->supplierId !== $supplierId
            || !hash_equals(
                $targetRegistryFingerprint,
                $invariantReport->targetRegistryFingerprint,
            )
            || min(
                $checkedTableCount,
                $checkedTenantRows,
                $mappedGlobalRows,
                $presentFileCount,
                $missingFileCount,
            ) < 0
        ) {
            throw new \InvalidArgumentException(
                'Výsledek post-import kontroly není platný.',
            );
        }
        $this->bindingSha256 = CanonicalJson::sha256([
            'format' => 'myucto-company-post-import-validation',
            'version' => 2,
            'supplier_id' => $supplierId,
            'target_registry_fingerprint' => $targetRegistryFingerprint,
            'data_preflight_binding_sha256' => $dataPreflightBindingSha256,
            'file_publication_plan_binding_sha256' =>
                $filePublicationPlanBindingSha256,
            'invariant_report_binding_sha256' =>
                $invariantReport->bindingSha256,
            'checked_table_count' => $checkedTableCount,
            'checked_tenant_rows' => $checkedTenantRows,
            'mapped_global_rows' => $mappedGlobalRows,
            'present_file_count' => $presentFileCount,
            'missing_file_count' => $missingFileCount,
            ...($manualConfigurationBindingSha256 === null ? [] : [
                'manual_configuration_binding_sha256' => $manualConfigurationBindingSha256,
            ]),
        ]);
    }
}
