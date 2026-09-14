<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupApprovalReceiptDecisionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupApprovalReceiptInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupDataPreflightResult;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PHPUnit\Framework\TestCase;

final class CompanyBackupApprovalReceiptDecisionPlanTest extends TestCase
{
    private const PREFLIGHT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const REGISTRY = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const INSTANCE = '123e4567-e89b-42d3-a456-426614174000';
    private const HASH_A = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const HASH_B = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    public function testCanonicalBoundPlanResolvesAndSerializesSafely(): void
    {
        $inventory = $this->inventory();
        $plan = $this->plan($inventory, [
            ['source_invoice_id' => 4, 'action' => 'invalidate'],
            ['source_invoice_id' => 2, 'action' => 'invalidate'],
        ]);
        self::assertSame([2, 4], array_column($plan->decisions(), 'source_invoice_id'));
        self::assertNull($plan->resolve(2, self::HASH_A, true));
        self::assertNull($plan->resolve(4, self::HASH_A, true));
        self::assertSame(self::HASH_B, $plan->resolve(8, self::HASH_B, false));
        self::assertSame($plan->bindingSha256, $this->plan($inventory, array_reverse($plan->decisions()))->bindingSha256);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $plan->bindingSha256);
        $json = json_encode($plan->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::HASH_A, $json);
        self::assertStringNotContainsString(self::HASH_B, $json);
        self::assertStringNotContainsString('target_owner', $json);
    }

    public function testRejectDecisionStopsRestoreImmediately(): void
    {
        $this->assertSafeError('approval_receipt_collision_rejected', fn () => $this->plan(
            $this->inventory(),
            [
                ['source_invoice_id' => 2, 'action' => 'reject'],
                ['source_invoice_id' => 4, 'action' => 'invalidate'],
            ],
        ));
    }

    public function testRequiresExactCollisionCoverageAndStrictFields(): void
    {
        $inventory = $this->inventory();
        foreach ([
            [[2, 'invalidate']],
            [[2, 'invalidate'], [2, 'invalidate']],
            [[2, 'invalidate'], [8, 'invalidate']],
            [[2, 'invalidate'], [999, 'invalidate']],
            [[2, 'invalidate'], [4, 'preserve']],
        ] as $rows) {
            $decisions = array_map(
                static fn (array $row): array => ['source_invoice_id' => $row[0], 'action' => $row[1]],
                $rows,
            );
            $this->assertAnySafeError(fn () => $this->plan($inventory, $decisions));
        }
        $this->assertAnySafeError(fn () => $this->plan($inventory, [
            ['source_invoice_id' => 2, 'action' => 'invalidate', 'receipt_hash' => self::HASH_A],
            ['source_invoice_id' => 4, 'action' => 'invalidate'],
        ]));
        $this->assertAnySafeError(fn () => CompanyBackupApprovalReceiptDecisionPlan::fromArray([
            'data_preflight_binding_sha256' => self::PREFLIGHT,
            'decisions' => [
                ['source_invoice_id' => 2, 'action' => 'invalidate'],
                ['source_invoice_id' => 4, 'action' => 'invalidate'],
            ],
            'unknown' => true,
        ], $inventory, self::PREFLIGHT, self::REGISTRY, self::INSTANCE, 91));
    }

    public function testDetectsStaleInputContextAndEveryServerBinding(): void
    {
        $inventory = $this->inventory();
        $decisions = [
            ['source_invoice_id' => 2, 'action' => 'invalidate'],
            ['source_invoice_id' => 4, 'action' => 'invalidate'],
        ];
        $this->assertSafeError('approval_receipt_decision_context_mismatch', fn () => CompanyBackupApprovalReceiptDecisionPlan::fromArray([
            'data_preflight_binding_sha256' => str_repeat('f', 64),
            'decisions' => $decisions,
        ], $inventory, self::PREFLIGHT, self::REGISTRY, self::INSTANCE, 91));
        $plan = $this->plan($inventory, $decisions);
        foreach ([
            [$this->inventory(reordered: true), self::PREFLIGHT, self::REGISTRY, self::INSTANCE, 91],
            [$inventory, str_repeat('f', 64), self::REGISTRY, self::INSTANCE, 91],
            [$inventory, self::PREFLIGHT, 'sha256:' . str_repeat('f', 64), self::INSTANCE, 91],
            [$inventory, self::PREFLIGHT, self::REGISTRY, '223e4567-e89b-42d3-a456-426614174000', 91],
            [$inventory, self::PREFLIGHT, self::REGISTRY, self::INSTANCE, 92],
        ] as $context) {
            $this->assertSafeError('approval_receipt_decision_context_mismatch',
                static fn () => $plan->assertContext(...$context));
        }
        $plan->assertContext($inventory, self::PREFLIGHT, self::REGISTRY, self::INSTANCE, 91);
    }

    public function testRejectsUnprefixedRegistryFingerprint(): void
    {
        $inventory = $this->inventory();
        $decisions = [
            ['source_invoice_id' => 2, 'action' => 'invalidate'],
            ['source_invoice_id' => 4, 'action' => 'invalidate'],
        ];
        $this->assertSafeError('approval_receipt_decision_context_mismatch',
            static fn () => CompanyBackupApprovalReceiptDecisionPlan::fromArray([
                'data_preflight_binding_sha256' => self::PREFLIGHT,
                'decisions' => $decisions,
            ], $inventory, self::PREFLIGHT, str_repeat('b', 64), self::INSTANCE, 91));

        $plan = $this->plan($inventory, $decisions);
        $this->assertSafeError('approval_receipt_decision_context_mismatch',
            static fn () => $plan->assertContext(
                $inventory,
                self::PREFLIGHT,
                str_repeat('b', 64),
                self::INSTANCE,
                91,
            ));
    }

    public function testResolveRejectsRedMutationOrNewAndDisappearedCollision(): void
    {
        $plan = $this->plan($this->inventory(), [
            ['source_invoice_id' => 2, 'action' => 'invalidate'],
            ['source_invoice_id' => 4, 'action' => 'invalidate'],
        ]);
        foreach ([
            [2, self::HASH_B, true],
            [2, self::HASH_A, false],
            [8, self::HASH_B, true],
            [999, self::HASH_A, true],
            [2, null, true],
        ] as [$id, $hash, $collision]) {
            $this->assertSafeError('approval_receipt_decision_stale',
                static fn () => $plan->resolve($id, $hash, $collision));
        }
    }

    public function testAcceptsRealRegistryFingerprintCarriedByPreflight(): void
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $registry = TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [new TenantDataDefinition(
                'table:invoices',
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantOwned,
                [$profile],
                ['primary_key' => ['id'], 'ownership' => ['strategy' => 'supplier_id']],
            )],
            [$profile],
        ), $profile);
        $preflight = new CompanyBackupDataPreflightResult(
            new CompanyBackupExternalReferenceInventory([]),
            0,
            0,
            0,
            0,
            0,
            $registry->fingerprint,
            str_repeat('a', 64),
        );
        $inventory = $this->inventory();
        $plan = CompanyBackupApprovalReceiptDecisionPlan::fromArray([
            'data_preflight_binding_sha256' => $preflight->bindingSha256,
            'decisions' => [
                ['source_invoice_id' => 2, 'action' => 'invalidate'],
                ['source_invoice_id' => 4, 'action' => 'invalidate'],
            ],
        ], $inventory, $preflight->bindingSha256, $preflight->targetRegistryFingerprint, self::INSTANCE, 91);

        self::assertSame($registry->fingerprint, $plan->targetRegistryFingerprint);
        $plan->assertContext($inventory, $preflight->bindingSha256, $registry->fingerprint, self::INSTANCE, 91);
    }

    private function inventory(bool $reordered = false): CompanyBackupApprovalReceiptInventory
    {
        return CompanyBackupApprovalReceiptInventory::fromRows([
            ['id' => 4, 'approval_receipt_hash' => self::HASH_A, 'target_collision' => false],
            ['id' => 2, 'approval_receipt_hash' => self::HASH_A, 'target_collision' => false],
            ['id' => 8, 'approval_receipt_hash' => self::HASH_B, 'target_collision' => $reordered],
        ]);
    }

    /** @param list<array<string,mixed>> $decisions */
    private function plan(CompanyBackupApprovalReceiptInventory $inventory, array $decisions): CompanyBackupApprovalReceiptDecisionPlan
    {
        return CompanyBackupApprovalReceiptDecisionPlan::fromArray([
            'data_preflight_binding_sha256' => self::PREFLIGHT,
            'decisions' => $decisions,
        ], $inventory, self::PREFLIGHT, self::REGISTRY, self::INSTANCE, 91);
    }

    private function assertSafeError(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Neplatné rozhodnutí nesmí projít.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertStringNotContainsString(self::HASH_A, $e->getMessage());
            self::assertStringNotContainsString(self::HASH_B, $e->getMessage());
        }
    }

    private function assertAnySafeError(callable $operation): void
    {
        try {
            $operation();
            self::fail('Neplatné rozhodnutí nesmí projít.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertStringNotContainsString(self::HASH_A, $e->getMessage());
            self::assertStringNotContainsString(self::HASH_B, $e->getMessage());
        }
    }
}
