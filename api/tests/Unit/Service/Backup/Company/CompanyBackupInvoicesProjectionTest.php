<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupInvoicesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupRestoreOverrideSet;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PHPUnit\Framework\TestCase;

final class CompanyBackupInvoicesProjectionTest extends TestCase
{
    public function testStoredColumnsAndOmittedSecretsAreDisjoint(): void
    {
        $columns = CompanyBackupInvoicesProjection::dataColumns();
        self::assertCount(count(array_unique($columns)), $columns);
        self::assertSame([], array_intersect($columns, [
            'amount_to_pay', 'effective_tax_date', 'approval_token', 'public_token',
        ]));
        self::assertSame(
            ['amount_to_pay', 'effective_tax_date'],
            CompanyBackupInvoicesProjection::generatedColumns(),
        );
        self::assertSame(
            ['idoklad_id', 'fakturoid_id'],
            CompanyBackupInvoicesProjection::preservedIdentifiers(),
        );
        self::assertContains('approval_receipt_hash', $columns);
        self::assertContains('approval_status', $columns);
        self::assertContains('supplier_snapshot', $columns);
        self::assertContains('imported_pdf_path', $columns);
        self::assertContains('prices_include_vat', $columns);
    }

    public function testPhysicalAndSoftReferencesHaveExplicitMappings(): void
    {
        $actual = [];
        foreach (CompanyBackupInvoicesProjection::references() as $reference) {
            $column = $reference['columns'][0];
            self::assertIsString($column);
            $actual[$column] = $reference;
        }
        ksort($actual);
        self::assertSame(
            [
                'booked_by', 'branding_profile_id', 'cash_register_id',
                'client_id', 'created_by', 'currency_id', 'parent_invoice_id',
                'project_id', 'recurring_template_id', 'revenue_category_id',
                'supplier_id',
            ],
            array_keys($actual),
        );
        foreach (['supplier_id', 'client_id', 'currency_id'] as $column) {
            self::assertSame(CompanyBackupReferenceConstraint::Required->value, $actual[$column]['constraint']);
            self::assertSame([], $actual[$column]['nullable_columns']);
        }
        foreach (['booked_by', 'created_by'] as $column) {
            self::assertSame(CompanyBackupReferenceMapping::Actor->value, $actual[$column]['mapping']);
            self::assertSame(['null', 'restore_actor'], $actual[$column]['fallbacks']);
        }
        self::assertSame(CompanyBackupReferenceConstraint::Optional->value, $actual['booked_by']['constraint']);
        self::assertSame(CompanyBackupReferenceConstraint::Required->value, $actual['created_by']['constraint']);
        self::assertSame(CompanyBackupReferenceConstraint::Optional->value, $actual['revenue_category_id']['constraint']);
        self::assertSame('table:revenue_categories', $actual['revenue_category_id']['target']);
        self::assertArrayNotHasKey('idoklad_id', $actual);
        self::assertArrayNotHasKey('fakturoid_id', $actual);
    }

    public function testSecretPolicyMatchesExistingInvoiceRegistry(): void
    {
        self::assertSame([
            'approval_token' => ['policy' => TenantSecretPolicy::OmitAndReconfigure->value],
            'approval_token_expires_at' => [
                'policy' => TenantSecretPolicy::NotSecret->value,
                'reason' => 'expiry_timestamp_without_token_is_inert',
            ],
            'public_token' => ['policy' => TenantSecretPolicy::OmitAndReconfigure->value],
        ], CompanyBackupInvoicesProjection::secretPolicies());
    }

    public function testReferencesParseAndRemapOnlyLocalIdentifiers(): void
    {
        $references = CompanyBackupReferenceSet::fromArray(
            CompanyBackupInvoicesProjection::references(), 'table:invoices',
        );
        self::assertCount(11, $references->references);
        $row = array_fill_keys(CompanyBackupInvoicesProjection::dataColumns(), null);
        $row['id'] = 7;
        $row['supplier_id'] = 2;
        $row['client_id'] = 3;
        $row['currency_id'] = 4;
        $row['parent_invoice_id'] = 5;
        $row['booked_by'] = 6;
        $row['total_without_vat'] = '100.0000';
        $row['total_vat'] = '21.0000';
        $row['total_with_vat'] = '121.0000';
        $row['advance_paid_amount'] = '40.0000';
        $row['paid_total'] = '50.0000';
        $row['prices_include_vat'] = 1;
        $row['approval_status'] = 'requested';
        $row['approval_receipt_hash'] = str_repeat('a', 64);
        $row['supplier_snapshot'] = '{"name":"Synthetic Ltd","id":2}';
        $row['idoklad_id'] = 91;
        $row['fakturoid_id'] = 92;
        $original = $row;

        $mapped = $references->remap($row,
            static function (CompanyBackupReference $reference, array $source): array {
                self::assertIsInt($source[0]);
                return [$source[0] + 100];
            },
        );
        foreach (['supplier_id', 'client_id', 'currency_id', 'parent_invoice_id', 'booked_by'] as $column) {
            self::assertIsInt($original[$column]);
            self::assertSame($original[$column] + 100, $mapped[$column]);
        }
        foreach ($original as $column => $value) {
            if (!in_array($column, ['supplier_id', 'client_id', 'currency_id', 'parent_invoice_id', 'booked_by'], true)) {
                self::assertSame($value, $mapped[$column], $column);
            }
        }
        self::assertSame($original, $row);
    }

    public function testRestoreResetsOnlyGeneratedPdfCache(): void
    {
        $columns = CompanyBackupInvoicesProjection::dataColumns();
        $references = CompanyBackupReferenceSet::fromArray(
            CompanyBackupInvoicesProjection::references(), 'table:invoices',
        );
        $overrides = CompanyBackupRestoreOverrideSet::fromArray(
            CompanyBackupInvoicesProjection::restoreOverrides(), 'table:invoices',
            $columns, ['id'], $references,
        );
        $row = array_fill_keys($columns, null);
        $row['pdf_path'] = 'storage/source/invoice.pdf';
        $row['pdf_generated_at'] = '2026-01-02 03:04:05';
        $row['imported_pdf_path'] = 'storage/imported/source.pdf';
        $row['imported_pdf_hash'] = str_repeat('b', 64);
        $row['imported_pdf_size_bytes'] = 1234;
        $row['imported_pdf_original_name'] = 'source.pdf';
        $row['paid_total'] = '50.0000';
        $row['total_vat'] = '21.0000';
        $original = $row;

        $expected = $row;
        $expected['pdf_path'] = null;
        $expected['pdf_generated_at'] = null;
        self::assertSame($expected, $overrides->apply($row));
        self::assertSame($original, $row);
    }

}
