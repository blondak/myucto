<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupEncodedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollPersonTaxChildClaimsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'dependant_id',
        'child_reference',
        'child_order',
        'claim_reason',
        'superseded_by_id',
        'ztp_p',
        'evidence_status',
        'shared_household_confirmed',
        'other_claimant_excluded',
        'effective_from',
        'effective_to',
        'evidence_reference',
        'evidence_note',
        'created_by',
        'updated_by',
        'row_version',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactClaimGraphAndCorrelatedChildIdentity(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_person_tax_child_claims',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->encodedReferences->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'dependant_id',
                    'claim_reason',
                    'superseded_by_id',
                    'effective_to',
                    'evidence_reference',
                    'evidence_note',
                    'created_by',
                    'updated_by',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'dependant_id'],
                        'payroll_dependants',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'employee_id'],
                        'payroll_employees',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(['created_by'], 'users', ['id']),
                    new CompanyBackupForeignKey(['updated_by'], 'users', ['id']),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            [
                'supplier_id',
                'employee_id',
                'child_reference',
                'effective_from',
            ],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame([], $projection->secretPolicies);
        self::assertSame([], $projection->omitColumns);
        self::assertSame([], $projection->restoreOverrides->overrides);
        self::assertSame(
            [
                'created_by->users:id',
                'supplier_id,dependant_id->payroll_dependants:supplier_id,id',
                'supplier_id,employee_id->payroll_employees:supplier_id,id',
                'supplier_id,superseded_by_id'
                    . '->payroll_person_tax_child_claims:supplier_id,id',
                'updated_by->users:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Optional,
            $projection->references->references[3]->constraint,
        );
        self::assertSame(
            'child_reference=dependant_id->payroll_dependants:id@dependant-',
            $projection->encodedReferences->references[0]->signature(),
        );

        $modern = $projection->remapPayloadReferences(
            [
                'dependant_id' => 17,
                'child_reference' => 'dependant-17',
            ],
            static function (
                CompanyBackupEncodedReference|CompanyBackupEmbeddedReference $reference,
                int|string $value,
            ): int {
                self::assertInstanceOf(CompanyBackupEncodedReference::class, $reference);
                self::assertSame(17, $value);
                return 117;
            },
        );
        self::assertSame(117, $modern['dependant_id']);
        self::assertSame('dependant-117', $modern['child_reference']);

        self::assertSame(
            [
                'dependant_id' => null,
                'child_reference' => 'legacy:synthetic-child',
            ],
            $projection->remapPayloadReferences(
                [
                    'dependant_id' => null,
                    'child_reference' => 'legacy:synthetic-child',
                ],
                static fn (): never => throw new \LogicException(
                    'Legacy reference nesmí být interpretována jako ID.',
                ),
            ),
        );

        foreach ($projection->references->references as $reference) {
            if ($reference->mapping === CompanyBackupReferenceMapping::Actor) {
                self::assertSame(['null', 'restore_actor'], $reference->fallbacks);
            }
        }
    }
}
