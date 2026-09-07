<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;

/** Výsledek atomicky commitnutého databázového i souborového importu. */
final readonly class CompanyBackupRestoreResult
{
    public string $bindingSha256;

    public function __construct(
        public CompanyBackupDatabaseImportResult $database,
        public CompanyBackupPostImportValidationResult $postImport,
        public int $publishedFileCount,
    ) {
        $publication = $database->filePublicationPlan;
        if ($postImport->supplierId !== $database->supplierId
            || !hash_equals(
                $postImport->targetRegistryFingerprint,
                $publication->registryFingerprint,
            )
            || !hash_equals(
                $postImport->filePublicationPlanBindingSha256,
                $publication->bindingSha256,
            )
            || $postImport->checkedTenantRows !== $database->insertedRows
            || $postImport->mappedGlobalRows !== $database->mappedGlobalRows
            || $postImport->presentFileCount
                !== $publication->presentEntryCount()
            || $postImport->missingFileCount
                !== $publication->missingEntryCount()
            || $publishedFileCount !== $publication->presentEntryCount()
        ) {
            throw new \InvalidArgumentException(
                'Výsledek obnovy není svázaný s post-import kontrolou.',
            );
        }
        $this->bindingSha256 = CanonicalJson::sha256([
            'format' => 'myucto-company-restore-result',
            'version' => 1,
            'supplier_id' => $database->supplierId,
            'file_publication_plan_binding_sha256' =>
                $publication->bindingSha256,
            'post_import_binding_sha256' => $postImport->bindingSha256,
            'published_file_count' => $this->publishedFileCount,
        ]);
    }
}
