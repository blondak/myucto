<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinksProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupWorkReportLinksProjectionTest extends TestCase
{
    /** @return array<string,int|string|null> */
    private static function row(): array
    {
        return [
            'id' => 7,
            'supplier_id' => 2,
            'scope' => 'project',
            'client_id' => 11,
            'project_id' => 13,
            'created_by_user_id' => 17,
            'created_at' => '2025-12-31 23:59:00',
            'last_sent_at' => '2026-01-01 00:01:00',
            'last_viewed_at' => '2026-01-02 01:02:03',
            'revoked_at' => '2026-01-03 04:05:06',
        ];
    }

    public function testRemapsOnlyReferencesAndPreservesHistory(): void
    {
        $row = self::row();
        self::assertSame(array_keys($row), CompanyBackupWorkReportLinksProjection::dataColumns());
        CompanyBackupWorkReportLinksProjection::validateRow($row);
        $refs = CompanyBackupReferenceSet::fromArray(
            CompanyBackupWorkReportLinksProjection::references(), 'table:work_report_links',
        );
        self::assertSame(
            [
                'client_id->clients:id', 'created_by_user_id->users:id',
                'project_id->projects:id', 'supplier_id->supplier:id',
            ],
            array_map(static fn (CompanyBackupReference $ref): string => $ref->signature(), $refs->references),
        );
        $mapped = $refs->remap($row, static function (CompanyBackupReference $ref, array $key): array {
            self::assertCount(1, $key);
            self::assertIsInt($key[0]);
            return [$key[0] + 100];
        });
        $expected = $row;
        foreach (['supplier_id', 'client_id', 'project_id', 'created_by_user_id'] as $column) {
            self::assertIsInt($expected[$column]);
            $expected[$column] += 100;
        }
        self::assertSame($expected, $mapped);
        self::assertSame(self::row(), $row);
        self::assertArrayNotHasKey('token', $mapped);
        self::assertSame(['token' => ['policy' => 'protected_domain_secret', 'storage' => 'raw']],
            CompanyBackupWorkReportLinksProjection::secrets());
        self::assertSame([[
            'materializer' => 'raw_bytes_v1', 'secret_column' => 'token',
            'tenant_id_column' => 'supplier_id', 'nullable' => false, 'bytes' => 48,
        ]], CompanyBackupWorkReportLinksProjection::protectedSecretMaterializations());

        foreach ($refs->references as $ref) {
            self::assertSame(CompanyBackupReferenceConstraint::Optional, $ref->constraint);
            self::assertSame(
                $ref->firstColumn() === 'created_by_user_id'
                    ? CompanyBackupReferenceMapping::Actor : CompanyBackupReferenceMapping::TenantId,
                $ref->mapping,
            );
            self::assertSame(
                in_array($ref->firstColumn(), ['project_id', 'created_by_user_id'], true)
                    ? [$ref->firstColumn()] : [],
                $ref->nullableColumns,
            );
            self::assertSame(
                $ref->firstColumn() === 'created_by_user_id' ? ['null', 'restore_actor'] : [],
                $ref->fallbacks,
            );
        }
    }

    public function testClientScopeAndNullActorNeedNoLookupOrFallback(): void
    {
        $row = self::row();
        $row['scope'] = 'client';
        $row['project_id'] = null;
        $row['created_by_user_id'] = null;
        $row['revoked_at'] = null;
        CompanyBackupWorkReportLinksProjection::validateRow($row);
        CompanyBackupWorkReportLinksProjection::validateToken(str_repeat('ab', 24));
        $refs = CompanyBackupReferenceSet::fromArray(
            CompanyBackupWorkReportLinksProjection::references(), 'table:work_report_links',
        );
        $visited = [];
        $mapped = $refs->remap($row, static function (CompanyBackupReference $ref, array $key) use (&$visited): array {
            $visited[] = $ref->firstColumn();
            self::assertIsInt($key[0]);
            return [$key[0] + 100];
        });
        self::assertSame(['client_id', 'supplier_id'], $visited);
        self::assertIsInt($row['client_id']);
        self::assertIsInt($row['supplier_id']);
        $row['client_id'] += 100;
        $row['supplier_id'] += 100;
        self::assertSame($row, $mapped);
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalidRows')]
    public function testRejectsInvalidScope(array $changes, string $code, string $column): void
    {
        $row = array_replace(self::row(), $changes);
        try {
            CompanyBackupWorkReportLinksProjection::validateRow($row);
            self::fail('Neplatný odkaz nesmí projít preflightem.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame('table:work_report_links', $e->registryKey);
            self::assertSame($column, $e->column);
        }
    }

    /** @return iterable<string,array{array<string,mixed>,string,string}> */
    public static function invalidRows(): iterable
    {
        yield 'unknown scope' => [['scope' => 'invoice'], 'work_report_link_scope_invalid', 'scope'];
        yield 'missing scope' => [['scope' => null], 'work_report_link_scope_invalid', 'scope'];
        yield 'client with project' => [
            ['scope' => 'client', 'project_id' => 13], 'work_report_link_scope_invalid', 'project_id',
        ];
        yield 'project without project' => [
            ['project_id' => null], 'work_report_link_scope_invalid', 'project_id',
        ];
        yield 'project with zero id' => [
            ['project_id' => 0], 'work_report_link_scope_invalid', 'project_id',
        ];
    }

    public function testRejectsMalformedTokenSeparatelyFromOrdinaryRow(): void
    {
        CompanyBackupWorkReportLinksProjection::validateRow(self::row());
        $this->expectException(CompanyBackupPreflightException::class);
        $this->expectExceptionMessage('work_report_link_token_invalid');
        CompanyBackupWorkReportLinksProjection::validateToken(str_repeat('A', 48));
    }
}
