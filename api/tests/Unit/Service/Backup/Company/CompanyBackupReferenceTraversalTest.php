<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceOccurrence;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupReferenceTraversalTest extends TestCase
{
    public function testUnifiesColumnEncodedAndEmbeddedReferences(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition(
            'table:payroll_inputs',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $occurrences = [];

        $projection->visitSourceReferences(
            [
                'approved_by' => null,
                'component_id' => 31,
                'component_snapshot_json' => CanonicalJson::encode([
                    'component_id' => 31,
                ]),
                'created_by' => 9,
                'employee_id' => 32,
                'employment_id' => 33,
                'external_id' => 'travel:41:exempt',
                'import_id' => null,
                'recurring_component_id' => null,
                'source_kind' => 'travel',
                'source_snapshot_json' => CanonicalJson::encode([
                    'business_trip_id' => 41,
                ]),
                'supplier_id' => 7,
            ],
            static function (CompanyBackupReferenceOccurrence $occurrence) use (
                &$occurrences,
            ): void {
                $occurrences[] = $occurrence;
            },
        );

        self::assertCount(7, $occurrences);
        self::assertSame(
            [
                CompanyBackupReferenceOccurrence::KIND_COLUMN => 4,
                CompanyBackupReferenceOccurrence::KIND_ENCODED => 1,
                CompanyBackupReferenceOccurrence::KIND_EMBEDDED => 2,
            ],
            array_count_values(array_map(
                static fn (CompanyBackupReferenceOccurrence $occurrence): string =>
                    $occurrence->sourceKind,
                $occurrences,
            )),
        );
        self::assertSame(
            [['id' => 9]],
            array_values(array_map(
                static fn (CompanyBackupReferenceOccurrence $occurrence): array =>
                    $occurrence->sourceKey,
                array_filter(
                    $occurrences,
                    static fn (CompanyBackupReferenceOccurrence $occurrence): bool =>
                        $occurrence->mapping === CompanyBackupReferenceMapping::Actor,
                ),
            )),
        );
        self::assertSame(
            [
                ['id' => 41],
                ['id' => 31],
                ['id' => 41],
            ],
            array_map(
                static fn (CompanyBackupReferenceOccurrence $occurrence): array =>
                    $occurrence->sourceKey,
                array_slice($occurrences, 4),
            ),
        );
    }

    public function testDecodesPolymorphicIdButDoesNotPlanPreservedValue(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition(
            'table:journal_entries',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $occurrences = [];
        $visitor = static function (
            CompanyBackupReferenceOccurrence $occurrence,
        ) use (&$occurrences): void {
            $occurrences[] = $occurrence;
        };
        $base = [
            'period_id' => 13,
            'posted_by' => null,
            'reversed_by' => null,
            'supplier_id' => 7,
        ];

        $projection->visitSourceReferences([
            ...$base,
            'source_id' => 17,
            'source_type' => 'invoice',
        ], $visitor);
        $projection->visitSourceReferences([
            ...$base,
            'source_id' => 202601,
            'source_type' => 'manual',
        ], $visitor);

        $polymorphic = array_values(array_filter(
            $occurrences,
            static fn (CompanyBackupReferenceOccurrence $occurrence): bool =>
                $occurrence->sourceKind
                    === CompanyBackupReferenceOccurrence::KIND_POLYMORPHIC,
        ));
        self::assertCount(1, $polymorphic);
        self::assertSame('table:invoices', $polymorphic[0]->targetRegistryKey);
        self::assertSame(['id' => 17], $polymorphic[0]->sourceKey);
    }
}
