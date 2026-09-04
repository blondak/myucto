<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PHPUnit\Framework\TestCase;

final class CompanyBackupReferenceSetTest extends TestCase
{
    public function testAcceptsRequiredTenantFkAndNullableActorMapping(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [
                $this->actorReference(),
                $this->supplierReference(),
            ],
            'table:accounting_periods',
        );

        $references->assertProjectionColumns(['id', 'supplier_id', 'approved_by']);
        $references->assertRegistryTargets($this->targetRegistry());
        $references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['approved_by'],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));

        self::assertCount(2, $references->references);
        self::assertSame(
            CompanyBackupReferenceMapping::Actor,
            $references->references[0]->mapping,
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Required,
            $references->references[1]->constraint,
        );
    }

    public function testRejectsReferenceLikeColumnWithoutMapping(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [$this->supplierReference()],
            'table:synthetic_records',
        );

        try {
            $references->assertProjectionColumns(['id', 'supplier_id', 'source_id']);
            self::fail('Každý exportovaný *_id musí mít explicitní remap politiku.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_column_unclassified', $e->errorCode);
            self::assertSame('source_id', $e->column);
        }
    }

    public function testRejectsUnknownRuntimeForeignKey(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [$this->supplierReference()],
            'table:synthetic_records',
        );
        $references->assertProjectionColumns(['id', 'supplier_id', 'legacy_owner']);

        try {
            $references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
                [],
                [
                    new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
                    new CompanyBackupForeignKey(['legacy_owner'], 'users', ['id']),
                ],
            ));
            self::fail('Skutečný FK bez klasifikace nesmí projít coverage branou.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_foreign_key_unclassified', $e->errorCode);
            self::assertSame('legacy_owner', $e->column);
        }
    }

    public function testRejectsMissingRequiredConstraintAndNullabilityDrift(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [$this->supplierReference()],
            'table:synthetic_records',
        );

        try {
            $references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema([], []));
            self::fail('Povinný fyzický FK nesmí ze schématu tiše zmizet.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_constraint_missing', $e->errorCode);
            self::assertSame('supplier_id', $e->column);
        }

        try {
            $references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
                ['supplier_id'],
                [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
            ));
            self::fail('Změna nullability reference musí změnit její obnovovací politiku.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_nullability_mismatch', $e->errorCode);
            self::assertSame('supplier_id', $e->column);
        }
    }

    public function testRejectsActorMappingToTenantTable(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [$this->actorReference()],
            'table:accounting_periods',
        );
        $registry = new TenantDataRegistry(
            1,
            [$this->definition('users', TenantDataPolicy::TenantOwned)],
        );

        try {
            $references->assertRegistryTargets($registry);
            self::fail('Actor se smí mapovat jen na instanční uživatelský účet.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_target_invalid', $e->errorCode);
            self::assertSame('approved_by', $e->column);
        }
    }

    public function testAllowsSharedTenantColumnAcrossNaturalKeyReferences(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [
                $this->accountCodeReference('credit_account_code'),
                $this->accountCodeReference('debit_account_code'),
                [
                    ...$this->supplierReference(),
                    'nullable_columns' => ['supplier_id'],
                ],
            ],
            'table:posting_rules',
        );
        $registry = new TenantDataRegistry(
            1,
            [
                $this->definition(
                    'chart_of_accounts',
                    TenantDataPolicy::TenantOwned,
                    ['natural_key' => ['supplier_id', 'account_code']],
                ),
                $this->definition('supplier', TenantDataPolicy::TenantRoot),
            ],
        );

        $references->assertProjectionColumns([
            'id',
            'supplier_id',
            'debit_account_code',
            'credit_account_code',
        ]);
        $references->assertRegistryTargets($registry);
        $references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['supplier_id', 'debit_account_code', 'credit_account_code'],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));

        self::assertCount(3, $references->references);
        self::assertSame(
            CompanyBackupReferenceMapping::TenantNaturalKey,
            $references->references[0]->mapping,
        );
    }

    public function testAllowsNaturalKeyToShareRemappedTenantCoordinate(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [
                [
                    'columns' => [
                        'supplier_id',
                        'employee_id',
                        'period_start',
                        'selected_employer_reference',
                    ],
                    'target' => 'table:other_employer_bases',
                    'target_columns' => [
                        'supplier_id',
                        'employee_id',
                        'period_start',
                        'employer_reference',
                    ],
                    'mapping' => CompanyBackupReferenceMapping::TenantNaturalKey->value,
                    'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                    'nullable_columns' => ['selected_employer_reference'],
                    'fallbacks' => [],
                ],
                [
                    'columns' => ['supplier_id', 'employee_id'],
                    'target' => 'table:payroll_employees',
                    'target_columns' => ['supplier_id', 'id'],
                    'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                    'constraint' => CompanyBackupReferenceConstraint::Required->value,
                    'nullable_columns' => [],
                    'fallbacks' => [],
                ],
            ],
            'table:health_month_evidence',
        );
        $registry = new TenantDataRegistry(1, [
            $this->definition(
                'other_employer_bases',
                TenantDataPolicy::TenantOwned,
                ['natural_key' => [
                    'supplier_id',
                    'employee_id',
                    'period_start',
                    'employer_reference',
                ]],
            ),
            $this->definition(
                'payroll_employees',
                TenantDataPolicy::TenantOwned,
            ),
        ]);

        $references->assertProjectionColumns([
            'id',
            'supplier_id',
            'employee_id',
            'period_start',
            'selected_employer_reference',
        ]);
        $references->assertRegistryTargets($registry);
        $references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['selected_employer_reference'],
            [new CompanyBackupForeignKey(
                ['supplier_id', 'employee_id'],
                'payroll_employees',
                ['supplier_id', 'id'],
            )],
        ));

        self::assertCount(2, $references->references);
    }

    public function testRejectsOverlappingBusinessReferenceColumns(): void
    {
        try {
            CompanyBackupReferenceSet::fromArray(
                [
                    [
                        'columns' => ['related_id', 'code'],
                        'target' => 'table:chart_of_accounts',
                        'target_columns' => ['supplier_id', 'account_code'],
                        'mapping' => CompanyBackupReferenceMapping::TenantNaturalKey->value,
                        'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                        'nullable_columns' => [],
                        'fallbacks' => [],
                    ],
                    [
                        'columns' => ['related_id'],
                        'target' => 'table:supplier',
                        'target_columns' => ['id'],
                        'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                        'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                        'nullable_columns' => [],
                        'fallbacks' => [],
                    ],
                ],
                'table:synthetic_records',
            );
            self::fail('Sdílet mezi remapy se smí jen explicitní tenantový kontext.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_duplicate', $e->errorCode);
            self::assertSame('related_id', $e->column);
        }
    }

    public function testAcceptsConditionallyDisjointPolymorphicSoftReferences(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [
                $this->sourceReference('invoice', 'invoices'),
                $this->sourceReference('purchase_invoice', 'purchase_invoices'),
                $this->supplierReference(),
            ],
            'table:journal_entries',
        );
        $registry = new TenantDataRegistry(1, [
            $this->definition('invoices', TenantDataPolicy::TenantOwned),
            $this->definition('purchase_invoices', TenantDataPolicy::TenantOwned),
            $this->definition('supplier', TenantDataPolicy::TenantRoot),
        ]);

        $references->assertProjectionColumns([
            'id',
            'supplier_id',
            'source_type',
            'source_id',
        ]);
        $references->assertRegistryTargets($registry);
        $references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['source_id'],
            [new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id'])],
        ));

        self::assertSame(
            [
                'source_id->invoices:id?source_type=invoice',
                'source_id->purchase_invoices:id?source_type=purchase_invoice',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $references->references,
            ),
        );
    }

    public function testRejectsAmbiguousConditionalReferenceClaims(): void
    {
        try {
            CompanyBackupReferenceSet::fromArray(
                [
                    $this->sourceReference('invoice', 'invoices'),
                    $this->sourceReference('invoice', 'purchase_invoices'),
                ],
                'table:journal_entries',
            );
            self::fail('Jeden diskriminátor nesmí vybrat dva cíle source_id.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_duplicate', $e->errorCode);
            self::assertSame('source_id', $e->column);
        }
    }

    public function testRejectsConditionalReferenceWithUnexportedDiscriminator(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [$this->sourceReference('invoice', 'invoices')],
            'table:journal_entries',
        );

        try {
            $references->assertProjectionColumns(['id', 'source_id']);
            self::fail('Diskriminátor reference musí být součástí exportované projekce.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame(
                'data_reference_condition_source_not_exported',
                $e->errorCode,
            );
            self::assertSame('source_type', $e->column);
        }
    }

    public function testRejectsConditionalReferenceThatPretendsToBePhysicalFk(): void
    {
        try {
            CompanyBackupReferenceSet::fromArray(
                [[
                    ...$this->sourceReference('invoice', 'invoices'),
                    'constraint' => CompanyBackupReferenceConstraint::Required->value,
                ]],
                'table:journal_entries',
            );
            self::fail('Podmíněná polymorfní reference nesmí předstírat fyzický FK.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_metadata_invalid', $e->errorCode);
        }
    }

    public function testRejectsConditionalClaimsWithDifferentDiscriminatorColumns(): void
    {
        try {
            CompanyBackupReferenceSet::fromArray(
                [
                    $this->sourceReference('invoice', 'invoices'),
                    [
                        ...$this->sourceReference('purchase_invoice', 'purchase_invoices'),
                        'condition' => [
                            'column' => 'source_kind',
                            'equals' => 'purchase_invoice',
                        ],
                    ],
                ],
                'table:journal_entries',
            );
            self::fail('Různé diskriminátory se mohou překrývat a nesmějí sdílet source_id.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_duplicate', $e->errorCode);
            self::assertSame('source_id', $e->column);
        }
    }

    public function testRejectsReferenceColumnAsItsOwnDiscriminator(): void
    {
        try {
            CompanyBackupReferenceSet::fromArray(
                [[
                    ...$this->sourceReference('invoice', 'invoices'),
                    'condition' => [
                        'column' => 'source_id',
                        'equals' => 'invoice',
                    ],
                ]],
                'table:journal_entries',
            );
            self::fail('Reference se nesmí sama používat jako typový diskriminátor.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_metadata_invalid', $e->errorCode);
            self::assertSame('source_id', $e->column);
        }
    }

    public function testAcceptsTenantScopedCompositeIdForeignKey(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [
                [
                    'columns' => ['supplier_id', 'period_id'],
                    'target' => 'table:accounting_periods',
                    'target_columns' => ['supplier_id', 'id'],
                    'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                    'constraint' => CompanyBackupReferenceConstraint::Required->value,
                    'nullable_columns' => [],
                    'fallbacks' => [],
                ],
                $this->supplierReference(),
            ],
            'table:accounting_closing_steps',
        );
        $registry = new TenantDataRegistry(1, [
            $this->definition('accounting_periods', TenantDataPolicy::TenantOwned),
            $this->definition('supplier', TenantDataPolicy::TenantRoot),
        ]);

        $references->assertProjectionColumns(['id', 'supplier_id', 'period_id']);
        $references->assertRegistryTargets($registry);
        $references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            [],
            [
                new CompanyBackupForeignKey(
                    ['supplier_id', 'period_id'],
                    'accounting_periods',
                    ['supplier_id', 'id'],
                ),
                new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
            ],
        ));

        self::assertSame(
            [
                'supplier_id,period_id->accounting_periods:supplier_id,id',
                'supplier_id->supplier:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $references->references,
            ),
        );
    }

    public function testAcceptsExplicitTenantReferenceKeyForeignKey(): void
    {
        $reference = [
            'columns' => ['supplier_id', 'revision_id', 'employee_id'],
            'target' => 'table:payroll_run_persons',
            'target_columns' => ['supplier_id', 'revision_id', 'employee_id'],
            'mapping' => CompanyBackupReferenceMapping::TenantReferenceKey->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ];
        $references = CompanyBackupReferenceSet::fromArray(
            [$reference, $this->supplierReference()],
            'table:payroll_statutory_person_results',
        );
        $registry = new TenantDataRegistry(1, [
            $this->definition(
                'payroll_run_persons',
                TenantDataPolicy::TenantOwned,
                ['reference_keys' => [[
                    'supplier_id',
                    'revision_id',
                    'employee_id',
                ]]],
            ),
            $this->definition('supplier', TenantDataPolicy::TenantRoot),
        ]);

        $references->assertProjectionColumns([
            'id',
            'supplier_id',
            'revision_id',
            'employee_id',
        ]);
        $references->assertRegistryTargets($registry);
        $references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            [],
            [
                new CompanyBackupForeignKey(
                    ['supplier_id', 'revision_id', 'employee_id'],
                    'payroll_run_persons',
                    ['supplier_id', 'revision_id', 'employee_id'],
                ),
                new CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']),
            ],
        ));
        self::assertSame(
            CompanyBackupReferenceMapping::TenantReferenceKey,
            $references->references[0]->mapping,
        );

        try {
            $references->assertRegistryTargets(new TenantDataRegistry(1, [
                $this->definition(
                    'payroll_run_persons',
                    TenantDataPolicy::TenantOwned,
                ),
                $this->definition('supplier', TenantDataPolicy::TenantRoot),
            ]));
            self::fail('Složený FK musí mířit na explicitní cílový reference key.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_target_invalid', $e->errorCode);
            self::assertSame('supplier_id', $e->column);
        }
    }

    public function testAcceptsNullableBusinessPartOfTenantReferenceKey(): void
    {
        $reference = [
            'columns' => ['supplier_id', 'agreement_id', 'employee_id'],
            'target' => 'table:payroll_deduction_agreements',
            'target_columns' => ['supplier_id', 'id', 'employee_id'],
            'mapping' => CompanyBackupReferenceMapping::TenantReferenceKey->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => ['agreement_id'],
            'fallbacks' => [],
        ];
        $references = CompanyBackupReferenceSet::fromArray(
            [
                $reference,
                [
                    'columns' => ['supplier_id', 'employee_id'],
                    'target' => 'table:payroll_employees',
                    'target_columns' => ['supplier_id', 'id'],
                    'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                    'constraint' => CompanyBackupReferenceConstraint::Required->value,
                    'nullable_columns' => [],
                    'fallbacks' => [],
                ],
            ],
            'table:payroll_deduction_ledger',
        );
        $registry = new TenantDataRegistry(1, [
            $this->definition(
                'payroll_deduction_agreements',
                TenantDataPolicy::TenantOwned,
                ['reference_keys' => [[
                    'supplier_id',
                    'id',
                    'employee_id',
                ]]],
            ),
            $this->definition(
                'payroll_employees',
                TenantDataPolicy::TenantOwned,
            ),
        ]);

        $references->assertProjectionColumns([
            'id',
            'supplier_id',
            'agreement_id',
            'employee_id',
        ]);
        $references->assertRegistryTargets($registry);
        $references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['agreement_id'],
            [
                new CompanyBackupForeignKey(
                    ['supplier_id', 'agreement_id', 'employee_id'],
                    'payroll_deduction_agreements',
                    ['supplier_id', 'id', 'employee_id'],
                ),
                new CompanyBackupForeignKey(
                    ['supplier_id', 'employee_id'],
                    'payroll_employees',
                    ['supplier_id', 'id'],
                ),
            ],
        ));

        self::assertSame(
            ['agreement_id'],
            $references->references[0]->nullableColumns,
        );
    }

    public function testRejectsNullableTenantScopeInReferenceKey(): void
    {
        $this->expectException(CompanyBackupDataSourceException::class);
        CompanyBackupReferenceSet::fromArray(
            [[
                'columns' => ['supplier_id', 'agreement_id', 'employee_id'],
                'target' => 'table:payroll_deduction_agreements',
                'target_columns' => ['supplier_id', 'id', 'employee_id'],
                'mapping' => CompanyBackupReferenceMapping::TenantReferenceKey->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => ['supplier_id', 'agreement_id'],
                'fallbacks' => [],
            ]],
            'table:payroll_deduction_ledger',
        );
    }

    public function testAcceptsGlobalIdResolvedThroughTargetNaturalKey(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [[
                'columns' => ['country_id'],
                'target' => 'table:countries',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::GlobalNaturalKey->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ]],
            'table:clients',
        );
        $registry = new TenantDataRegistry(1, [
            $this->definition(
                'countries',
                TenantDataPolicy::GlobalReference,
                ['natural_key' => ['iso2']],
            ),
        ]);

        $references->assertProjectionColumns(['id', 'country_id']);
        $references->assertRegistryTargets($registry);
        $references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            [],
            [new CompanyBackupForeignKey(['country_id'], 'countries', ['id'])],
        ));

        self::assertSame(
            CompanyBackupReferenceMapping::GlobalNaturalKey,
            $references->references[0]->mapping,
        );
    }

    public function testRejectsGlobalIdTargetWithoutNaturalKey(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [[
                'columns' => ['country_id'],
                'target' => 'table:countries',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::GlobalNaturalKey->value,
                'constraint' => CompanyBackupReferenceConstraint::Required->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ]],
            'table:clients',
        );
        $registry = new TenantDataRegistry(1, [
            $this->definition('countries', TenantDataPolicy::GlobalReference),
        ]);

        try {
            $references->assertRegistryTargets($registry);
            self::fail('Globální ID musí mít natural key pro cílové rozlišení.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_target_invalid', $e->errorCode);
            self::assertSame('country_id', $e->column);
        }
    }

    public function testCredentialDecisionTargetsOnlyPersonalSecretAttachment(): void
    {
        $reference = [
            'columns' => ['vault_credential_id'],
            'target' => 'table:epo_signing_credentials',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::CredentialDecision->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => ['vault_credential_id'],
            'fallbacks' => [],
        ];
        $references = CompanyBackupReferenceSet::fromArray(
            [$reference],
            'table:signing_credentials',
        );
        $references->assertProjectionColumns(['id', 'vault_credential_id']);
        $references->assertRegistryTargets(new TenantDataRegistry(1, [
            $this->definition(
                'epo_signing_credentials',
                TenantDataPolicy::PersonalSecretAttachment,
            ),
        ]));
        $references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema(
            ['vault_credential_id'],
            [new CompanyBackupForeignKey(
                ['vault_credential_id'],
                'epo_signing_credentials',
                ['id'],
            )],
        ));

        self::assertSame(
            CompanyBackupReferenceMapping::CredentialDecision,
            $references->references[0]->mapping,
        );

        try {
            $references->assertRegistryTargets(new TenantDataRegistry(1, [
                $this->definition(
                    'epo_signing_credentials',
                    TenantDataPolicy::InstanceOwned,
                ),
            ]));
            self::fail('Credential decision nesmí mířit na obecná instanční data.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_target_invalid', $e->errorCode);
        }
    }

    public function testAcceptsExplicitZeroSentinelSoftReference(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [[
                'columns' => ['register_id'],
                'target' => 'table:cash_registers',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantIdOrZero->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ]],
            'table:accounting_document_series',
        );
        $registry = new TenantDataRegistry(1, [
            $this->definition('cash_registers', TenantDataPolicy::TenantOwned),
        ]);

        $references->assertProjectionColumns(['id', 'register_id']);
        $references->assertRegistryTargets($registry);
        $references->assertRuntimeSchema(new CompanyBackupTableReferenceSchema([], []));

        self::assertSame(
            CompanyBackupReferenceMapping::TenantIdOrZero,
            $references->references[0]->mapping,
        );
    }

    public function testRejectsZeroSentinelThatPretendsToBeNullableOrPhysicalFk(): void
    {
        foreach (
            [
                ['constraint' => CompanyBackupReferenceConstraint::Required->value],
                ['nullable_columns' => ['register_id']],
            ] as $change
        ) {
            try {
                CompanyBackupReferenceSet::fromArray(
                    [[
                        'columns' => ['register_id'],
                        'target' => 'table:cash_registers',
                        'target_columns' => ['id'],
                        'mapping' => CompanyBackupReferenceMapping::TenantIdOrZero->value,
                        'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                        'nullable_columns' => [],
                        'fallbacks' => [],
                        ...$change,
                    ]],
                    'table:accounting_document_series',
                );
                self::fail('Nulový sentinel musí zůstat ne-nullable soft referencí.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_reference_metadata_invalid', $e->errorCode);
            }
        }
    }

    public function testVisitsOnlyActiveNonNullReferenceValues(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [
                $this->actorReference(),
                $this->sourceReference('invoice', 'invoices'),
                $this->sourceReference('purchase_invoice', 'purchase_invoices'),
                $this->supplierReference(),
            ],
            'table:synthetic_records',
        );
        $visited = [];

        $references->visitSourceRow(
            [
                'approved_by' => null,
                'source_id' => 17,
                'source_type' => 'invoice',
                'supplier_id' => 7,
            ],
            static function (
                \MyInvoice\Service\Backup\Company\CompanyBackupReference $reference,
                array $values,
            ) use (&$visited): void {
                $visited[] = [$reference->signature(), $values];
            },
        );

        self::assertSame([
            ['source_id->invoices:id?source_type=invoice', [17]],
            ['supplier_id->supplier:id', [7]],
        ], $visited);
    }

    public function testReferenceValueTraversalRejectsInvalidScalarTypes(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [$this->actorReference(), $this->supplierReference()],
            'table:synthetic_records',
        );

        foreach (
            [
                [['approved_by' => '9', 'supplier_id' => 7], 'approved_by'],
                [['approved_by' => null, 'supplier_id' => null], 'supplier_id'],
            ] as [$row, $column]
        ) {
            try {
                $references->visitSourceRow($row, static function (): void {});
                self::fail('Neplatná hodnota reference nesmí vstoupit do plánu.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_reference_value_invalid', $e->errorCode);
                self::assertSame($column, $e->column);
            }
        }
    }

    public function testReferenceTraversalRejectsUnclassifiedConditionalValue(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [
                $this->sourceReference('invoice', 'invoices'),
                $this->sourceReference('purchase_invoice', 'purchase_invoices'),
            ],
            'table:synthetic_records',
        );

        try {
            $references->visitSourceRow(
                ['source_id' => 17, 'source_type' => 'future_document'],
                static function (): void {},
            );
            self::fail('Neznámý typ neprázdné soft reference musí zastavit preflight.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_value_invalid', $e->errorCode);
            self::assertSame('source_id', $e->column);
        }
    }

    public function testZeroSentinelDoesNotCreateReferenceOccurrence(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [[
                'columns' => ['register_id'],
                'target' => 'table:cash_registers',
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantIdOrZero->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ]],
            'table:accounting_document_series',
        );
        $visited = [];

        $references->visitSourceRow(
            ['register_id' => 0],
            static function ($reference, array $values) use (&$visited): void {
                $visited[] = $values;
            },
        );
        self::assertSame([], $visited);

        $references->visitSourceRow(
            ['register_id' => 7],
            static function ($reference, array $values) use (&$visited): void {
                $visited[] = $values;
            },
        );
        self::assertSame([[7]], $visited);
    }

    public function testRemapsSharedCompositeColumnsFromOriginalRow(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [
                [
                    'columns' => ['supplier_id', 'employee_id'],
                    'target' => 'table:payroll_employees',
                    'target_columns' => ['supplier_id', 'id'],
                    'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                    'constraint' => CompanyBackupReferenceConstraint::Required->value,
                    'nullable_columns' => [],
                    'fallbacks' => [],
                ],
                $this->supplierReference(),
            ],
            'table:synthetic_records',
        );
        $visited = [];

        $restored = $references->remap(
            ['supplier_id' => 7, 'employee_id' => 31],
            static function (
                CompanyBackupReference $reference,
                array $values,
            ) use (&$visited): array {
                $visited[$reference->signature()] = $values;
                return count($values) === 2 ? [71, 401] : [71];
            },
        );

        self::assertSame(
            [
                'supplier_id,employee_id->payroll_employees:supplier_id,id' =>
                    [7, 31],
                'supplier_id->supplier:id' => [7],
            ],
            $visited,
        );
        self::assertSame(
            ['supplier_id' => 71, 'employee_id' => 401],
            $restored,
        );
    }

    public function testRemapAllowsActorNullAndPreservesZeroSentinel(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [
                $this->actorReference(),
                [
                    'columns' => ['register_id'],
                    'target' => 'table:cash_registers',
                    'target_columns' => ['id'],
                    'mapping' =>
                        CompanyBackupReferenceMapping::TenantIdOrZero->value,
                    'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                    'nullable_columns' => [],
                    'fallbacks' => [],
                ],
            ],
            'table:synthetic_records',
        );
        $calls = 0;

        $restored = $references->remap(
            ['approved_by' => 9, 'register_id' => 0],
            static function () use (&$calls): ?array {
                $calls++;
                return null;
            },
        );

        self::assertSame(1, $calls);
        self::assertSame(
            ['approved_by' => null, 'register_id' => 0],
            $restored,
        );
    }

    public function testRemapRejectsConflictingSharedColumnAtomically(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [
                [
                    'columns' => ['supplier_id', 'employee_id'],
                    'target' => 'table:payroll_employees',
                    'target_columns' => ['supplier_id', 'id'],
                    'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                    'constraint' => CompanyBackupReferenceConstraint::Required->value,
                    'nullable_columns' => [],
                    'fallbacks' => [],
                ],
                $this->supplierReference(),
            ],
            'table:synthetic_records',
        );
        $source = ['supplier_id' => 7, 'employee_id' => 31];

        try {
            $references->remap(
                $source,
                static fn (
                    CompanyBackupReference $reference,
                    array $values,
                ): array => count($values) === 2 ? [71, 401] : [72],
            );
            self::fail('Sdílený sloupec nesmí získat dvě různé cílové hodnoty.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_reference_value_invalid', $e->errorCode);
            self::assertSame('supplier_id', $e->column);
        }
        self::assertSame(['supplier_id' => 7, 'employee_id' => 31], $source);
    }

    public function testRemapRejectsInvalidMappedShapeAndRequiredNull(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            [$this->supplierReference()],
            'table:synthetic_records',
        );

        foreach ([[], [true], null] as $mapped) {
            try {
                $references->remap(
                    ['supplier_id' => 7],
                    static fn () => $mapped,
                );
                self::fail('Neplatný výsledek mapování nesmí vstoupit do řádku.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('data_reference_value_invalid', $e->errorCode);
                self::assertSame('supplier_id', $e->column);
            }
        }
    }

    /** @return array<string,mixed> */
    private function supplierReference(): array
    {
        return [
            'columns' => ['supplier_id'],
            'target' => 'table:supplier',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Required->value,
            'nullable_columns' => [],
            'fallbacks' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function actorReference(): array
    {
        return [
            'columns' => ['approved_by'],
            'target' => 'table:users',
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::Actor->value,
            'constraint' => CompanyBackupReferenceConstraint::Optional->value,
            'nullable_columns' => ['approved_by'],
            'fallbacks' => ['null', 'restore_actor'],
        ];
    }

    /** @return array<string,mixed> */
    private function accountCodeReference(string $column): array
    {
        return [
            'columns' => ['supplier_id', $column],
            'target' => 'table:chart_of_accounts',
            'target_columns' => ['supplier_id', 'account_code'],
            'mapping' => CompanyBackupReferenceMapping::TenantNaturalKey->value,
            'constraint' => CompanyBackupReferenceConstraint::Optional->value,
            'nullable_columns' => ['supplier_id', $column],
            'fallbacks' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function sourceReference(string $sourceType, string $target): array
    {
        return [
            'columns' => ['source_id'],
            'condition' => [
                'column' => 'source_type',
                'equals' => $sourceType,
            ],
            'target' => 'table:' . $target,
            'target_columns' => ['id'],
            'mapping' => CompanyBackupReferenceMapping::TenantId->value,
            'constraint' => CompanyBackupReferenceConstraint::Optional->value,
            'nullable_columns' => ['source_id'],
            'fallbacks' => [],
        ];
    }

    private function targetRegistry(): TenantDataRegistry
    {
        return new TenantDataRegistry(
            1,
            [
                $this->definition('supplier', TenantDataPolicy::TenantRoot),
                $this->definition('users', TenantDataPolicy::InstanceOwned),
            ],
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
            ['primary_key' => ['id'], ...$details],
        );
    }
}
