<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupInvoiceSupplierSnapshotContract;
use MyInvoice\Service\Backup\Company\CompanyBackupInvoiceSupplierSnapshotRemapper;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use PHPUnit\Framework\TestCase;

final class CompanyBackupInvoiceSupplierSnapshotRemapperTest extends TestCase
{
    public function testRemapsOnlyLiveIdsWithExactHistoricalBytesPreserved(): void
    {
        $source = "{\n  \"id\" : 7, \"company_name\":\"D\\u00f3m\\/test\", \"branding_profile_id\" : 99,\n"
            . '  "email_profile_id":11, "branding_profile_name":"Žlutý dům",'
            . ' "logo_path":"storage/supplier-logos/sup-7-brand-99-abcdef012345.png",'
            . ' "email_accent_color":"#AABBCC", "is_vat_payer":true, "is_identified":false }';
        $expected = str_replace(['"id" : 7', '"email_profile_id":11'],
            ['"id" : 41', '"email_profile_id":23'], $source);

        self::assertSame($expected, CompanyBackupInvoiceSupplierSnapshotRemapper::rewrite(
            $source, 7, 41, [11 => 23],
        ));
        self::assertSame([
            'supplier_id' => 7,
            'email_profile_id' => 11,
        ], CompanyBackupInvoiceSupplierSnapshotContract::inspect($source, 7));
    }

    public function testDeletedHistoricalBrandMarkerSurvivesWithoutLiveBrandMapping(): void
    {
        $source = '{"id":7,"company_name":"Archiv","branding_profile_id":999,'
            . '"branding_profile_name":"Zaniklý profil","email_branding_enabled":true}';
        self::assertSame(
            str_replace('"id":7', '"id":41', $source),
            CompanyBackupInvoiceSupplierSnapshotRemapper::rewrite($source, 7, 41, []),
        );
    }

    public function testNullAndKnownLegacySubsetsStayByteIdenticalWithoutInventedIds(): void
    {
        self::assertNull(CompanyBackupInvoiceSupplierSnapshotRemapper::rewrite(null, 7, 41, []));
        foreach ([
            '{"company_name":"Archiv"}',
            " { \"company_name\" : \"Archiv\", \"is_vat_payer\" : false } \n",
            '{"company_name":"Archiv","branding_profile_id":null,"email_profile_id":null}',
        ] as $source) {
            self::assertSame($source, CompanyBackupInvoiceSupplierSnapshotRemapper::rewrite(
                $source, 7, 41, [],
            ));
        }
    }

    public function testRejectsUnknownDuplicatedOrUnsupportedObjectShapes(): void
    {
        foreach ([
            '{}', '[]', 'null', '"text"',
            '{"company_name":"Archiv","future_key":1}',
            '{"company_name":"Archiv","id":7,"\\u0069d":8}',
            '{"company_name":{"nested":"Archiv"}}',
            '{"company_name":"Archiv","logo_path":["invalid"]}',
            '{"company_name":"Archiv","is_vat_payer":1}',
            '{"company_name":"Archiv","email_branding_enabled":0}',
        ] as $source) {
            $this->assertSafeFailure(static fn () => CompanyBackupInvoiceSupplierSnapshotRemapper::rewrite(
                $source, 7, 41, [],
            ));
        }
    }

    public function testRejectsWrongSupplierAndNonCanonicalIds(): void
    {
        foreach ([
            '{"company_name":"Archiv","id":8}',
            '{"company_name":"Archiv","id":null}',
            '{"company_name":"Archiv","id":0}',
            '{"company_name":"Archiv","id":-7}',
            '{"company_name":"Archiv","id":7.0}',
            '{"company_name":"Archiv","id":7e0}',
            '{"company_name":"Archiv","id":"7"}',
            '{"company_name":"Archiv","id":9223372036854775808}',
            '{"company_name":"Archiv","branding_profile_id":0}',
            '{"company_name":"Archiv","branding_profile_id":7.5}',
            '{"company_name":"Archiv","email_profile_id":0}',
            '{"company_name":"Archiv","email_profile_id":7e0}',
        ] as $source) {
            $this->assertSafeFailure(static fn () => CompanyBackupInvoiceSupplierSnapshotRemapper::rewrite(
                $source, 7, 41, [],
            ));
        }
        $this->assertSafeFailure(static fn () => CompanyBackupInvoiceSupplierSnapshotRemapper::rewrite(
            '{"company_name":"Archiv"}', 0, 41, [],
        ));
        $this->assertSafeFailure(static fn () => CompanyBackupInvoiceSupplierSnapshotRemapper::rewrite(
            '{"company_name":"Archiv"}', 7, 0, [],
        ));
    }

    public function testNonNullEmailIdRequiresExplicitValidMapping(): void
    {
        $source = '{"id":7,"company_name":"Archiv","email_profile_id":11}';
        foreach ([[], [11 => 0], [11 => '23'], [11 => 23.0], [11 => null]] as $map) {
            $this->assertSafeFailure(static fn () => CompanyBackupInvoiceSupplierSnapshotRemapper::rewrite(
                $source, 7, 41, $map,
            ));
        }
    }

    public function testRejectsOversizedAndMalformedJson(): void
    {
        $this->assertSafeFailure(static fn () => CompanyBackupInvoiceSupplierSnapshotRemapper::rewrite(
            '{"company_name":"' . str_repeat('x', 8_388_608) . '"}', 7, 41, [],
        ));
        $this->assertSafeFailure(static fn () => CompanyBackupInvoiceSupplierSnapshotRemapper::rewrite(
            '{"company_name":"bad', 7, 41, [],
        ));
    }

    private function assertSafeFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('Neplatný snapshot musí být odmítnut.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('table:invoices', $e->registryKey);
            self::assertSame('supplier_snapshot', $e->column);
            self::assertStringNotContainsString('Archiv', $e->getMessage());
        }
    }
}
