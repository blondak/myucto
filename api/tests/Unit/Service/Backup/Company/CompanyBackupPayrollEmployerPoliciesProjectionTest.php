<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollEmployerPoliciesProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'valid_from',
        'valid_to',
        'payday_day',
        'payday_month_offset',
        'payday_business_day_rule',
        'balance_rounding_mode',
        'home_office_policy',
        'travel_expense_policy',
        'leave_entitlement_weeks',
        'four_eyes_required',
        'automatic_calculation_enabled',
        'automatic_posting_enabled',
        'automatic_payments_enabled',
        'delivery_channel',
        'delivery_verified_on',
        'source_kind',
        'source_reference',
        'created_by',
        'updated_by',
        'row_version',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactColumnsReferencesAndSafeRestoreState(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_employer_policies');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                [
                    'valid_to',
                    'delivery_verified_on',
                    'source_reference',
                    'created_by',
                    'updated_by',
                ],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id'],
                        'supplier',
                        ['id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertSame(
            ['supplier_id', 'valid_from'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            [
                'created_by->users:id',
                'supplier_id->supplier:id',
                'updated_by->users:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            ['null', 'restore_actor'],
            $projection->references->references[0]->fallbacks,
        );
        self::assertSame(
            ['null', 'restore_actor'],
            $projection->references->references[2]->fallbacks,
        );

        $restored = $projection->restoreOverrides->apply([
            'automatic_calculation_enabled' => 1,
            'automatic_payments_enabled' => 1,
            'automatic_posting_enabled' => 1,
            'delivery_channel' => 'smime_email',
            'delivery_verified_on' => '2026-01-01',
            'four_eyes_required' => 1,
        ]);
        self::assertSame(0, $restored['automatic_calculation_enabled']);
        self::assertSame(0, $restored['automatic_payments_enabled']);
        self::assertSame(0, $restored['automatic_posting_enabled']);
        self::assertSame('disabled', $restored['delivery_channel']);
        self::assertNull($restored['delivery_verified_on']);
        self::assertSame(1, $restored['four_eyes_required']);
    }
}
