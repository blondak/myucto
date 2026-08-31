<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollPersonTaxResidencesProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'residence',
        'country_code',
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

    public function testDeclaresExactTaxResidenceProjectionAndPreservesEvidence(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_person_tax_residences',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'country_code',
                    'effective_to',
                    'evidence_reference',
                    'evidence_note',
                    'created_by',
                    'updated_by',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['created_by'],
                        'users',
                        ['id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'employee_id'],
                        'payroll_employees',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['updated_by'],
                        'users',
                        ['id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'employee_id', 'effective_from'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            [
                'created_by->users:id',
                'supplier_id,employee_id->payroll_employees:supplier_id,id',
                'updated_by->users:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        foreach ([0, 2] as $actorIndex) {
            $actor = $projection->references->references[$actorIndex];
            self::assertSame(CompanyBackupReferenceMapping::Actor, $actor->mapping);
            self::assertSame(
                CompanyBackupReferenceConstraint::Optional,
                $actor->constraint,
            );
            self::assertSame(['null', 'restore_actor'], $actor->fallbacks);
        }

        self::assertSame([], $projection->restoreOverrides->overrides);
        self::assertSame(
            [
                'residence' => 'non-resident',
                'country_code' => 'SK',
                'evidence_reference' => 'document:synthetic-tax-residence',
            ],
            $projection->restoreOverrides->apply([
                'residence' => 'non-resident',
                'country_code' => 'SK',
                'evidence_reference' => 'document:synthetic-tax-residence',
            ]),
        );
    }
}
