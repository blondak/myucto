<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;

/** Exportní kontrakt runtime kořene jedné registrované souborové oblasti. */
final readonly class CompanyBackupFileAreaProjection
{
    private function __construct(
        public string $registryKey,
        public string $name,
        public CompanyBackupFilePolicy $policy,
        public CompanyBackupFilePathPolicy $pathPolicy,
        public string $storageSubdirectory,
        public CompanyBackupFileOwnerSet $owners,
    ) {}

    public static function fromDefinition(
        TenantDataDefinition $definition,
        TenantDataRegistry $registry,
    ): self {
        $subdirectory = $definition->details['storage_subdirectory'] ?? null;
        $ownership = $definition->details['ownership'] ?? null;
        if ($definition->kind !== TenantDataObjectKind::FileArea
            || !$definition->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
            || !$definition->policy->hasMachineDataPayload()
            || !is_string($subdirectory)
            || !self::validSubdirectory($subdirectory)
            || !is_array($ownership)
            || array_is_list($ownership)
            || ($ownership['strategy'] ?? null) !== 'database_references'
        ) {
            throw new CompanyBackupFileSourceException(
                'file_area_metadata_invalid',
                $definition->key,
            );
        }
        try {
            $policy = CompanyBackupFilePolicy::fromDefinition($definition);
            $pathPolicy = CompanyBackupFilePathPolicy::fromDefinition($definition);
            $owners = CompanyBackupFileOwnerSet::fromDefinition(
                $definition,
                $registry,
            );
            foreach ($owners->owners as $owner) {
                if (in_array($pathPolicy, [
                        CompanyBackupFilePathPolicy::SupplierContentHash,
                        CompanyBackupFilePathPolicy::SupplierInvoiceAttachment,
                        CompanyBackupFilePathPolicy::SupplierInvoicePdf,
                        CompanyBackupFilePathPolicy::SupplierImportedInvoicePdf,
                        CompanyBackupFilePathPolicy::SupplierPurchaseInvoicePdf,
                    ], true)
                    !== ($owner->storedPrefix === '')
                ) {
                    throw new \InvalidArgumentException(
                        'Souborový vlastník nemá jednoznačné kódování.',
                    );
                }
            }
            if ($pathPolicy === CompanyBackupFilePathPolicy::SupplierImportedInvoicePdf) {
                self::assertDirectInvoiceDocumentContract(
                    $subdirectory, $owners, $registry,
                    'invoices-imported', 'table:invoices', 'imported_pdf_path', 'Importované PDF',
                );
            }
            if ($pathPolicy === CompanyBackupFilePathPolicy::SupplierPurchaseInvoicePdf) {
                self::assertDirectInvoiceDocumentContract(
                    $subdirectory, $owners, $registry,
                    'purchase-invoices', 'table:purchase_invoices', 'pdf_path', 'PDF přijaté faktury',
                );
            }
            if ($pathPolicy === CompanyBackupFilePathPolicy::SupplierInvoiceAttachment) {
                self::assertInvoiceFileContract(
                    $subdirectory,
                    $owners,
                    $registry,
                    'table:invoice_attachments',
                    'Příloha',
                );
            }
            if ($pathPolicy === CompanyBackupFilePathPolicy::SupplierInvoicePdf) {
                self::assertInvoiceFileContract(
                    $subdirectory,
                    $owners,
                    $registry,
                    'table:invoice_pdfs',
                    'PDF archiv',
                );
            }
        } catch (\InvalidArgumentException|CompanyBackupDataSourceException $e) {
            throw new CompanyBackupFileSourceException(
                'file_area_metadata_invalid',
                $definition->key,
                previous: $e,
            );
        }
        return new self(
            $definition->key,
            $definition->name(),
            $policy,
            $pathPolicy,
            $subdirectory,
            $owners,
        );
    }

    private static function assertInvoiceFileContract(
        string $subdirectory,
        CompanyBackupFileOwnerSet $owners,
        TenantDataRegistry $registry,
        string $ownerRegistryKey,
        string $label,
    ): void {
        $owner = $owners->owners[0] ?? null;
        $target = $registry->definition($ownerRegistryKey);
        if ($subdirectory !== 'invoices'
            || count($owners->owners) !== 1
            || !$owner instanceof CompanyBackupFileOwnerDefinition
            || $owner->registryKey !== $ownerRegistryKey
            || $owner->column !== 'filename'
            || $owner->path !== []
            || $owner->storedPrefix !== ''
            || !$target instanceof TenantDataDefinition
            || $target->kind !== TenantDataObjectKind::Table
            || $target->policy !== TenantDataPolicy::TenantOwnedIndirect
            || ($target->details['primary_key'] ?? null) !== ['id']
        ) {
            throw new \InvalidArgumentException(
                $label . ' nemá jednoznačného databázového vlastníka.',
            );
        }
        $expectedOwnership = [
            'strategy' => 'foreign_key_path',
            'path' => [
                ['from_column' => 'invoice_id', 'to_table' => 'invoices',
                    'to_column' => 'id'],
                ['from_column' => 'supplier_id', 'to_table' => 'supplier',
                    'to_column' => 'id'],
            ],
        ];
        if (CanonicalJson::encode($target->details['ownership'] ?? null)
            !== CanonicalJson::encode($expectedOwnership)
        ) {
            throw new \InvalidArgumentException(
                $label . ' nemá přímou tenantovou vazbu přes fakturu.',
            );
        }

        $projection = CompanyBackupTableProjection::fromDefinition($target);
        if ($projection->primaryKey !== ['id']
            || !in_array('invoice_id', $projection->dataColumns, true)
            || !in_array('filename', $projection->dataColumns, true)
        ) {
            throw new \InvalidArgumentException(
                $label . ' nemá exportované ID faktury a název souboru.',
            );
        }
        self::assertRequiredReference($projection, 'invoice_id', 'table:invoices');
    }

    private static function assertDirectInvoiceDocumentContract(
        string $subdirectory,
        CompanyBackupFileOwnerSet $owners,
        TenantDataRegistry $registry,
        string $expectedSubdirectory,
        string $ownerRegistryKey,
        string $pathColumn,
        string $label,
    ): void {
        $owner = $owners->owners[0] ?? null;
        $target = $registry->definition($ownerRegistryKey);
        if ($subdirectory !== $expectedSubdirectory
            || count($owners->owners) !== 1
            || !$owner instanceof CompanyBackupFileOwnerDefinition
            || $owner->registryKey !== $ownerRegistryKey
            || $owner->column !== $pathColumn
            || $owner->path !== [] || $owner->storedPrefix !== ''
            || !$target instanceof TenantDataDefinition
            || $target->kind !== TenantDataObjectKind::Table
            || $target->policy !== TenantDataPolicy::TenantOwned
            || ($target->details['primary_key'] ?? null) !== ['id']
            || CanonicalJson::encode($target->details['ownership'] ?? null)
                !== CanonicalJson::encode(['strategy' => 'supplier_id', 'column' => 'supplier_id'])
        ) {
            throw new \InvalidArgumentException($label . ' nemá jednoznačného vlastníka.');
        }
        $projection = CompanyBackupTableProjection::fromDefinition($target);
        if (!in_array('supplier_id', $projection->dataColumns, true)
            || !in_array($pathColumn, $projection->dataColumns, true)
        ) {
            throw new \InvalidArgumentException($label . ' nemá exportovanou cestu a firmu.');
        }
        self::assertRequiredReference($projection, 'supplier_id', 'table:supplier');
    }

    private static function assertRequiredReference(
        CompanyBackupTableProjection $projection,
        string $column,
        string $target,
    ): void {
        $references = array_values(array_filter(
            $projection->references->references,
            static fn (CompanyBackupReference $reference): bool =>
                in_array($column, $reference->columns, true),
        ));
        $reference = $references[0] ?? null;
        if (count($references) !== 1
            || !$reference instanceof CompanyBackupReference
            || $reference->columns !== [$column]
            || $reference->target !== $target
            || $reference->targetColumns !== ['id']
            || $reference->mapping !== CompanyBackupReferenceMapping::TenantId
            || $reference->constraint !== CompanyBackupReferenceConstraint::Required
            || $reference->nullableColumns !== []
            || $reference->fallbacks !== []
            || $reference->condition !== null
        ) {
            throw new \InvalidArgumentException('Vlastník souboru nemá povinnou ne-null referenci.');
        }
    }

    private static function validSubdirectory(string $value): bool
    {
        if ($value === ''
            || strlen($value) > 255
            || str_starts_with($value, '/')
            || str_ends_with($value, '/')
            || str_contains($value, '\\')
            || preg_match('/\A[A-Za-z]:/', $value) === 1
        ) {
            return false;
        }
        foreach (explode('/', $value) as $segment) {
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $segment) !== 1
                || $segment === '.'
                || $segment === '..'
                || str_ends_with($segment, '.')
                || str_ends_with($segment, ' ')
            ) {
                return false;
            }
        }
        return true;
    }
}
