<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataPreflightResult;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceCollector;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceRequirement;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionAction;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceOccurrence;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceResolutionException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceTargetResolver;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupReferenceTargetResolverTest extends TestCase
{
    private const INSTANCE_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const RESTORE_ACTOR_ID = 91;

    private PDO $database;

    protected function setUp(): void
    {
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->database->exec(
            'CREATE TABLE countries (id INTEGER PRIMARY KEY, iso2 TEXT NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)',
        );
        $this->database->exec(
            'CREATE TABLE epo_signing_credentials (id INTEGER PRIMARY KEY)',
        );
    }

    public function testResolvesEveryDecisionWithoutWritingTargetData(): void
    {
        $this->database->exec("INSERT INTO countries (id, iso2) VALUES (10, 'CZ')");
        $this->database->exec(
            "INSERT INTO users (id, email) VALUES (91, 'restore@example.test')",
        );
        $registry = $this->registry();
        $preflight = $this->preflight($registry);
        $plan = $this->plan($preflight, $registry);
        $changesBefore = $this->totalChanges();
        $this->database->beginTransaction();

        $resolved = (new CompanyBackupReferenceTargetResolver($this->database))
            ->resolve($plan, $preflight, $registry);

        self::assertTrue($this->database->inTransaction());
        self::assertSame(
            $changesBefore,
            $this->totalChanges(),
        );
        self::assertSame($plan->bindingSha256, $resolved->decisionPlanBindingSha256);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $resolved->bindingSha256);

        $global = $this->requirement(
            $preflight->externalReferences,
            CompanyBackupReferenceMapping::GlobalNaturalKey,
        );
        self::assertSame(
            ['id' => 10],
            $resolved->resolution($global->id)?->targetPrimaryKey,
        );
        $actor = $this->requirement(
            $preflight->externalReferences,
            CompanyBackupReferenceMapping::Actor,
        );
        self::assertSame(
            ['id' => self::RESTORE_ACTOR_ID],
            $resolved->resolution($actor->id)?->targetPrimaryKey,
        );
        $credential = $this->requirement(
            $preflight->externalReferences,
            CompanyBackupReferenceMapping::CredentialDecision,
        );
        self::assertNull($resolved->resolution($credential->id)?->targetPrimaryKey);

        $this->database->rollBack();
    }

    public function testResolvesExplicitExistingActorAndIsDeterministic(): void
    {
        $this->seedRequiredTargets();
        $this->database->exec(
            "INSERT INTO users (id, email) VALUES (17, 'mapped@example.test')",
        );
        $registry = $this->registry();
        $preflight = $this->preflight($registry);
        $plan = $this->plan(
            $preflight,
            $registry,
            CompanyBackupReferenceDecisionAction::MapExisting,
            17,
        );
        $resolver = new CompanyBackupReferenceTargetResolver($this->database);

        $resolved = $resolver->resolve($plan, $preflight, $registry);
        $second = $resolver->resolve($plan, $preflight, $registry);

        $actor = $this->requirement(
            $preflight->externalReferences,
            CompanyBackupReferenceMapping::Actor,
        );
        self::assertSame(['id' => 17], $resolved->resolution($actor->id)?->targetPrimaryKey);
        self::assertSame($resolved->toArray(), $second->toArray());

        $nullPlan = $this->plan(
            $preflight,
            $registry,
            CompanyBackupReferenceDecisionAction::SetNull,
        );
        self::assertNull(
            $resolver->resolve($nullPlan, $preflight, $registry)
                ->resolution($actor->id)?->targetPrimaryKey,
        );
    }

    public function testRejectsMissingAmbiguousAndDifferentGlobalMatch(): void
    {
        $this->database->exec(
            "INSERT INTO users (id, email) VALUES (91, 'restore@example.test')",
        );
        $registry = $this->registry();
        $preflight = $this->preflight($registry);
        $global = $this->requirement(
            $preflight->externalReferences,
            CompanyBackupReferenceMapping::GlobalNaturalKey,
        );
        $resolver = new CompanyBackupReferenceTargetResolver($this->database);

        $this->assertResolutionError(
            'reference_target_missing',
            $global->id,
            fn () => $resolver->resolve(
                $this->plan($preflight, $registry),
                $preflight,
                $registry,
            ),
        );

        $this->database->exec(
            "INSERT INTO countries (id, iso2) VALUES (10, 'CZ'), (11, 'CZ')",
        );
        $this->assertResolutionError(
            'reference_target_ambiguous',
            $global->id,
            fn () => $resolver->resolve(
                $this->plan($preflight, $registry),
                $preflight,
                $registry,
            ),
        );

        $this->database->exec('DELETE FROM countries WHERE id = 11');
        $this->assertResolutionError(
            'reference_target_key_mismatch',
            $global->id,
            fn () => $resolver->resolve(
                $this->plan($preflight, $registry, globalTargetId: 12),
                $preflight,
                $registry,
            ),
        );
    }

    public function testRejectsMissingRestoreActorAndExplicitActor(): void
    {
        $this->database->exec("INSERT INTO countries (id, iso2) VALUES (10, 'CZ')");
        $registry = $this->registry();
        $preflight = $this->preflight($registry);
        $resolver = new CompanyBackupReferenceTargetResolver($this->database);

        $this->assertResolutionError(
            'reference_restore_actor_missing',
            null,
            fn () => $resolver->resolve(
                $this->plan($preflight, $registry),
                $preflight,
                $registry,
            ),
        );

        $this->database->exec(
            "INSERT INTO users (id, email) VALUES (91, 'restore@example.test')",
        );
        $actor = $this->requirement(
            $preflight->externalReferences,
            CompanyBackupReferenceMapping::Actor,
        );
        $this->assertResolutionError(
            'reference_target_missing',
            $actor->id,
            fn () => $resolver->resolve(
                $this->plan(
                    $preflight,
                    $registry,
                    CompanyBackupReferenceDecisionAction::MapExisting,
                    17,
                ),
                $preflight,
                $registry,
            ),
        );
    }

    public function testRejectsChangedContextAndHidesLookupValuesOnQueryFailure(): void
    {
        $this->seedRequiredTargets();
        $registry = $this->registry();
        $preflight = $this->preflight($registry);
        $plan = $this->plan($preflight, $registry);
        $changedPreflight = new CompanyBackupDataPreflightResult(
            $preflight->externalReferences,
            2,
            2,
            2,
            256,
            3,
            $registry->fingerprint,
            str_repeat('a', 64),
        );
        $resolver = new CompanyBackupReferenceTargetResolver($this->database);
        $this->assertResolutionError(
            'reference_resolution_context_mismatch',
            null,
            fn () => $resolver->resolve($plan, $changedPreflight, $registry),
        );

        $this->database->exec('DROP TABLE countries');
        try {
            $resolver->resolve($plan, $preflight, $registry);
            self::fail('Chybný cílový dotaz musí být odmítnut.');
        } catch (CompanyBackupReferenceResolutionException $e) {
            self::assertSame('reference_target_query_failed', $e->errorCode);
            self::assertStringNotContainsString('CZ', $e->getMessage());
            self::assertStringNotContainsString('restore@example.test', $e->getMessage());
        }
    }

    private function seedRequiredTargets(): void
    {
        $this->database->exec("INSERT INTO countries (id, iso2) VALUES (10, 'CZ')");
        $this->database->exec(
            "INSERT INTO users (id, email) VALUES (91, 'restore@example.test')",
        );
    }

    private function totalChanges(): int
    {
        $statement = $this->database->query('SELECT total_changes()');
        if (!$statement instanceof \PDOStatement) {
            throw new \LogicException('SQLite nevrátil počet změn.');
        }
        return (int) $statement->fetchColumn();
    }

    private function plan(
        CompanyBackupDataPreflightResult $preflight,
        TenantDataRegistrySnapshot $registry,
        CompanyBackupReferenceDecisionAction $actorAction =
            CompanyBackupReferenceDecisionAction::UseRestoreActor,
        int $actorTargetId = 17,
        int $globalTargetId = 10,
    ): CompanyBackupReferenceDecisionPlan {
        $decisions = [];
        foreach ($preflight->externalReferences->requirements as $requirement) {
            [$action, $targetPrimaryKey] = match ($requirement->mapping) {
                CompanyBackupReferenceMapping::GlobalNaturalKey => [
                    CompanyBackupReferenceDecisionAction::MapExisting,
                    ['id' => $globalTargetId],
                ],
                CompanyBackupReferenceMapping::Actor => [
                    $actorAction,
                    $actorAction === CompanyBackupReferenceDecisionAction::MapExisting
                        ? ['id' => $actorTargetId]
                        : null,
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
        return CompanyBackupReferenceDecisionPlan::fromArray([
            'format' => CompanyBackupReferenceDecisionPlan::FORMAT,
            'version' => CompanyBackupReferenceDecisionPlan::VERSION,
            'data_preflight_binding_sha256' => $preflight->bindingSha256,
            'decisions' => $decisions,
        ], $preflight, $registry, self::INSTANCE_ID, self::RESTORE_ACTOR_ID);
    }

    private function preflight(
        TenantDataRegistrySnapshot $registry,
    ): CompanyBackupDataPreflightResult {
        return new CompanyBackupDataPreflightResult(
            $this->inventory(),
            1,
            1,
            1,
            128,
            3,
            $registry->fingerprint,
            str_repeat('a', 64),
        );
    }

    private function inventory(): CompanyBackupExternalReferenceInventory
    {
        $collector = new CompanyBackupExternalReferenceCollector();
        $collector->accept($this->occurrence(
            CompanyBackupReferenceMapping::Actor,
            'table:users',
            ['id'],
            [9],
            'approved_by',
            ['null', 'restore_actor'],
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

    private function registry(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [
                new TenantDataDefinition(
                    'table:countries',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::GlobalReference,
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
                        'primary_key' => ['id'],
                        'ownership' => ['strategy' => 'instance'],
                    ],
                ),
            ],
            [$profile],
        ), $profile);
    }

    /** @param callable():mixed $operation */
    private function assertResolutionError(
        string $errorCode,
        ?string $requirementId,
        callable $operation,
    ): void {
        try {
            $operation();
            self::fail('Neplatné cílové mapování musí být odmítnuto.');
        } catch (CompanyBackupReferenceResolutionException $e) {
            self::assertSame($errorCode, $e->errorCode);
            self::assertSame($requirementId, $e->requirementId);
        }
    }
}
