<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupInvoiceSupplierSnapshotContract;
use MyInvoice\Service\Backup\Company\CompanyBackupLosslessJson;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceRemapDirective;
use PHPUnit\Framework\TestCase;

final class CompanyBackupInvoiceSupplierSnapshotEmbeddedReferenceSetTest extends TestCase
{
    public function testMapsBothIdsThroughSetWithoutChangingHistoricalBytesOrSource(): void
    {
        $set = $this->set();
        $source = " {\n \"id\" : 7, \"company_name\":\"D\\u00f3m\\/test\","
            . ' "email_profile_id" : 11, "branding_profile_id":999,'
            . ' "branding_profile_name":"Historický profil" } ';
        $row = ['supplier_id' => 41, 'supplier_snapshot' => $source];
        $visited = [];

        $result = $set->remap($row, static function (
            CompanyBackupEmbeddedReference $reference,
            int|string $id,
        ) use (&$visited): int {
            $visited[] = [$reference->signature(), $id];
            return match ($reference->target) {
                'table:supplier' => 41,
                'table:email_profiles' => 23,
                default => self::fail('Neznámý cíl snapshotu.'),
            };
        });

        self::assertSame([
            ['supplier_snapshot:email_profile_id->email_profiles:id', 11],
            ['supplier_snapshot:id->supplier:id', 7],
        ], $visited);
        self::assertSame(
            str_replace(['"id" : 7', '"email_profile_id" : 11'],
                ['"id" : 41', '"email_profile_id" : 23'], $source),
            $result['supplier_snapshot'],
        );
        self::assertSame($source, $row['supplier_snapshot']);
        self::assertSame(41, $result['supplier_id']);
    }

    public function testDeferredPassCanRestartFromOriginalSource(): void
    {
        $set = $this->set();
        $source = '{ "id":7,"company_name":"Archiv", "email_profile_id":11 }';
        $row = ['supplier_snapshot' => $source];
        $deferred = $set->remap(
            $row,
            static fn (): CompanyBackupReferenceRemapDirective =>
                CompanyBackupReferenceRemapDirective::Defer,
        );
        self::assertSame(
            '{ "id":null,"company_name":"Archiv", "email_profile_id":null }',
            $deferred['supplier_snapshot'],
        );
        $final = $set->remap(
            $row,
            static fn (CompanyBackupEmbeddedReference $reference): int =>
                $reference->target === 'table:supplier' ? 41 : 23,
        );
        self::assertSame(
            '{ "id":41,"company_name":"Archiv", "email_profile_id":23 }',
            $final['supplier_snapshot'],
        );
    }

    public function testNullAndLegacyMissingIdsStayUntouched(): void
    {
        $set = $this->set();
        $calls = 0;
        foreach ([null, ' { "company_name" : "Archiv" } ',
            '{"company_name":"Archiv","email_profile_id":null}'] as $source) {
            $result = $set->remap(
                ['supplier_snapshot' => $source],
                static function () use (&$calls): int { $calls++; return 1; },
            );
            self::assertSame($source, $result['supplier_snapshot']);
        }
        self::assertSame(0, $calls);
    }

    public function testExactMetadataClaimsAreRequiredOnlyWhenColumnIsExported(): void
    {
        $expected = CompanyBackupInvoiceSupplierSnapshotContract::embeddedReferences();
        $extra = $expected[0];
        $extra['column'] = 'other_json';
        $set = CompanyBackupEmbeddedReferenceSet::fromArray(
            [$extra, ...$expected], 'table:invoices', ['supplier_snapshot', 'other_json'],
        );
        self::assertCount(3, $set->references);
        self::assertSame([], CompanyBackupEmbeddedReferenceSet::fromArray(
            [], 'table:invoices', ['id'],
        )->references);

        foreach ([
            [],
            [$expected[0]],
            [$expected[1]],
            [$expected[0], array_replace($expected[1], ['target' => 'table:clients'])],
            [$expected[0], $expected[1], array_replace($expected[1], ['path' => ['other_id']])],
        ] as $claims) {
            try {
                CompanyBackupEmbeddedReferenceSet::fromArray(
                    $claims, 'table:invoices', ['supplier_snapshot'],
                );
                self::fail('Změněný kontrakt snapshotu musí být odmítnut.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertSame('supplier_snapshot', $e->column);
            }
        }
    }

    public function testMalformedDuplicateAndUnknownSnapshotFailsWithoutPayload(): void
    {
        foreach ([
            '{"id":7,"company_name":"Archiv","id":8}',
            '{"id":7,"company_name":"Archiv","future_key":1}',
            '{"id":0,"company_name":"Archiv"}',
            '{"id":"7","company_name":"Archiv"}',
            '{"company_name":"Archiv","email_profile_id":0}',
            '{"company_name":"Archiv"',
        ] as $source) {
            try {
                $this->set()->remap(
                    ['supplier_snapshot' => $source],
                    static fn (): int => 41,
                );
                self::fail('Neplatný snapshot musí být odmítnut.');
            } catch (CompanyBackupPreflightException $e) {
                self::assertSame('table:invoices', $e->registryKey);
                self::assertSame('supplier_snapshot', $e->column);
                self::assertStringNotContainsString('Archiv', $e->getMessage());
            }
        }
    }

    public function testOversizedSnapshotIsRejectedBeforeMapping(): void
    {
        $source = '{"id":7,"company_name":"'
            . str_repeat('x', CompanyBackupLosslessJson::DEFAULT_MAX_BYTES) . '"}';
        $calls = 0;
        try {
            $this->set()->remap(
                ['supplier_snapshot' => $source],
                static function () use (&$calls): int { $calls++; return 41; },
            );
            self::fail('Nadlimitní snapshot musí být odmítnut před mapováním.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('invoice_supplier_snapshot_invalid', $e->errorCode);
            self::assertSame('table:invoices', $e->registryKey);
            self::assertSame('supplier_snapshot', $e->column);
            self::assertSame(0, $calls);
        }
    }

    private function set(): CompanyBackupEmbeddedReferenceSet
    {
        return CompanyBackupEmbeddedReferenceSet::fromArray(
            CompanyBackupInvoiceSupplierSnapshotContract::embeddedReferences(),
            'table:invoices',
            ['supplier_snapshot'],
        );
    }
}
