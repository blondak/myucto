<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataPreflightResult;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceCollector;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceRequirement;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionAction;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceOccurrence;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PHPUnit\Framework\TestCase;

final class CompanyBackupReferenceDecisionPlanTest extends TestCase
{
    private const INSTANCE_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const OTHER_INSTANCE_ID = '223e4567-e89b-42d3-a456-426614174000';
    private const RESTORE_ACTOR_ID = 91;

    public function testBuildsCanonicalPlanBoundToPreflightAndTarget(): void
    {
        $registry = $this->registry();
        $preflight = $this->preflight($registry);
        $input = $this->input($preflight);
        $input['decisions'] = array_reverse($input['decisions']);

        $plan = CompanyBackupReferenceDecisionPlan::fromArray(
            $input,
            $preflight,
            $registry,
            self::INSTANCE_ID,
            self::RESTORE_ACTOR_ID,
        );

        self::assertSame($preflight->bindingSha256, $plan->dataPreflightBindingSha256);
        self::assertSame($registry->fingerprint, $plan->targetRegistryFingerprint);
        self::assertSame(self::INSTANCE_ID, $plan->targetInstanceId);
        self::assertSame(self::RESTORE_ACTOR_ID, $plan->restoreActorId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $plan->bindingSha256);
        $ids = array_map(
            static fn ($decision): string => $decision->requirementId,
            $plan->decisions(),
        );
        $ordered = $ids;
        sort($ordered, SORT_STRING);
        self::assertSame($ordered, $ids);

        $global = $this->requirement(
            $preflight->externalReferences,
            CompanyBackupReferenceMapping::GlobalNaturalKey,
        );
        $globalDecision = $plan->decision($global->id);
        self::assertNotNull($globalDecision);
        self::assertSame(
            CompanyBackupReferenceDecisionAction::MapExisting,
            $globalDecision->action,
        );
        self::assertSame(['id' => 1], $globalDecision->targetPrimaryKey);

        $second = CompanyBackupReferenceDecisionPlan::fromArray(
            $this->input($preflight),
            $preflight,
            $registry,
            self::INSTANCE_ID,
            self::RESTORE_ACTOR_ID,
        );
        self::assertSame($plan->toArray(), $second->toArray());
    }

    public function testRejectsStalePreflightBinding(): void
    {
        $registry = $this->registry();
        $preflight = $this->preflight($registry);
        $input = $this->input($preflight);
        $input['data_preflight_binding_sha256'] = str_repeat('f', 64);

        $this->assertPlanError(
            'reference_decision_context_mismatch',
            fn () => CompanyBackupReferenceDecisionPlan::fromArray(
                $input,
                $preflight,
                $registry,
                self::INSTANCE_ID,
                self::RESTORE_ACTOR_ID,
            ),
        );
    }

    public function testRejectsTargetRegistryChangedAfterPreflight(): void
    {
        $registry = $this->registry();
        $preflight = $this->preflight($registry);
        $changedRegistry = $this->registry(
            countryPolicy: TenantDataPolicy::InstanceOwned,
        );

        $this->assertPlanError(
            'reference_decision_context_mismatch',
            fn () => CompanyBackupReferenceDecisionPlan::fromArray(
                $this->input($preflight),
                $preflight,
                $changedRegistry,
                self::INSTANCE_ID,
                self::RESTORE_ACTOR_ID,
            ),
        );
    }

    public function testBindsActorMappingToTargetInstanceAndRestoreActor(): void
    {
        $registry = $this->registry();
        $preflight = $this->preflight($registry);
        $input = $this->input($preflight);
        foreach ($input['decisions'] as &$decision) {
            if ($decision['mapping'] === CompanyBackupReferenceMapping::Actor->value) {
                $decision['action'] =
                    CompanyBackupReferenceDecisionAction::MapExisting->value;
                $decision['target_primary_key'] = ['id' => 17];
            }
        }
        unset($decision);

        $plan = CompanyBackupReferenceDecisionPlan::fromArray(
            $input,
            $preflight,
            $registry,
            self::INSTANCE_ID,
            self::RESTORE_ACTOR_ID,
        );
        $actor = $this->requirement(
            $preflight->externalReferences,
            CompanyBackupReferenceMapping::Actor,
        );
        $actorDecision = $plan->decision($actor->id);
        self::assertNotNull($actorDecision);
        self::assertSame(
            CompanyBackupReferenceDecisionAction::MapExisting,
            $actorDecision->action,
        );
        self::assertSame(['id' => 17], $actorDecision->targetPrimaryKey);

        $otherInstance = CompanyBackupReferenceDecisionPlan::fromArray(
            $input,
            $preflight,
            $registry,
            self::OTHER_INSTANCE_ID,
            self::RESTORE_ACTOR_ID,
        );
        $otherActor = CompanyBackupReferenceDecisionPlan::fromArray(
            $input,
            $preflight,
            $registry,
            self::INSTANCE_ID,
            self::RESTORE_ACTOR_ID + 1,
        );
        self::assertNotSame($plan->bindingSha256, $otherInstance->bindingSha256);
        self::assertNotSame($plan->bindingSha256, $otherActor->bindingSha256);
    }

    public function testRequiresExactlyOneDecisionForEveryRequirement(): void
    {
        $registry = $this->registry();
        $preflight = $this->preflight($registry);
        $input = $this->input($preflight);
        array_pop($input['decisions']);
        $this->assertPlanError(
            'reference_decision_missing',
            fn () => CompanyBackupReferenceDecisionPlan::fromArray(
                $input,
                $preflight,
                $registry,
                self::INSTANCE_ID,
                self::RESTORE_ACTOR_ID,
            ),
        );

        $input = $this->input($preflight);
        $input['decisions'][] = $input['decisions'][0];
        $this->assertPlanError(
            'reference_decision_duplicate',
            fn () => CompanyBackupReferenceDecisionPlan::fromArray(
                $input,
                $preflight,
                $registry,
                self::INSTANCE_ID,
                self::RESTORE_ACTOR_ID,
            ),
        );

        $input = $this->input($preflight);
        $input['decisions'][0]['requirement_id'] = 'sha256:' . str_repeat('e', 64);
        $this->assertPlanError(
            'reference_decision_scope_mismatch',
            fn () => CompanyBackupReferenceDecisionPlan::fromArray(
                $input,
                $preflight,
                $registry,
                self::INSTANCE_ID,
                self::RESTORE_ACTOR_ID,
            ),
        );
    }

    public function testActorFallbackMustBeAllowedByEverySourceOccurrence(): void
    {
        $registry = $this->registry();
        $inventory = $this->inventory(actorFallbacks: ['restore_actor']);
        $preflight = $this->preflight($registry, $inventory);
        $input = $this->input($preflight);
        foreach ($input['decisions'] as &$decision) {
            if ($decision['mapping'] === CompanyBackupReferenceMapping::Actor->value) {
                $decision['action'] = CompanyBackupReferenceDecisionAction::SetNull->value;
            }
        }
        unset($decision);

        $this->assertPlanError(
            'reference_decision_action_forbidden',
            fn () => CompanyBackupReferenceDecisionPlan::fromArray(
                $input,
                $preflight,
                $registry,
                self::INSTANCE_ID,
                self::RESTORE_ACTOR_ID,
            ),
        );
    }

    public function testMappingActionsEnforceTargetKeyAndRegistryPolicy(): void
    {
        $registry = $this->registry();
        $preflight = $this->preflight($registry);
        $input = $this->input($preflight);
        foreach ($input['decisions'] as &$decision) {
            if ($decision['mapping']
                === CompanyBackupReferenceMapping::GlobalNaturalKey->value
            ) {
                $decision['target_primary_key'] = ['wrong_id' => 1];
            }
        }
        unset($decision);
        $this->assertPlanError(
            'reference_decision_target_key_invalid',
            fn () => CompanyBackupReferenceDecisionPlan::fromArray(
                $input,
                $preflight,
                $registry,
                self::INSTANCE_ID,
                self::RESTORE_ACTOR_ID,
            ),
        );

        $input = $this->input($preflight);
        foreach ($input['decisions'] as &$decision) {
            if ($decision['mapping']
                === CompanyBackupReferenceMapping::GlobalNaturalKey->value
            ) {
                $decision['action'] = CompanyBackupReferenceDecisionAction::Omit->value;
                $decision['target_primary_key'] = null;
            }
        }
        unset($decision);
        $this->assertPlanError(
            'reference_decision_action_forbidden',
            fn () => CompanyBackupReferenceDecisionPlan::fromArray(
                $input,
                $preflight,
                $registry,
                self::INSTANCE_ID,
                self::RESTORE_ACTOR_ID,
            ),
        );

        $input = $this->input($preflight);
        foreach ($input['decisions'] as &$decision) {
            if ($decision['mapping']
                === CompanyBackupReferenceMapping::CredentialDecision->value
            ) {
                $decision['action'] =
                    CompanyBackupReferenceDecisionAction::MapExisting->value;
                $decision['target_primary_key'] = ['id' => 44];
            }
        }
        unset($decision);
        $this->assertPlanError(
            'reference_decision_action_forbidden',
            fn () => CompanyBackupReferenceDecisionPlan::fromArray(
                $input,
                $preflight,
                $registry,
                self::INSTANCE_ID,
                self::RESTORE_ACTOR_ID,
            ),
        );

        $wrongRegistry = $this->registry(countryPolicy: TenantDataPolicy::InstanceOwned);
        $wrongPreflight = $this->preflight($wrongRegistry);
        $this->assertPlanError(
            'reference_decision_target_contract_mismatch',
            fn () => CompanyBackupReferenceDecisionPlan::fromArray(
                $this->input($wrongPreflight),
                $wrongPreflight,
                $wrongRegistry,
                self::INSTANCE_ID,
                self::RESTORE_ACTOR_ID,
            ),
        );

        $invalidPrimaryKeyRegistry = $this->registry(userPrimaryKey: []);
        $invalidPrimaryKeyPreflight = $this->preflight(
            $invalidPrimaryKeyRegistry,
        );
        $this->assertPlanError(
            'reference_decision_target_contract_mismatch',
            fn () => CompanyBackupReferenceDecisionPlan::fromArray(
                $this->input($invalidPrimaryKeyPreflight),
                $invalidPrimaryKeyPreflight,
                $invalidPrimaryKeyRegistry,
                self::INSTANCE_ID,
                self::RESTORE_ACTOR_ID,
            ),
        );
    }

    /** @return array<string,mixed> */
    private function input(CompanyBackupDataPreflightResult $preflight): array
    {
        $decisions = [];
        foreach ($preflight->externalReferences->requirements as $requirement) {
            [$action, $targetPrimaryKey] = match ($requirement->mapping) {
                CompanyBackupReferenceMapping::GlobalNaturalKey => [
                    CompanyBackupReferenceDecisionAction::MapExisting,
                    ['id' => 1],
                ],
                CompanyBackupReferenceMapping::Actor => [
                    CompanyBackupReferenceDecisionAction::UseRestoreActor,
                    null,
                ],
                CompanyBackupReferenceMapping::CredentialDecision => [
                    CompanyBackupReferenceDecisionAction::Omit,
                    null,
                ],
                default => throw new \LogicException(
                    'Testovací inventář obsahuje interní mapování.',
                ),
            };
            $decisions[] = [
                'requirement_id' => $requirement->id,
                'mapping' => $requirement->mapping->value,
                'target_registry_key' => $requirement->targetRegistryKey,
                'action' => $action->value,
                'target_primary_key' => $targetPrimaryKey,
            ];
        }
        return [
            'format' => CompanyBackupReferenceDecisionPlan::FORMAT,
            'version' => CompanyBackupReferenceDecisionPlan::VERSION,
            'data_preflight_binding_sha256' => $preflight->bindingSha256,
            'decisions' => $decisions,
        ];
    }

    private function preflight(
        TenantDataRegistrySnapshot $registry,
        ?CompanyBackupExternalReferenceInventory $inventory = null,
    ): CompanyBackupDataPreflightResult {
        return new CompanyBackupDataPreflightResult(
            $inventory ?? $this->inventory(),
            1,
            1,
            1,
            128,
            3,
            $registry->fingerprint,
            str_repeat('a', 64),
        );
    }

    /** @param list<string> $actorFallbacks */
    private function inventory(
        array $actorFallbacks = ['null', 'restore_actor'],
    ): CompanyBackupExternalReferenceInventory {
        $collector = new CompanyBackupExternalReferenceCollector();
        $collector->accept($this->occurrence(
            CompanyBackupReferenceMapping::Actor,
            'table:users',
            ['id'],
            [9],
            'approved_by',
            $actorFallbacks,
            true,
        ));
        $collector->accept($this->occurrence(
            CompanyBackupReferenceMapping::GlobalNaturalKey,
            'table:countries',
            ['iso2'],
            ['CZ'],
            'country_iso2',
        ));
        $collector->accept($this->occurrence(
            CompanyBackupReferenceMapping::CredentialDecision,
            'table:epo_signing_credentials',
            ['id'],
            [77],
            'vault_credential_id',
            nullable: true,
        ));
        return $collector->finish();
    }

    /**
     * @param list<string> $targetColumns
     * @param list<int|string> $values
     * @param list<string> $fallbacks
     */
    private function occurrence(
        CompanyBackupReferenceMapping $mapping,
        string $target,
        array $targetColumns,
        array $values,
        string $column,
        array $fallbacks = [],
        bool $nullable = false,
    ): CompanyBackupReferenceOccurrence {
        $reference = CompanyBackupReferenceSet::fromArray([[
            'columns' => [$column],
            'target' => $target,
            'target_columns' => $targetColumns,
            'mapping' => $mapping->value,
            'constraint' => $mapping
                    === CompanyBackupReferenceMapping::CredentialDecision
                ? CompanyBackupReferenceConstraint::Required->value
                : ($nullable
                    ? CompanyBackupReferenceConstraint::Optional->value
                    : CompanyBackupReferenceConstraint::Required->value),
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => $fallbacks,
        ]], 'table:synthetic_records')->references[0];
        return CompanyBackupReferenceOccurrence::column(
            'table:synthetic_records',
            $reference,
            $values,
        );
    }

    private function requirement(
        CompanyBackupExternalReferenceInventory $inventory,
        CompanyBackupReferenceMapping $mapping,
    ): CompanyBackupExternalReferenceRequirement {
        foreach ($inventory->requirements as $requirement) {
            if ($requirement->mapping === $mapping) {
                return $requirement;
            }
        }
        throw new \LogicException('Testovací požadavek nebyl nalezen.');
    }

    /** @param list<string> $userPrimaryKey */
    private function registry(
        TenantDataPolicy $countryPolicy = TenantDataPolicy::GlobalReference,
        array $userPrimaryKey = ['id'],
    ): TenantDataRegistrySnapshot {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [
                new TenantDataDefinition(
                    'table:countries',
                    TenantDataObjectKind::Table,
                    $countryPolicy,
                    [$profile],
                    [
                        'primary_key' => ['id'],
                        'natural_key' => ['iso2'],
                        'ownership' => ['strategy' => 'global'],
                    ],
                ),
                new TenantDataDefinition(
                    'table:epo_signing_credentials',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::PersonalSecretAttachment,
                    [$profile],
                    [
                        'primary_key' => ['id'],
                        'ownership' => ['strategy' => 'personal'],
                    ],
                ),
                new TenantDataDefinition(
                    'table:users',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::InstanceOwned,
                    [$profile],
                    [
                        'primary_key' => $userPrimaryKey,
                        'ownership' => ['strategy' => 'instance'],
                    ],
                ),
            ],
            [$profile],
        ), $profile);
    }

    /** @param callable():mixed $operation */
    private function assertPlanError(string $errorCode, callable $operation): void
    {
        try {
            $operation();
            self::fail('Neplatný mapovací plán musí být odmítnut.');
        } catch (CompanyBackupReferenceDecisionException $e) {
            self::assertSame($errorCode, $e->errorCode);
        }
    }
}
