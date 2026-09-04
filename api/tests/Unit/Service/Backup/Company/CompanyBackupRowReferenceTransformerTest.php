<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataPreflightResult;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceCollector;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionAction;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceDecisionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceOccurrence;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceResolution;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceResolutionPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupRowReferenceTransformer;
use MyInvoice\Service\Backup\Company\CompanyBackupRowTransformException;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentity;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceKey;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlTargetIdentityMap;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupRowReferenceTransformerTest extends TestCase
{
    private const INSTANCE_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const RESTORE_ACTOR_ID = 91;

    private PDO $database;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné pro izolovaný SQL test.');
        }
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }

    public function testMapsCompositeGlobalAndActorReferencesAndAppliesOverride(): void
    {
        $map = $this->identityMap();
        $transformer = new CompanyBackupRowReferenceTransformer(
            $map,
            $this->resolutionPlan(),
        );

        $restored = $transformer->transform(
            $this->projection(),
            [
                'id' => 501,
                'supplier_id' => 7,
                'employee_id' => 31,
                'country_id' => 1,
                'approved_by' => 9,
                'is_active' => 1,
            ],
        );

        self::assertSame([
            'id' => 501,
            'supplier_id' => 71,
            'employee_id' => 401,
            'country_id' => 10,
            'approved_by' => self::RESTORE_ACTOR_ID,
            'is_active' => 0,
        ], $restored);
        $map->close();
    }

    public function testAppliesExplicitNullActorDecision(): void
    {
        $map = $this->identityMap();
        $transformer = new CompanyBackupRowReferenceTransformer(
            $map,
            $this->resolutionPlan(CompanyBackupReferenceDecisionAction::SetNull),
        );

        $restored = $transformer->transform(
            $this->projection(),
            [
                'id' => 501,
                'supplier_id' => 7,
                'employee_id' => 31,
                'country_id' => 1,
                'approved_by' => 9,
                'is_active' => 1,
            ],
        );

        self::assertNull($restored['approved_by']);
        $map->close();
    }

    public function testRejectsUnresolvedInternalAndActorReferencesWithoutValues(): void
    {
        $map = $this->identityMap(includeEmployee: false);
        $transformer = new CompanyBackupRowReferenceTransformer(
            $map,
            $this->resolutionPlan(),
        );
        $source = [
            'id' => 501,
            'supplier_id' => 7,
            'employee_id' => 31,
            'country_id' => 1,
            'approved_by' => 9,
            'is_active' => 1,
        ];

        $this->assertTransformError(
            'row_reference_unresolved',
            'supplier_id',
            fn () => $transformer->transform($this->projection(), $source),
        );
        self::assertSame(31, $source['employee_id']);
        $map->close();

        $map = $this->identityMap();
        $transformer = new CompanyBackupRowReferenceTransformer(
            $map,
            $this->resolutionPlan(),
        );
        $source['approved_by'] = 99;
        $this->assertTransformError(
            'row_reference_decision_missing',
            'approved_by',
            fn () => $transformer->transform($this->projection(), $source),
        );
        $map->close();
    }

    public function testRejectsGlobalMapThatDisagreesWithResolvedDecision(): void
    {
        $map = $this->identityMap(globalTargetId: 11);
        $transformer = new CompanyBackupRowReferenceTransformer(
            $map,
            $this->resolutionPlan(),
        );

        $this->assertTransformError(
            'row_reference_decision_invalid',
            'country_id',
            fn () => $transformer->transform(
                $this->projection(),
                [
                    'id' => 501,
                    'supplier_id' => 7,
                    'employee_id' => 31,
                    'country_id' => 1,
                    'approved_by' => 9,
                    'is_active' => 1,
                ],
            ),
        );
        $map->close();
    }

    private function projection(): CompanyBackupTableProjection
    {
        return CompanyBackupTableProjection::fromDefinition(
            new TenantDataDefinition(
                'table:synthetic_records',
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantOwned,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
                [
                    'primary_key' => ['id'],
                    'ownership' => [
                        'strategy' => 'supplier_id',
                        'column' => 'supplier_id',
                    ],
                    'secrets' => [],
                    'company_backup' => [
                        'data_columns' => [
                            'id',
                            'supplier_id',
                            'employee_id',
                            'country_id',
                            'approved_by',
                            'is_active',
                        ],
                        'embedded_references' => [],
                        'generated_columns' => [],
                        'omit_columns' => [],
                        'references' => [
                            $this->reference(
                                ['approved_by'],
                                'table:users',
                                ['id'],
                                CompanyBackupReferenceMapping::Actor,
                                ['approved_by'],
                                ['null', 'restore_actor'],
                            ),
                            $this->reference(
                                ['country_id'],
                                'table:countries',
                                ['id'],
                                CompanyBackupReferenceMapping::GlobalNaturalKey,
                            ),
                            $this->reference(
                                ['supplier_id', 'employee_id'],
                                'table:payroll_employees',
                                ['supplier_id', 'id'],
                                CompanyBackupReferenceMapping::TenantId,
                            ),
                            $this->reference(
                                ['supplier_id'],
                                'table:supplier',
                                ['id'],
                                CompanyBackupReferenceMapping::TenantId,
                            ),
                        ],
                        'restore_overrides' => [
                            'is_active' => [
                                'value' => 0,
                                'reason' => 'disable_after_restore',
                            ],
                        ],
                    ],
                ],
            ),
        );
    }

    /**
     * @param list<string> $columns
     * @param list<string> $targetColumns
     * @param list<string> $nullableColumns
     * @param list<string> $fallbacks
     * @return array<string,mixed>
     */
    private function reference(
        array $columns,
        string $target,
        array $targetColumns,
        CompanyBackupReferenceMapping $mapping,
        array $nullableColumns = [],
        array $fallbacks = [],
    ): array {
        return [
            'columns' => $columns,
            'target' => $target,
            'target_columns' => $targetColumns,
            'mapping' => $mapping->value,
            'constraint' => $mapping === CompanyBackupReferenceMapping::Actor
                ? CompanyBackupReferenceConstraint::Optional->value
                : CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => $nullableColumns,
            'fallbacks' => $fallbacks,
        ];
    }

    private function identityMap(
        bool $includeEmployee = true,
        int $globalTargetId = 10,
    ): CompanyBackupSqlTargetIdentityMap {
        $map = new CompanyBackupSqlTargetIdentityMap($this->database);
        $map->add(
            $this->identity('table:supplier', TenantDataPolicy::TenantRoot, 7),
            $this->identity('table:supplier', TenantDataPolicy::TenantRoot, 71),
        );
        if ($includeEmployee) {
            $map->add(
                $this->identity(
                    'table:payroll_employees',
                    TenantDataPolicy::TenantOwned,
                    31,
                    7,
                ),
                $this->identity(
                    'table:payroll_employees',
                    TenantDataPolicy::TenantOwned,
                    401,
                    71,
                ),
            );
        }
        $map->add(
            $this->identity(
                'table:countries',
                TenantDataPolicy::GlobalReference,
                1,
                naturalKey: ['iso2' => 'CZ'],
            ),
            $this->identity(
                'table:countries',
                TenantDataPolicy::GlobalReference,
                $globalTargetId,
                naturalKey: ['iso2' => 'CZ'],
            ),
        );
        $map->seal();
        return $map;
    }

    /** @param array<string,int|string>|null $naturalKey */
    private function identity(
        string $registryKey,
        TenantDataPolicy $policy,
        int $id,
        ?int $supplierId = null,
        ?array $naturalKey = null,
    ): CompanyBackupSourceIdentity {
        return new CompanyBackupSourceIdentity(
            $policy,
            CompanyBackupSourceKey::fromValues($registryKey, ['id' => $id]),
            $supplierId === null
                ? null
                : CompanyBackupSourceKey::fromValues(
                    $registryKey,
                    ['supplier_id' => $supplierId, 'id' => $id],
                ),
            $naturalKey === null
                ? null
                : CompanyBackupSourceKey::fromValues($registryKey, $naturalKey),
            [],
        );
    }

    private function resolutionPlan(
        CompanyBackupReferenceDecisionAction $actorAction =
            CompanyBackupReferenceDecisionAction::UseRestoreActor,
    ): CompanyBackupReferenceResolutionPlan {
        $registry = $this->registry();
        $collector = new CompanyBackupExternalReferenceCollector();
        $collector->accept($this->externalOccurrence(
            CompanyBackupReferenceMapping::Actor,
            'table:users',
            ['id' => 9],
            ['null', 'restore_actor'],
        ));
        $collector->accept($this->externalOccurrence(
            CompanyBackupReferenceMapping::GlobalNaturalKey,
            'table:countries',
            ['iso2' => 'CZ'],
        ));
        $inventory = $collector->finish();
        $preflight = new CompanyBackupDataPreflightResult(
            $inventory,
            1,
            1,
            1,
            128,
            2,
            $registry->fingerprint,
            str_repeat('a', 64),
        );
        $decisions = [];
        foreach ($inventory->requirements as $requirement) {
            [$action, $target] = match ($requirement->mapping) {
                CompanyBackupReferenceMapping::Actor => [$actorAction, null],
                CompanyBackupReferenceMapping::GlobalNaturalKey => [
                    CompanyBackupReferenceDecisionAction::MapExisting,
                    ['id' => 10],
                ],
                default => throw new \LogicException('Neočekávaný požadavek.'),
            };
            $decisions[] = [
                'requirement_id' => $requirement->id,
                'mapping' => $requirement->mapping->value,
                'target_registry_key' => $requirement->targetRegistryKey,
                'action' => $action->value,
                'target_primary_key' => $target,
            ];
        }
        $decisionPlan = CompanyBackupReferenceDecisionPlan::fromArray([
            'format' => CompanyBackupReferenceDecisionPlan::FORMAT,
            'version' => CompanyBackupReferenceDecisionPlan::VERSION,
            'data_preflight_binding_sha256' => $preflight->bindingSha256,
            'decisions' => $decisions,
        ], $preflight, $registry, self::INSTANCE_ID, self::RESTORE_ACTOR_ID);
        $resolutions = [];
        foreach ($decisionPlan->decisions() as $decision) {
            $target = match ($decision->action) {
                CompanyBackupReferenceDecisionAction::MapExisting =>
                    $decision->targetPrimaryKey,
                CompanyBackupReferenceDecisionAction::UseRestoreActor =>
                    ['id' => self::RESTORE_ACTOR_ID],
                default => null,
            };
            $resolutions[] = new CompanyBackupReferenceResolution(
                $decision,
                $target,
            );
        }
        return CompanyBackupReferenceResolutionPlan::fromResolutions(
            $decisionPlan,
            $resolutions,
        );
    }

    /**
     * @param array<string,int|string> $sourceKey
     * @param list<string> $fallbacks
     */
    private function externalOccurrence(
        CompanyBackupReferenceMapping $mapping,
        string $target,
        array $sourceKey,
        array $fallbacks = [],
    ): CompanyBackupReferenceOccurrence {
        $column = $mapping === CompanyBackupReferenceMapping::Actor
            ? 'approved_by'
            : 'country_id';
        $reference = CompanyBackupReferenceSet::fromArray([[
            'columns' => [$column],
            'target' => $target,
            'target_columns' => array_keys($sourceKey),
            'mapping' => $mapping->value,
            'constraint' => $mapping === CompanyBackupReferenceMapping::Actor
                ? CompanyBackupReferenceConstraint::Optional->value
                : CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => $mapping === CompanyBackupReferenceMapping::Actor
                ? [$column]
                : [],
            'fallbacks' => $fallbacks,
        ]], 'table:synthetic_records')->references[0];
        return CompanyBackupReferenceOccurrence::column(
            'table:synthetic_records',
            $reference,
            array_values($sourceKey),
        );
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
    private function assertTransformError(
        string $errorCode,
        string $column,
        callable $operation,
    ): void {
        try {
            $operation();
            self::fail('Nevyřešená reference musí zastavit transformaci.');
        } catch (CompanyBackupRowTransformException $e) {
            self::assertSame($errorCode, $e->errorCode);
            self::assertSame('table:synthetic_records', $e->registryKey);
            self::assertSame($column, $e->column);
            self::assertStringNotContainsString('31', $e->getMessage());
            self::assertStringNotContainsString('99', $e->getMessage());
        }
    }
}
