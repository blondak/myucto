<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Registry;

use MyInvoice\Service\Backup\Registry\IncompleteTenantDataRegistryCoverage;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryCoverageValidator;
use PHPUnit\Framework\TestCase;

final class TenantDataRegistryCoverageValidatorTest extends TestCase
{
    public function testExplicitOmissionsAreCoveredAndSafe(): void
    {
        $registry = $this->completeRegistry([
            $this->definition('supplier', TenantDataPolicy::TenantRoot),
            $this->definition('users', TenantDataPolicy::InstanceOwned, ['reason' => 'target_identity']),
            $this->definition('cache_entries', TenantDataPolicy::RuntimeDerived, ['reason' => 'regenerated']),
        ]);

        $report = (new TenantDataRegistryCoverageValidator())->evaluate(
            $registry,
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            ['table:cache_entries', 'table:supplier', 'table:users'],
        );

        self::assertTrue($report->isSafe());
        self::assertSame([], $report->issues);
    }

    public function testNewInventoryObjectIsBlockedByDefault(): void
    {
        $registry = $this->completeRegistry([
            $this->definition('supplier', TenantDataPolicy::TenantRoot),
        ]);

        $report = (new TenantDataRegistryCoverageValidator())->evaluate(
            $registry,
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            ['table:supplier', 'table:new_agenda'],
        );

        self::assertFalse($report->isSafe());
        self::assertSame(['object_unclassified'], array_column($report->toArray()['issues'], 'code'));
        self::assertSame('table:new_agenda', $report->toArray()['issues'][0]['object']);
    }

    public function testObjectRegisteredOnlyForAnotherProfileIsStillBlocked(): void
    {
        $supplier = $this->definition('supplier', TenantDataPolicy::TenantRoot);
        $archiveOnly = new TenantDataDefinition(
            'table:archive_metadata',
            TenantDataObjectKind::Table,
            TenantDataPolicy::RuntimeDerived,
            [TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE],
            ['reason' => 'local_archive_job'],
        );
        $registry = new TenantDataRegistry(
            1,
            [$supplier, $archiveOnly],
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
        );

        $report = (new TenantDataRegistryCoverageValidator())->evaluate(
            $registry,
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            ['table:supplier', 'table:archive_metadata'],
        );

        self::assertSame(['object_outside_profile'], array_column($report->toArray()['issues'], 'code'));
    }

    public function testDeclaredObjectMissingFromRuntimeInventoryIsBlocked(): void
    {
        $registry = $this->completeRegistry([
            $this->definition('supplier', TenantDataPolicy::TenantRoot),
            $this->definition('invoices', TenantDataPolicy::TenantOwned),
        ]);

        $report = (new TenantDataRegistryCoverageValidator())->evaluate(
            $registry,
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            ['table:supplier'],
        );

        self::assertSame(['declared_object_missing'], array_column($report->toArray()['issues'], 'code'));
        self::assertSame('table:invoices', $report->toArray()['issues'][0]['object']);
    }

    public function testUnsupportedPolicyClassifiesObjectButStillStopsBackup(): void
    {
        $registry = $this->completeRegistry([
            $this->definition('supplier', TenantDataPolicy::TenantRoot),
            $this->definition('opaque_plugin_data', TenantDataPolicy::Unsupported, [
                'reason' => 'ownership_unknown',
            ]),
        ]);

        $report = (new TenantDataRegistryCoverageValidator())->evaluate(
            $registry,
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            ['table:supplier', 'table:opaque_plugin_data'],
        );

        self::assertSame(['object_unsupported'], array_column($report->toArray()['issues'], 'code'));
    }

    public function testDraftProfileCannotPassRuntimeGate(): void
    {
        $registry = new TenantDataRegistry(1, [
            $this->definition('supplier', TenantDataPolicy::TenantRoot),
        ]);

        $validator = new TenantDataRegistryCoverageValidator();
        $report = $validator->evaluate(
            $registry,
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            ['table:supplier'],
        );

        self::assertSame(['profile_incomplete'], array_column($report->toArray()['issues'], 'code'));

        $this->expectException(IncompleteTenantDataRegistryCoverage::class);
        $validator->assertSafe(
            $registry,
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            ['table:supplier'],
        );
    }

    /** @param list<TenantDataDefinition> $definitions */
    private function completeRegistry(array $definitions): TenantDataRegistry
    {
        return new TenantDataRegistry(
            1,
            $definitions,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
        );
    }

    /** @param array<string,mixed> $details */
    private function definition(
        string $table,
        TenantDataPolicy $policy,
        array $details = [],
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            $details,
        );
    }
}
