<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Registry;

use InvalidArgumentException;
use MyInvoice\Service\Backup\Registry\IncompleteTenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PHPUnit\Framework\TestCase;

final class TenantDataRegistryTest extends TestCase
{
    public function testCompleteProfileHasStableFingerprintAcrossDefinitionOrder(): void
    {
        $supplier = $this->definition('supplier', TenantDataPolicy::TenantRoot);
        $invoices = $this->definition('invoices', TenantDataPolicy::TenantOwned, [
            'primary_key' => ['id'],
            'ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
        ]);

        $first = new TenantDataRegistry(1, [$supplier, $invoices], [TenantDataRegistry::COMPANY_BACKUP_PROFILE]);
        $reordered = new TenantDataRegistry(1, [$invoices, $supplier], [TenantDataRegistry::COMPANY_BACKUP_PROFILE]);

        self::assertSame(
            $first->fingerprintFor(TenantDataRegistry::COMPANY_BACKUP_PROFILE),
            $reordered->fingerprintFor(TenantDataRegistry::COMPANY_BACKUP_PROFILE),
        );
        self::assertMatchesRegularExpression(
            '/^sha256:[a-f0-9]{64}$/D',
            $first->fingerprintFor(TenantDataRegistry::COMPANY_BACKUP_PROFILE),
        );
        self::assertSame(
            ['table:invoices', 'table:supplier'],
            array_map(
                static fn (TenantDataDefinition $definition): string => $definition->key,
                $first->definitionsFor(TenantDataRegistry::COMPANY_BACKUP_PROFILE),
            ),
        );
    }

    public function testChangedPolicyOrDetailsChangesFingerprint(): void
    {
        $owned = new TenantDataRegistry(
            1,
            [$this->definition('documents', TenantDataPolicy::TenantOwned)],
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
        );
        $derived = new TenantDataRegistry(
            1,
            [$this->definition('documents', TenantDataPolicy::RuntimeDerived, ['reason' => 'rendered_copy'])],
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
        );

        self::assertNotSame(
            $owned->fingerprintFor(TenantDataRegistry::COMPANY_BACKUP_PROFILE),
            $derived->fingerprintFor(TenantDataRegistry::COMPANY_BACKUP_PROFILE),
        );
    }

    public function testDraftProfileCannotProduceFingerprint(): void
    {
        $registry = new TenantDataRegistry(1, [
            $this->definition('supplier', TenantDataPolicy::TenantRoot),
        ]);

        $this->expectException(IncompleteTenantDataRegistry::class);

        $registry->fingerprintFor(TenantDataRegistry::COMPANY_BACKUP_PROFILE);
    }

    public function testDefinitionKeyMustMatchObjectKind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('neodpovídá druhu');

        new TenantDataDefinition(
            'file-area:attachments',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [],
        );
    }

    public function testUnsupportedDefinitionRequiresStableReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('důvod');

        $this->definition('unknown_agenda', TenantDataPolicy::Unsupported);
    }

    public function testRegistryProvidesExactLookupWithoutCaseFolding(): void
    {
        $definition = $this->definition('supplier', TenantDataPolicy::TenantRoot);
        $registry = new TenantDataRegistry(1, [$definition]);

        self::assertSame($definition, $registry->definition('table:supplier'));
        self::assertNull($registry->definition('table:Supplier'));
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
