<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupEncodedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollPayoutRulesProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'allocation_reference',
        'destination_kind',
        'destination_reference',
        'allocation_kind',
        'amount_minor',
        'basis_points',
        'priority_no',
        'is_active',
        'row_version',
        'created_at',
        'updated_at',
        'remainder_guard',
    ];

    /** @var list<string> */
    private const DATA_COLUMNS = [
        'id',
        'supplier_id',
        'employee_id',
        'allocation_reference',
        'destination_kind',
        'destination_reference',
        'allocation_kind',
        'amount_minor',
        'basis_points',
        'priority_no',
        'is_active',
        'row_version',
        'created_at',
        'updated_at',
    ];

    public function testDeclaresExactRuleGraphAndConditionalBankTarget(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_payout_rules');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(
            self::COLUMNS,
            ['remainder_guard'],
            ['id'],
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->encodedReferences->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                ['destination_reference', 'amount_minor', 'basis_points'],
                [
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'employee_id'],
                        'payroll_employees',
                        ['supplier_id', 'id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::DATA_COLUMNS, $projection->dataColumns);
        self::assertSame(['remainder_guard'], $projection->generatedColumns);
        self::assertSame([], $projection->omitColumns);
        self::assertSame([], $projection->secretPolicies);
        self::assertSame([], $projection->restoreOverrides->overrides);
        self::assertSame(
            ['supplier_id', 'employee_id', 'allocation_reference'],
            $definition->details['natural_key'] ?? null,
        );
        self::assertSame(
            ['supplier_id,employee_id->payroll_employees:supplier_id,id'],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->references->references,
            ),
        );
        self::assertSame(
            'destination_reference->payroll_person_accounts:id'
                . '?destination_kind=bank@account:',
            $projection->encodedReferences->references[0]->signature(),
        );

        $employee = $projection->references->references[0];
        self::assertSame(CompanyBackupReferenceMapping::TenantId, $employee->mapping);
        self::assertSame(CompanyBackupReferenceConstraint::Required, $employee->constraint);

        $bank = $projection->remapPayloadReferences(
            [
                'destination_kind' => 'bank',
                'destination_reference' => 'account:17',
            ],
            static function (
                CompanyBackupEncodedReference|CompanyBackupEmbeddedReference $reference,
                int|string $value,
            ): int {
                self::assertInstanceOf(CompanyBackupEncodedReference::class, $reference);
                self::assertSame('table:payroll_person_accounts', $reference->target);
                self::assertSame(17, $value);
                return 117;
            },
        );
        self::assertSame('account:117', $bank['destination_reference']);

        foreach ([
            [
                'destination_kind' => 'cash',
                'destination_reference' => null,
            ],
            [
                'destination_kind' => 'partner_settlement',
                'destination_reference' => '365.100',
            ],
        ] as $unchanged) {
            self::assertSame(
                $unchanged,
                $projection->remapPayloadReferences(
                    $unchanged,
                    static fn (): never => throw new \LogicException(
                        'Nebankovní cíl nesmí volat ID mapper.',
                    ),
                ),
            );
        }
    }
}
