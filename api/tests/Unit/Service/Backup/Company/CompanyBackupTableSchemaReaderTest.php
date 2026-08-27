<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableSchemaReader;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class CompanyBackupTableSchemaReaderTest extends TestCase
{
    public function testReadsCompositeForeignKeyInDeclaredColumnOrder(): void
    {
        $columns = $this->statement([
            ['COLUMN_NAME' => 'id', 'IS_NULLABLE' => 'NO'],
            ['COLUMN_NAME' => 'supplier_id', 'IS_NULLABLE' => 'NO'],
            ['COLUMN_NAME' => 'parent_id', 'IS_NULLABLE' => 'NO'],
        ]);
        $foreignKeys = $this->statement([
            [
                'CONSTRAINT_NAME' => 'fk_synthetic_parent',
                'COLUMN_NAME' => 'supplier_id',
                'ORDINAL_POSITION' => 1,
                'REFERENCED_TABLE_NAME' => 'synthetic_records',
                'REFERENCED_COLUMN_NAME' => 'supplier_id',
            ],
            [
                'CONSTRAINT_NAME' => 'fk_synthetic_parent',
                'COLUMN_NAME' => 'parent_id',
                'ORDINAL_POSITION' => 2,
                'REFERENCED_TABLE_NAME' => 'synthetic_records',
                'REFERENCED_COLUMN_NAME' => 'id',
            ],
        ]);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($columns, $foreignKeys);

        $projection = CompanyBackupTableProjection::fromDefinition(new TenantDataDefinition(
            'table:synthetic_records',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
                'secrets' => [],
                'company_backup' => [
                    'data_columns' => ['id', 'supplier_id', 'parent_id'],
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' => [[
                        'columns' => ['supplier_id', 'parent_id'],
                        'target' => 'table:synthetic_records',
                        'target_columns' => ['supplier_id', 'id'],
                        'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                        'constraint' => CompanyBackupReferenceConstraint::Required->value,
                        'nullable_columns' => [],
                        'fallbacks' => [],
                    ]],
                    'restore_overrides' => [],
                ],
            ],
        ));

        $schema = (new CompanyBackupTableSchemaReader())->readReferences($pdo, $projection);

        self::assertSame([], $schema->nullableColumns);
        self::assertCount(1, $schema->foreignKeys);
        self::assertSame(['supplier_id', 'parent_id'], $schema->foreignKeys[0]->columns);
        self::assertSame('synthetic_records', $schema->foreignKeys[0]->targetTable);
        self::assertSame(['supplier_id', 'id'], $schema->foreignKeys[0]->targetColumns);
        $projection->references->assertRuntimeSchema($schema);
    }

    /** @param list<array<string,mixed>> $rows */
    private function statement(array $rows): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with(['synthetic_records'])
            ->willReturn(true);
        $statement->expects(self::once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($rows);
        $statement->expects(self::once())->method('closeCursor')->willReturn(true);
        return $statement;
    }
}
