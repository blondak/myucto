<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupForeignKey;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPayrollEmploymentEventsProjectionTest extends TestCase
{
    /** @var list<string> */
    private const COLUMNS = [
        'id',
        'supplier_id',
        'employment_id',
        'event_type',
        'from_status',
        'to_status',
        'effective_on',
        'note',
        'diff_json',
        'created_by',
        'created_at',
    ];

    public function testDeclaresAndRemapsExactEmploymentEventProjection(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_employment_events');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);

        $projection->assertRuntimeSchema(self::COLUMNS, [], ['id']);
        $projection->references->assertRegistryTargets($registry);
        $projection->embeddedReferences->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema(
                ['created_by', 'diff_json', 'from_status', 'note', 'to_status'],
                [
                    new CompanyBackupForeignKey(
                        ['created_by'],
                        'users',
                        ['id'],
                    ),
                    new CompanyBackupForeignKey(
                        ['supplier_id', 'employment_id'],
                        'payroll_employments',
                        ['supplier_id', 'id'],
                    ),
                ],
            ),
        );

        self::assertSame(self::COLUMNS, $projection->dataColumns);
        self::assertArrayNotHasKey('natural_key', $definition->details);
        self::assertSame(
            [
                'created_by->users:id',
                'supplier_id,employment_id->payroll_employments:supplier_id,id',
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
        self::assertSame(['created_by'], $actor->nullableColumns);
        self::assertSame(['null', 'restore_actor'], $actor->fallbacks);
        self::assertSame(
            [
                'diff_json:office_id.from->payroll_offices:id',
                'diff_json:office_id.to->payroll_offices:id',
            ],
            array_map(
                static fn ($reference): string => $reference->signature(),
                $projection->embeddedReferences->references,
            ),
        );
        foreach ($projection->embeddedReferences->references as $reference) {
            self::assertSame(
                CompanyBackupReferenceMapping::TenantId,
                $reference->mapping,
            );
            self::assertTrue($reference->nullable);
        }

        $diff = CanonicalJson::encode([
            'office_id' => ['from' => 17, 'to' => 23],
            'weekly_hours' => ['from' => '40.00', 'to' => '32.00'],
        ]);
        $restored = $projection->remapEmbeddedReferences(
            ['diff_json' => $diff],
            static fn (
                CompanyBackupEmbeddedReference $reference,
                int|string $value,
            ): int => $reference->target === 'table:payroll_offices'
                ? (int) $value + 100
                : throw new \LogicException(
                    'Test zachytil neočekávanou referenci.',
                ),
        );
        self::assertSame(
            [
                'office_id' => ['from' => 117, 'to' => 123],
                'weekly_hours' => ['from' => '40.00', 'to' => '32.00'],
            ],
            json_decode(
                (string) $restored['diff_json'],
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );

        self::assertSame(
            ['status' => ['from' => 'planned', 'to' => 'active']],
            json_decode(
                (string) $projection->remapEmbeddedReferences(
                    ['diff_json' => CanonicalJson::encode([
                        'status' => ['from' => 'planned', 'to' => 'active'],
                    ])],
                    static fn (): never => throw new \LogicException(
                        'Chybějící office_id se nesmí mapovat.',
                    ),
                )['diff_json'],
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
        self::assertSame(
            ['office_id' => ['from' => null, 'to' => null]],
            json_decode(
                (string) $projection->remapEmbeddedReferences(
                    ['diff_json' => CanonicalJson::encode([
                        'office_id' => ['from' => null, 'to' => null],
                    ])],
                    static fn (): never => throw new \LogicException(
                        'Null reference se nesmí mapovat.',
                    ),
                )['diff_json'],
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
        self::assertSame(
            ['diff_json' => null],
            $projection->remapEmbeddedReferences(
                ['diff_json' => null],
                static fn (): never => throw new \LogicException(
                    'Null diff se nesmí mapovat.',
                ),
            ),
        );
    }
}
