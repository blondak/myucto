<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupInvoicesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceOccurrence;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PHPUnit\Framework\TestCase;

final class CompanyBackupInvoiceSnapshotProjectionTest extends TestCase
{
    public function testSourceInspectionVisitsOnlyLiveSnapshotReferences(): void
    {
        $row = ['id' => 101, 'supplier_id' => 7, 'supplier_snapshot' =>
            '{"id":7,"company_name":"Synthetic","email_profile_id":11,"branding_profile_id":999}'];
        $projection = $this->projection();
        $projection->assertExportRow($row);
        $occurrences = [];
        $projection->inspectCompleteSourceRow($row, static function (CompanyBackupReferenceOccurrence $ref) use (&$occurrences): void {
            $occurrences[] = $ref;
        });
        // Scalar tenant plus the supplier and email-profile references; no brand lookup.
        self::assertCount(3, $occurrences);
    }

    public function testExportAndPreflightRejectAnotherSuppliersSnapshotBeforeVisitingReferences(): void
    {
        $projection = $this->projection();
        $row = ['id' => 101, 'supplier_id' => 7, 'supplier_snapshot' =>
            '{"id":8,"company_name":"Synthetic"}'];
        foreach (['export', 'preflight'] as $stage) {
            try {
                if ($stage === 'export') {
                    $projection->assertExportRow($row);
                } else {
                    $projection->inspectCompleteSourceRow($row, static function (): never {
                        self::fail('Invalid snapshot must fail before reference discovery.');
                    });
                }
                self::fail('Cross-tenant snapshot must be rejected.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('invoice_supplier_snapshot_supplier_mismatch', $e->errorCode);
                self::assertSame('table:invoices', $e->registryKey);
                self::assertSame('supplier_snapshot', $e->column);
                self::assertStringNotContainsString('Synthetic', $e->getMessage());
            }
        }
    }

    public function testLegacyMissingIdsAndNullSnapshotsRemainAccepted(): void
    {
        $projection = $this->projection();
        foreach ([null, '{"company_name":"Synthetic"}'] as $snapshot) {
            $row = ['id' => 101, 'supplier_id' => 7, 'supplier_snapshot' => $snapshot];
            $projection->assertExportRow($row);
            $occurrences = [];
            $projection->inspectCompleteSourceRow($row, static function (CompanyBackupReferenceOccurrence $ref) use (&$occurrences): void {
                $occurrences[] = $ref;
            });
            self::assertCount(1, $occurrences);
        }
    }

    private function projection(): CompanyBackupTableProjection
    {
        $references = array_values(array_filter(
            CompanyBackupInvoicesProjection::references(),
            static fn (array $reference): bool => $reference['columns'] === ['supplier_id'],
        ));
        return CompanyBackupTableProjection::fromDefinition(new TenantDataDefinition(
            'table:invoices', TenantDataObjectKind::Table, TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE], [
                'primary_key' => ['id'],
                'ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                'secrets' => [],
                'company_backup' => [
                    'data_columns' => ['id', 'supplier_id', 'supplier_snapshot'],
                    'embedded_references' => CompanyBackupInvoicesProjection::embeddedReferences(),
                    'generated_columns' => [], 'omit_columns' => [],
                    'references' => $references, 'restore_overrides' => [],
                ],
            ],
        ));
    }
}
