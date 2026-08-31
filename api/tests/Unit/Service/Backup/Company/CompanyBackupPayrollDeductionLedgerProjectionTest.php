<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupColumnCodec;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollDeductionLedgerProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'agreement_id',
        'revision_id',
        'employee_id',
        'event_kind',
        'amount_minor',
        'event_key_hash',
        'source_ledger_id',
        'metadata_json',
        'actor_user_id',
        'created_at',
    ];

    public function testDeclaresExactAppendOnlyLedgerProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_deduction_ledger');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(
            self::COLUMNS,
            [],
            ['id'],
            ['event_key_hash'],
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                ['agreement_id', 'source_ledger_id', 'actor_user_id'],
                [
                    new CompanyBackupForeignKey(
                        ['actor_user_id'],
                        'users',
                        ['id'],
                    ),
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
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'revision_id'],
                        'payroll_run_revisions',
                        ['supplier_id', 'id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'source_ledger_id'],
                        'payroll_deduction_ledger',
                        ['supplier_id', 'id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            CompanyBackupColumnCodec::BinaryHex,
            $projection->columnCodecs['event_key_hash'] ?? null,
        );
        self::assertSame(
            ['supplier_id', 'event_key_hash'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            [
                'actor_user_id->users:id',
                'supplier_id,agreement_id,employee_id'
                    . '->payroll_deduction_agreements:supplier_id,id,employee_id',
                'supplier_id,employee_id->payroll_employees:supplier_id,id',
                'supplier_id,revision_id->payroll_run_revisions:supplier_id,id',
                'supplier_id,source_ledger_id'
                    . '->payroll_deduction_ledger:supplier_id,id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        $actor = $projection->references->references[0];
        self::assertSame(CompanyBackupReferenceMapping::Actor, $actor->mapping);
        self::assertSame(
            CompanyBackupReferenceConstraint::Optional,
            $actor->constraint,
        );
        self::assertSame(['null', 'restore_actor'], $actor->fallbacks);
        self::assertSame(
            CompanyBackupReferenceMapping::TenantReferenceKey,
            $projection->references->references[1]->mapping,
        );
        self::assertSame(
            ['agreement_id'],
            $projection->references->references[1]->nullableColumns,
        );
        self::assertSame(
            ['source_ledger_id'],
            $projection->references->references[4]->nullableColumns,
        );
    }
}
