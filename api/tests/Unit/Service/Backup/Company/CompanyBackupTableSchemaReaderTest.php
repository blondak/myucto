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
    public function testIgnoresImplicitSystemVersionedRowEndInPrimaryKey(): void
    {
        $columns = $this->statement([
            [
                'COLUMN_NAME' => 'id',
                'DATA_TYPE' => 'bigint',
                'EXTRA' => 'auto_increment',
                'GENERATION_EXPRESSION' => null,
                'TABLE_TYPE' => 'SYSTEM VERSIONED',
            ],
            [
                'COLUMN_NAME' => 'supplier_id',
                'DATA_TYPE' => 'int',
                'EXTRA' => '',
                'GENERATION_EXPRESSION' => null,
                'TABLE_TYPE' => 'SYSTEM VERSIONED',
            ],
            [
                'COLUMN_NAME' => 'digest',
                'DATA_TYPE' => 'binary',
                'EXTRA' => '',
                'GENERATION_EXPRESSION' => null,
                'TABLE_TYPE' => 'SYSTEM VERSIONED',
            ],
        ]);
        $primaryKey = $this->statement(['id', 'row_end'], PDO::FETCH_COLUMN);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($columns, $primaryKey);

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
                    'column_codecs' => ['digest' => 'binary_hex'],
                    'data_columns' => ['id', 'supplier_id', 'digest'],
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' => [[
                        'columns' => ['supplier_id'],
                        'target' => 'table:supplier',
                        'target_columns' => ['id'],
                        'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                        'constraint' => CompanyBackupReferenceConstraint::Required->value,
                        'nullable_columns' => [],
                        'fallbacks' => [],
                    ]],
                    'restore_overrides' => [],
                ],
            ],
        ));

        $schema = (new CompanyBackupTableSchemaReader())->read($pdo, $projection);

        self::assertSame(['id', 'supplier_id', 'digest'], $schema->columns);
        self::assertSame(['digest'], $schema->binaryColumns);
        self::assertSame([], $schema->generatedColumns);
        self::assertSame(['id'], $schema->primaryKey);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
    }

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

    public function testReadsBoundedAutoIncrementMetadataForImport(): void
    {
        $engine = $this->statement([[
            'ENGINE' => 'InnoDB',
        ]]);
        $autoIncrement = $this->statement([[
            'COLUMN_NAME' => 'id',
            'COLUMN_TYPE' => 'tinyint(3) unsigned',
            'DATA_TYPE' => 'tinyint',
        ]]);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($engine, $autoIncrement);

        $metadata = (new CompanyBackupTableSchemaReader())->readImportMetadata(
            $pdo,
            CompanyBackupTableProjection::fromDefinition(new TenantDataDefinition(
                'table:synthetic_records',
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantRoot,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
                [
                    'primary_key' => ['id'],
                    'ownership' => [
                        'strategy' => 'selected_supplier',
                        'column' => 'id',
                    ],
                    'secrets' => [],
                    'company_backup' => [
                        'data_columns' => ['id', 'name'],
                        'embedded_references' => [],
                        'generated_columns' => [],
                        'omit_columns' => [],
                        'references' => [],
                        'restore_overrides' => [],
                    ],
                ],
            )),
        );

        self::assertNotNull($metadata->autoIncrement);
        self::assertSame('id', $metadata->autoIncrement->column);
        self::assertSame(255, $metadata->autoIncrement->maximumValue);
    }

    /** @param list<mixed> $rows */
    private function statement(array $rows, int $fetchMode = PDO::FETCH_ASSOC): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with(['synthetic_records'])
            ->willReturn(true);
        $statement->expects(self::once())
            ->method('fetchAll')
            ->with($fetchMode)
            ->willReturn($rows);
        $statement->expects(self::once())->method('closeCursor')->willReturn(true);
        return $statement;
    }
}
