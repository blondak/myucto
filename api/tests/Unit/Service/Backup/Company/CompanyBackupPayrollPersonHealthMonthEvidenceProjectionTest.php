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

final class CompanyBackupPayrollPersonHealthMonthEvidenceProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'period_start',
        'top_up_responsibility',
        'top_up_responsibility_evidence_reference',
        'selected_top_up_employer_reference',
        'selected_top_up_employer_evidence_reference',
        'evidence_note',
        'created_by',
        'updated_by',
        'row_version',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactMonthEvidenceAndSelectedEmployerReference(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_person_health_month_evidence',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'top_up_responsibility_evidence_reference',
                    'selected_top_up_employer_reference',
                    'selected_top_up_employer_evidence_reference',
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
            ['supplier_id', 'employee_id', 'period_start'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            [
                'created_by->users:id',
                'supplier_id,employee_id,period_start,'
                    . 'selected_top_up_employer_reference'
                    . '->payroll_person_health_other_employer_bases:'
                    . 'supplier_id,employee_id,period_start,employer_reference',
                'supplier_id,employee_id->payroll_employees:supplier_id,id',
                'updated_by->users:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        foreach ([0, 3] as $actorIndex) {
            $actor = $projection->references->references[$actorIndex];
            self::assertSame(CompanyBackupReferenceMapping::Actor, $actor->mapping);
            self::assertSame(
                CompanyBackupReferenceConstraint::Optional,
                $actor->constraint,
            );
            self::assertSame(['null', 'restore_actor'], $actor->fallbacks);
        }
        $selectedEmployer = $projection->references->references[1];
        self::assertSame(
            CompanyBackupReferenceMapping::TenantNaturalKey,
            $selectedEmployer->mapping,
        );
        self::assertSame(
            CompanyBackupReferenceConstraint::Optional,
            $selectedEmployer->constraint,
        );
        self::assertSame(
            ['selected_top_up_employer_reference'],
            $selectedEmployer->nullableColumns,
        );
        self::assertSame([], $selectedEmployer->fallbacks);

        self::assertSame([], $projection->restoreOverrides->overrides);
    }
}
