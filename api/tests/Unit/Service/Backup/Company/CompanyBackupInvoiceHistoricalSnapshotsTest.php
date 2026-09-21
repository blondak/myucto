<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupInvoiceSupplierSnapshotContract;
use MyInvoice\Service\Backup\Company\CompanyBackupInvoicesProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use PHPUnit\Framework\TestCase;

final class CompanyBackupInvoiceHistoricalSnapshotsTest extends TestCase
{
    public function testOnlyLiveReferencesChangeAndHistoricalJsonRemainsByteExact(): void
    {
        $scalar = CompanyBackupReferenceSet::fromArray(
            CompanyBackupInvoicesProjection::references(), 'table:invoices',
        );
        $embedded = CompanyBackupEmbeddedReferenceSet::fromArray(
            CompanyBackupInvoicesProjection::embeddedReferences(),
            'table:invoices', CompanyBackupInvoicesProjection::dataColumns(),
        );
        $client = " {\n \"id\" : 3, \"code\":\"003\", \"company_name\":\"Synthetic \\u010c\", \"legacy\":{\"id\":17} } ";
        $bank = ' { "id":4, "currency":"CZK", "account_number":"1000000005",'
            . ' "bank_code":"0100", "iban":"CZ1801000000001000000005",'
            . ' "bic":null, "legacy":{"id":4,"code":"004"} } ';
        self::assertJson($client);
        self::assertJson($bank);
        $source = array_fill_keys(CompanyBackupInvoicesProjection::dataColumns(), null);
        $source['id'] = 101;
        $source['client_id'] = 3;
        $source['currency_id'] = 4;
        $source['supplier_id'] = 7;
        $source['client_snapshot'] = $client;
        $source['bank_snapshot'] = $bank;
        $source['supplier_snapshot'] = ' { "id" : 7, "company_name":"Synthetic", "email_profile_id" : 11 } ';
        $original = $source;

        $mapped = $scalar->remap($source, static function (
            CompanyBackupReference $reference,
            array $values,
        ): array {
            self::assertIsInt($values[0]);
            return [$values[0] + 100];
        });
        $visited = [];
        $mapped = $embedded->remap($mapped, static function (
            CompanyBackupEmbeddedReference $reference,
            int|string $value,
        ) use (&$visited): int {
            $visited[] = $reference->column . ':' . implode('.', $reference->path);
            self::assertIsInt($value);
            return $value + 100;
        });

        self::assertSame(103, $mapped['client_id']);
        self::assertSame(104, $mapped['currency_id']);
        self::assertSame(107, $mapped['supplier_id']);
        self::assertSame($client, $mapped['client_snapshot']);
        self::assertSame($bank, $mapped['bank_snapshot']);
        self::assertSame(
            ' { "id" : 107, "company_name":"Synthetic", "email_profile_id" : 111 } ',
            $mapped['supplier_snapshot'],
        );
        self::assertSame(['supplier_snapshot:email_profile_id', 'supplier_snapshot:id'], $visited);
        self::assertSame($original, $source);
    }

    public function testNullAndMissingLegacyIdsRemainUntouched(): void
    {
        $set = CompanyBackupEmbeddedReferenceSet::fromArray(
            CompanyBackupInvoicesProjection::embeddedReferences(),
            'table:invoices', CompanyBackupInvoicesProjection::dataColumns(),
        );
        foreach ([null, ' { "company_name" : "Synthetic" } '] as $client) {
            foreach ([null, ' { "currency" : "CZK", "account_number" : "1000000005" } '] as $bank) {
                $row = ['supplier_snapshot' => null, 'client_snapshot' => $client, 'bank_snapshot' => $bank];
                $calls = 0;
                $mapped = $set->remap($row, static function () use (&$calls): int {
                    $calls++;
                    return 1;
                });
                self::assertSame($row, $mapped);
                self::assertSame(0, $calls);
            }
        }
    }

    public function testHistoricalReferenceClaimsAreRejectedAtMetadataBoundary(): void
    {
        $columns = CompanyBackupInvoicesProjection::dataColumns();
        $supplier = CompanyBackupInvoiceSupplierSnapshotContract::embeddedReferences();
        foreach (['client_snapshot', 'bank_snapshot'] as $column) {
            foreach ([['id'], ['legacy', 'id']] as $path) {
                $claim = array_replace($supplier[0], ['column' => $column, 'path' => $path]);
                $claims = [...$supplier, $claim];
                usort($claims, static fn (array $left, array $right): int => strcmp(
                    CompanyBackupEmbeddedReference::fromArray($left, 'table:invoices')->signature(),
                    CompanyBackupEmbeddedReference::fromArray($right, 'table:invoices')->signature(),
                ));
                try {
                    CompanyBackupEmbeddedReferenceSet::fromArray($claims, 'table:invoices', $columns);
                    self::fail('Historical snapshot reference claim must be rejected.');
                } catch (CompanyBackupDataSourceException $e) {
                    self::assertSame('data_embedded_reference_metadata_invalid', $e->errorCode);
                    self::assertSame($column, $e->column);
                }
                self::assertCount(1, CompanyBackupEmbeddedReferenceSet::fromArray(
                    [$claim], 'table:synthetic', [$column],
                )->references);
            }
        }
    }
}
