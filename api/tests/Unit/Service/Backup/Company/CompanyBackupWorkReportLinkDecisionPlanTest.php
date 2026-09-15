<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinkDecisionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinkInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupDataPreflightResult;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PHPUnit\Framework\TestCase;

final class CompanyBackupWorkReportLinkDecisionPlanTest extends TestCase
{
    private const PREFLIGHT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const REGISTRY = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const INSTANCE = '123e4567-e89b-42d3-a456-426614174000';
    private const TOKEN_A = 'cccccccccccccccccccccccccccccccccccccccccccccccc';
    private const TOKEN_B = 'dddddddddddddddddddddddddddddddddddddddddddddddd';

    public function testCanonicalBoundPlanResolvesAndSerializesSafely(): void
    {
        $inventory = $this->inventory();
        $plan = $this->plan($inventory, [
            ['source_link_id' => 4, 'action' => 'regenerate'],
            ['source_link_id' => 2, 'action' => 'regenerate'],
        ]);
        self::assertSame([2, 4], array_column($plan->decisions(), 'source_link_id'));
        $replacement2 = $plan->resolve(2, self::TOKEN_A, true);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{48}\z/D', $replacement2);
        self::assertNotSame(self::TOKEN_A, $replacement2);
        $replacement4 = $plan->resolve(4, self::TOKEN_A, true);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{48}\z/D', $replacement4);
        self::assertNotSame(self::TOKEN_A, $replacement4);
        self::assertSame(self::TOKEN_B, $plan->resolve(8, self::TOKEN_B, false));
        self::assertSame($plan->bindingSha256, $this->plan($inventory, array_reverse($plan->decisions()))->bindingSha256);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $plan->bindingSha256);
        $json = json_encode($plan->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::TOKEN_A, $json);
        self::assertStringNotContainsString(self::TOKEN_B, $json);
        self::assertStringNotContainsString('target_owner', $json);
    }

    public function testRejectDecisionStopsRestoreImmediately(): void
    {
        $this->assertSafeError('work_report_link_collision_rejected', fn () => $this->plan(
            $this->inventory(),
            [
                ['source_link_id' => 2, 'action' => 'reject'],
                ['source_link_id' => 4, 'action' => 'regenerate'],
            ],
        ));
    }

    public function testNoCollisionNeedsNoChoiceAndPreservesToken(): void
    {
        $inventory = CompanyBackupWorkReportLinkInventory::fromRows([
            ['id' => 11, 'token' => self::TOKEN_B, 'target_collision' => false],
        ]);
        $plan = $this->plan($inventory, []);
        self::assertSame([], $plan->decisions());
        self::assertSame(self::TOKEN_B, $plan->resolve(11, self::TOKEN_B, false));
        $this->assertSafeError('work_report_link_decision_plan_invalid',
            fn () => $this->plan($inventory, [['source_link_id' => 11, 'action' => 'regenerate']]));
    }

    public function testRequiresExactCollisionCoverageAndStrictFields(): void
    {
        $inventory = $this->inventory();
        foreach ([
            [[2, 'regenerate']],
            [[2, 'regenerate'], [2, 'regenerate']],
            [[2, 'regenerate'], [8, 'regenerate']],
            [[2, 'regenerate'], [999, 'regenerate']],
            [[2, 'regenerate'], [4, 'preserve']],
        ] as $rows) {
            $decisions = array_map(
                static fn (array $row): array => ['source_link_id' => $row[0], 'action' => $row[1]],
                $rows,
            );
            $this->assertAnySafeError(fn () => $this->plan($inventory, $decisions));
        }
        $this->assertAnySafeError(fn () => $this->plan($inventory, [
            ['source_link_id' => 2, 'action' => 'regenerate', 'raw_token' => self::TOKEN_A],
            ['source_link_id' => 4, 'action' => 'regenerate'],
        ]));
        $this->assertAnySafeError(fn () => CompanyBackupWorkReportLinkDecisionPlan::fromArray([
            'data_preflight_binding_sha256' => self::PREFLIGHT,
            'link_inventory_sha256' => $inventory->sha256(),
            'decisions' => [
                ['source_link_id' => 2, 'action' => 'regenerate'],
                ['source_link_id' => 4, 'action' => 'regenerate'],
            ],
            'unknown' => true,
        ], $inventory, self::PREFLIGHT, self::REGISTRY, self::INSTANCE, 91));
    }

    public function testDetectsStaleInputContextAndEveryServerBinding(): void
    {
        $inventory = $this->inventory();
        $decisions = [
            ['source_link_id' => 2, 'action' => 'regenerate'],
            ['source_link_id' => 4, 'action' => 'regenerate'],
        ];
        $this->assertSafeError('work_report_link_decision_context_mismatch', fn () => CompanyBackupWorkReportLinkDecisionPlan::fromArray([
            'data_preflight_binding_sha256' => str_repeat('f', 64),
            'link_inventory_sha256' => $inventory->sha256(),
            'decisions' => $decisions,
        ], $inventory, self::PREFLIGHT, self::REGISTRY, self::INSTANCE, 91));
        $plan = $this->plan($inventory, $decisions);
        foreach ([
            [$this->inventory(extraCollision: true), self::PREFLIGHT, self::REGISTRY, self::INSTANCE, 91],
            [CompanyBackupWorkReportLinkInventory::fromRows([
                ['id' => 4, 'token' => self::TOKEN_A, 'target_collision' => false],
                ['id' => 2, 'token' => self::TOKEN_A, 'target_collision' => false],
                ['id' => 8, 'token' => str_repeat('e', 48), 'target_collision' => false],
            ]), self::PREFLIGHT, self::REGISTRY, self::INSTANCE, 91],
            [$inventory, str_repeat('f', 64), self::REGISTRY, self::INSTANCE, 91],
            [$inventory, self::PREFLIGHT, 'sha256:' . str_repeat('f', 64), self::INSTANCE, 91],
            [$inventory, self::PREFLIGHT, self::REGISTRY, '223e4567-e89b-42d3-a456-426614174000', 91],
            [$inventory, self::PREFLIGHT, self::REGISTRY, self::INSTANCE, 92],
        ] as $context) {
            $this->assertSafeError('work_report_link_decision_context_mismatch',
                static fn () => $plan->assertContext(...$context));
        }
        $plan->assertContext($inventory, self::PREFLIGHT, self::REGISTRY, self::INSTANCE, 91);
    }

    public function testRawDecisionCannotBeReusedForChangedInventoryWithSamePreflight(): void
    {
        $inventory = $this->inventory();
        $raw = [
            'data_preflight_binding_sha256' => self::PREFLIGHT,
            'link_inventory_sha256' => $inventory->sha256(),
            'decisions' => [
                ['source_link_id' => 2, 'action' => 'regenerate'],
                ['source_link_id' => 4, 'action' => 'regenerate'],
            ],
        ];
        foreach ([
            CompanyBackupWorkReportLinkInventory::fromRows([
                ['id' => 4, 'token' => self::TOKEN_A, 'target_collision' => false],
                ['id' => 2, 'token' => self::TOKEN_A, 'target_collision' => false],
                ['id' => 8, 'token' => str_repeat('e', 48), 'target_collision' => false],
            ]),
            $this->inventory(extraCollision: true),
        ] as $changedInventory) {
            $this->assertSafeError('work_report_link_decision_context_mismatch',
                static fn () => CompanyBackupWorkReportLinkDecisionPlan::fromArray(
                    $raw, $changedInventory, self::PREFLIGHT, self::REGISTRY, self::INSTANCE, 91,
                ));
        }
    }

    public function testRejectsUnprefixedRegistryFingerprint(): void
    {
        $inventory = $this->inventory();
        $decisions = [
            ['source_link_id' => 2, 'action' => 'regenerate'],
            ['source_link_id' => 4, 'action' => 'regenerate'],
        ];
        $this->assertSafeError('work_report_link_decision_context_mismatch',
            static fn () => CompanyBackupWorkReportLinkDecisionPlan::fromArray([
                'data_preflight_binding_sha256' => self::PREFLIGHT,
                'link_inventory_sha256' => $inventory->sha256(),
                'decisions' => $decisions,
            ], $inventory, self::PREFLIGHT, str_repeat('b', 64), self::INSTANCE, 91));

        $plan = $this->plan($inventory, $decisions);
        $this->assertSafeError('work_report_link_decision_context_mismatch',
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
            ['source_link_id' => 2, 'action' => 'regenerate'],
            ['source_link_id' => 4, 'action' => 'regenerate'],
        ]);
        foreach ([
            [2, self::TOKEN_B, true],
            [2, self::TOKEN_A, false],
            [8, self::TOKEN_B, true],
            [999, self::TOKEN_A, true],
            [2, null, true],
        ] as [$id, $token, $collision]) {
            $this->assertSafeError('work_report_link_decision_stale',
                static fn () => $plan->resolve($id, $token, $collision));
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
        $plan = CompanyBackupWorkReportLinkDecisionPlan::fromArray([
            'data_preflight_binding_sha256' => $preflight->bindingSha256,
            'link_inventory_sha256' => $inventory->sha256(),
            'decisions' => [
                ['source_link_id' => 2, 'action' => 'regenerate'],
                ['source_link_id' => 4, 'action' => 'regenerate'],
            ],
        ], $inventory, $preflight->bindingSha256, $preflight->targetRegistryFingerprint, self::INSTANCE, 91);

        self::assertSame($registry->fingerprint, $plan->targetRegistryFingerprint);
        $plan->assertContext($inventory, $preflight->bindingSha256, $registry->fingerprint, self::INSTANCE, 91);
    }

    private function inventory(bool $extraCollision = false): CompanyBackupWorkReportLinkInventory
    {
        return CompanyBackupWorkReportLinkInventory::fromRows([
            ['id' => 4, 'token' => self::TOKEN_A, 'target_collision' => false],
            ['id' => 2, 'token' => self::TOKEN_A, 'target_collision' => false],
            ['id' => 8, 'token' => self::TOKEN_B, 'target_collision' => $extraCollision],
        ]);
    }

    /** @param list<array<string,mixed>> $decisions */
    private function plan(CompanyBackupWorkReportLinkInventory $inventory, array $decisions): CompanyBackupWorkReportLinkDecisionPlan
    {
        return CompanyBackupWorkReportLinkDecisionPlan::fromArray([
            'data_preflight_binding_sha256' => self::PREFLIGHT,
            'link_inventory_sha256' => $inventory->sha256(),
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
            self::assertStringNotContainsString(self::TOKEN_A, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN_B, $e->getMessage());
        }
    }

    private function assertAnySafeError(callable $operation): void
    {
        try {
            $operation();
            self::fail('Neplatné rozhodnutí nesmí projít.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertStringNotContainsString(self::TOKEN_A, $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN_B, $e->getMessage());
        }
    }
}
