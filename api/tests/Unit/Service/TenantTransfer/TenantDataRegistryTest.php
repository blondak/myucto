<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Registry\IncompleteTenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use PHPUnit\Framework\TestCase;

final class TenantDataRegistryTest extends TestCase
{
    public function testCompleteProfileHasStableFingerprintAcrossDefinitionOrder(): void
    {
        $supplier = $this->definition(
            'table:supplier',
            TenantDataPolicy::TenantRoot,
            ['ownership' => ['column' => 'id', 'strategy' => 'selected_supplier']],
        );
        $invoices = $this->definition(
            'table:invoices',
            TenantDataPolicy::TenantOwned,
            ['primary_key' => ['id'], 'ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id']],
        );

        $first = new TenantDataRegistry(1, [$supplier, $invoices], [TenantDataRegistry::TRANSFER_PROFILE]);
        $reordered = new TenantDataRegistry(1, [$invoices, $supplier], [TenantDataRegistry::TRANSFER_PROFILE]);

        self::assertSame(
            $first->fingerprintFor(TenantDataRegistry::TRANSFER_PROFILE),
            $reordered->fingerprintFor(TenantDataRegistry::TRANSFER_PROFILE),
        );
        self::assertSame(
            ['table:invoices', 'table:supplier'],
            array_map(
                static fn (TenantDataDefinition $definition): string => $definition->key,
                $first->definitionsFor(TenantDataRegistry::TRANSFER_PROFILE),
            ),
        );
    }

    public function testChangedPolicyChangesFingerprint(): void
    {
        $owned = new TenantDataRegistry(
            1,
            [$this->definition('table:documents', TenantDataPolicy::TenantOwned, ['ownership' => ['strategy' => 'supplier_id']])],
            [TenantDataRegistry::TRANSFER_PROFILE],
        );
        $unsupported = new TenantDataRegistry(
            1,
            [$this->definition('table:documents', TenantDataPolicy::Unsupported, ['reason' => 'selector_missing'])],
            [TenantDataRegistry::TRANSFER_PROFILE],
        );

        self::assertNotSame(
            $owned->fingerprintFor(TenantDataRegistry::TRANSFER_PROFILE),
            $unsupported->fingerprintFor(TenantDataRegistry::TRANSFER_PROFILE),
        );
    }

    public function testDraftProfileCannotProduceTransferFingerprint(): void
    {
        $registry = new TenantDataRegistry(
            1,
            [$this->definition('table:supplier', TenantDataPolicy::TenantRoot, [])],
        );

        $this->expectException(IncompleteTenantDataRegistry::class);

        $registry->fingerprintFor(TenantDataRegistry::TRANSFER_PROFILE);
    }

    public function testPersonalSecretAttachmentIsExplicitPolicyNotTenantOwnedData(): void
    {
        $credential = $this->definition(
            'table:epo_signing_credentials',
            TenantDataPolicy::PersonalSecretAttachment,
            ['consent' => 'source_and_target_owner', 'default_selected' => false],
        );

        self::assertSame('personal_secret_attachment', $credential->toArray()['policy']);
        self::assertFalse($credential->toArray()['details']['default_selected']);
    }

    public function testEmptyProfileCannotBeMarkedComplete(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TenantDataRegistry(1, [], [TenantDataRegistry::TRANSFER_PROFILE]);
    }

    /** @param array<string,mixed> $details */
    private function definition(
        string $key,
        TenantDataPolicy $policy,
        array $details,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            $key,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::TRANSFER_PROFILE],
            $details,
        );
    }
}
