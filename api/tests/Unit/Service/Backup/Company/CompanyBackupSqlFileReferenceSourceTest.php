<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupFileSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlFileReferenceSource;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSqlFileReferenceSourceTest extends TestCase
{
    public function testReadsScalarAndJsonOwnersWithDirectTenantBoundaries(): void
    {
        $branding = $this->statement([[
            'id' => 11,
            '_file_source_path' =>
                'storage/supplier-logos/sup-7-brand-11-abcdef123456.png',
        ]]);
        $invoice = $this->statement([[
            'id' => 101,
            '_file_source_path' =>
                'storage/supplier-logos/sup-7-brand-11-abcdef123456.png',
        ]]);
        $supplier = $this->statement([[
            'id' => 7,
            '_file_source_path' => 'storage/supplier-logos/sup-7.png',
        ]]);
        $pdo = $this->createMock(PDO::class);
        $queries = [];
        $statements = [$branding, $invoice, $supplier];
        $pdo->expects(self::exactly(3))
            ->method('prepare')
            ->willReturnCallback(static function (string $sql) use (
                &$queries,
                &$statements,
            ): PDOStatement {
                $queries[] = $sql;
                $statement = array_shift($statements);
                if (!$statement instanceof PDOStatement) {
                    throw new \LogicException('Test nemá připravený SQL statement.');
                }
                return $statement;
            });
        $registry = $this->registry();
        $area = $registry->definition('file-area:supplier-logos');
        self::assertNotNull($area);

        $references = iterator_to_array(
            (new CompanyBackupSqlFileReferenceSource(batchSize: 100))->references(
                $pdo,
                7,
                $area,
                $registry,
            ),
        );

        self::assertSame(
            [
                'sup-7-brand-11-abcdef123456.png',
                'sup-7-brand-11-abcdef123456.png',
                'sup-7.png',
            ],
            array_column($references, 'sourcePath'),
        );
        self::assertSame(
            ['table:branding_profiles', 'table:invoices', 'table:supplier'],
            array_column($references, 'registryKey'),
        );
        self::assertSame([[], ['logo_path'], []], array_column($references, 'path'));
        self::assertSame(
            'SELECT `_company_source`.`id`,'
            . ' `_company_source`.`logo_path` AS `_file_source_path`'
            . ' FROM `branding_profiles` AS `_company_source`'
            . ' WHERE `_company_source`.`supplier_id` = ?'
            . ' AND `_company_source`.`logo_path` IS NOT NULL'
            . " AND `_company_source`.`logo_path` <> ''"
            . ' ORDER BY `_company_source`.`id` LIMIT 100 OFFSET 0',
            $queries[0],
        );
        self::assertStringContainsString(
            "JSON_UNQUOTE(JSON_EXTRACT(`_company_source`.`supplier_snapshot`, '$.logo_path'))",
            $queries[1],
        );
        self::assertStringContainsString('`_company_source`.`supplier_id` = ?', $queries[1]);
        self::assertStringContainsString('`_company_source`.`id` = ?', $queries[2]);
    }

    public function testRejectsStoredPathOutsideRegisteredPrefix(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())
            ->method('prepare')
            ->willReturn($this->statement([[
                'id' => 11,
                '_file_source_path' => 'storage/private/outside.png',
            ]]));
        $registry = $this->registry();
        $area = $registry->definition('file-area:supplier-logos');
        self::assertNotNull($area);

        try {
            iterator_to_array(
                (new CompanyBackupSqlFileReferenceSource())->references(
                    $pdo,
                    7,
                    $area,
                    $registry,
                ),
            );
            self::fail('DB cesta mimo registrovaný prefix nesmí přejít na filesystem.');
        } catch (CompanyBackupFileSourceException $e) {
            self::assertSame('file_reference_path_invalid', $e->errorCode);
            self::assertSame('file-area:supplier-logos', $e->registryKey);
        }
    }

    public function testRejectsLogoPathCarryingAnotherSupplierIdentity(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())
            ->method('prepare')
            ->willReturn($this->statement([[
                'id' => 11,
                '_file_source_path' => 'storage/supplier-logos/sup-999.png',
            ]]));
        $registry = $this->registry();
        $area = $registry->definition('file-area:supplier-logos');
        self::assertNotNull($area);

        try {
            iterator_to_array(
                (new CompanyBackupSqlFileReferenceSource())->references(
                    $pdo,
                    7,
                    $area,
                    $registry,
                ),
            );
            self::fail('Cizí supplier identita v basename nesmí projít do zálohy.');
        } catch (CompanyBackupFileSourceException $e) {
            self::assertSame('file_reference_tenant_mismatch', $e->errorCode);
            self::assertSame('sup-999.png', $e->sourcePath);
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function statement(array $rows): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with([7])
            ->willReturn(true);
        $statement->expects(self::once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($rows);
        $statement->expects(self::once())->method('closeCursor')->willReturn(true);
        return $statement;
    }

    private function registry(): TenantDataRegistry
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return new TenantDataRegistry(1, [
            $this->table(
                'branding_profiles',
                TenantDataPolicy::TenantOwned,
                ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
            ),
            $this->table(
                'invoices',
                TenantDataPolicy::TenantOwned,
                ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
            ),
            $this->table(
                'supplier',
                TenantDataPolicy::TenantRoot,
                ['strategy' => 'selected_supplier', 'column' => 'id'],
            ),
            new TenantDataDefinition(
                'file-area:supplier-logos',
                TenantDataObjectKind::FileArea,
                TenantDataPolicy::TenantOwned,
                [$profile],
                [
                    'file_policy' => 'historical_optional',
                    'ownership' => ['strategy' => 'database_references'],
                    'path_policy' => 'supplier_logo',
                    'storage_subdirectory' => 'supplier-logos',
                    'file_owners' => [
                        $this->owner('table:branding_profiles', 'logo_path'),
                        $this->owner(
                            'table:invoices',
                            'supplier_snapshot',
                            ['logo_path'],
                        ),
                        $this->owner('table:supplier', 'logo_path'),
                    ],
                ],
            ),
        ]);
    }

    /** @param array<string,mixed> $ownership */
    private function table(
        string $name,
        TenantDataPolicy $policy,
        array $ownership,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $name,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => $ownership,
            ],
        );
    }

    /**
     * @param list<string> $path
     * @return array<string,mixed>
     */
    private function owner(
        string $registryKey,
        string $column,
        array $path = [],
    ): array {
        return [
            'registry_key' => $registryKey,
            'column' => $column,
            'path' => $path,
            'stored_prefix' => 'storage/supplier-logos/',
        ];
    }
}
