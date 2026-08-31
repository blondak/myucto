<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlRowSource;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSqlRowSourceTest extends TestCase
{
    public function testStreamsBoundedPagesWithExplicitColumnsAndStableOrder(): void
    {
        $schema = $this->statement(
            [['synthetic_records']],
            [
                [
                    'COLUMN_NAME' => 'id',
                    'DATA_TYPE' => 'bigint',
                    'EXTRA' => 'auto_increment',
                    'GENERATION_EXPRESSION' => null,
                    'TABLE_TYPE' => 'BASE TABLE',
                ],
                [
                    'COLUMN_NAME' => 'supplier_id',
                    'DATA_TYPE' => 'int',
                    'EXTRA' => '',
                    'GENERATION_EXPRESSION' => null,
                    'TABLE_TYPE' => 'BASE TABLE',
                ],
                [
                    'COLUMN_NAME' => 'label',
                    'DATA_TYPE' => 'varchar',
                    'EXTRA' => '',
                    'GENERATION_EXPRESSION' => null,
                    'TABLE_TYPE' => 'BASE TABLE',
                ],
            ],
            PDO::FETCH_ASSOC,
        );
        $primaryKey = $this->statement(
            [['synthetic_records']],
            ['id'],
            PDO::FETCH_COLUMN,
        );
        $firstPage = $this->statement(
            [[7]],
            [
                ['id' => 1, 'supplier_id' => 7, 'label' => 'A'],
                ['id' => 2, 'supplier_id' => 7, 'label' => 'B'],
            ],
            PDO::FETCH_ASSOC,
        );
        $secondPage = $this->statement(
            [[7]],
            [['id' => 3, 'supplier_id' => 7, 'label' => 'C']],
            PDO::FETCH_ASSOC,
        );
        $pdo = $this->createMock(PDO::class);
        $queries = [];
        $statements = [$schema, $primaryKey, $firstPage, $secondPage];
        $pdo->expects(self::exactly(4))
            ->method('prepare')
            ->willReturnCallback(static function (string $sql) use (&$queries, &$statements): PDOStatement {
                $queries[] = $sql;
                $statement = array_shift($statements);
                if (!$statement instanceof PDOStatement) {
                    throw new \LogicException('Test nemá připravený další statement.');
                }
                return $statement;
            });

        $rows = iterator_to_array((new CompanyBackupSqlRowSource(batchSize: 2))->rows(
            $pdo,
            7,
            $this->definition(),
        ));

        self::assertSame([
            ['id' => 1, 'supplier_id' => 7, 'label' => 'A'],
            ['id' => 2, 'supplier_id' => 7, 'label' => 'B'],
            ['id' => 3, 'supplier_id' => 7, 'label' => 'C'],
        ], $rows);
        self::assertStringContainsString('`information_schema`.`COLUMNS`', $queries[0]);
        self::assertStringContainsString('`information_schema`.`KEY_COLUMN_USAGE`', $queries[1]);
        self::assertSame(
            'SELECT `_company_source`.`id`, `_company_source`.`supplier_id`,'
            . ' `_company_source`.`label` FROM `synthetic_records` AS `_company_source`'
            . ' WHERE `_company_source`.`supplier_id` = ?'
            . ' ORDER BY `_company_source`.`id` LIMIT 2 OFFSET 0',
            $queries[2],
        );
        self::assertStringEndsWith('LIMIT 2 OFFSET 2', $queries[3]);
    }

    public function testEncodesBinaryColumnAsLowercaseHexBeforeYielding(): void
    {
        $schema = $this->statement(
            [['synthetic_records']],
            [
                [
                    'COLUMN_NAME' => 'id',
                    'DATA_TYPE' => 'bigint',
                    'EXTRA' => 'auto_increment',
                    'GENERATION_EXPRESSION' => null,
                    'TABLE_TYPE' => 'BASE TABLE',
                ],
                [
                    'COLUMN_NAME' => 'supplier_id',
                    'DATA_TYPE' => 'int',
                    'EXTRA' => '',
                    'GENERATION_EXPRESSION' => null,
                    'TABLE_TYPE' => 'BASE TABLE',
                ],
                [
                    'COLUMN_NAME' => 'label',
                    'DATA_TYPE' => 'binary',
                    'EXTRA' => '',
                    'GENERATION_EXPRESSION' => null,
                    'TABLE_TYPE' => 'BASE TABLE',
                ],
            ],
            PDO::FETCH_ASSOC,
        );
        $primaryKey = $this->statement(
            [['synthetic_records']],
            ['id'],
            PDO::FETCH_COLUMN,
        );
        $page = $this->statement(
            [[7]],
            [['id' => 1, 'supplier_id' => 7, 'label' => "\xB1\x31"]],
            PDO::FETCH_ASSOC,
        );
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(3))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($schema, $primaryKey, $page);

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $pdo,
            7,
            $this->definition(columnCodecs: ['label' => 'binary_hex']),
        ));

        self::assertSame(
            [['id' => 1, 'supplier_id' => 7, 'label' => 'b131']],
            $rows,
        );
    }

    public function testEncodesBinaryDerivedHashBeforeIntegrityValidation(): void
    {
        $schemaRows = [];
        foreach (
            [
                'id' => 'bigint',
                'supplier_id' => 'int',
                'payload_json' => 'longtext',
                'payload_hash' => 'binary',
            ] as $column => $type
        ) {
            $schemaRows[] = [
                'COLUMN_NAME' => $column,
                'DATA_TYPE' => $type,
                'EXTRA' => $column === 'id' ? 'auto_increment' : '',
                'GENERATION_EXPRESSION' => null,
                'TABLE_TYPE' => 'BASE TABLE',
            ];
        }
        $schema = $this->statement(
            [['synthetic_records']],
            $schemaRows,
            PDO::FETCH_ASSOC,
        );
        $primaryKey = $this->statement(
            [['synthetic_records']],
            ['id'],
            PDO::FETCH_COLUMN,
        );
        $json = '{"employee_id":17}';
        $page = $this->statement(
            [[7]],
            [[
                'id' => 1,
                'supplier_id' => 7,
                'payload_json' => $json,
                'payload_hash' => hash('sha256', $json, true),
            ]],
            PDO::FETCH_ASSOC,
        );
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(3))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($schema, $primaryKey, $page);

        $rows = iterator_to_array((new CompanyBackupSqlRowSource())->rows(
            $pdo,
            7,
            $this->derivedHashDefinition(binary: true),
        ));

        self::assertSame(hash('sha256', $json), $rows[0]['payload_hash']);
    }

    public function testStreamsOnlyNonSecretColumnsAfterEnvelopeIsOrchestrated(): void
    {
        $schema = $this->statement(
            [['synthetic_records']],
            [
                [
                    'COLUMN_NAME' => 'id',
                    'DATA_TYPE' => 'bigint',
                    'EXTRA' => 'auto_increment',
                    'GENERATION_EXPRESSION' => null,
                    'TABLE_TYPE' => 'BASE TABLE',
                ],
                [
                    'COLUMN_NAME' => 'supplier_id',
                    'DATA_TYPE' => 'int',
                    'EXTRA' => '',
                    'GENERATION_EXPRESSION' => null,
                    'TABLE_TYPE' => 'BASE TABLE',
                ],
                [
                    'COLUMN_NAME' => 'label',
                    'DATA_TYPE' => 'varchar',
                    'EXTRA' => '',
                    'GENERATION_EXPRESSION' => null,
                    'TABLE_TYPE' => 'BASE TABLE',
                ],
                [
                    'COLUMN_NAME' => 'protected_value_enc',
                    'DATA_TYPE' => 'longtext',
                    'EXTRA' => '',
                    'GENERATION_EXPRESSION' => null,
                    'TABLE_TYPE' => 'BASE TABLE',
                ],
            ],
            PDO::FETCH_ASSOC,
        );
        $primaryKey = $this->statement(
            [['synthetic_records']],
            ['id'],
            PDO::FETCH_COLUMN,
        );
        $page = $this->statement(
            [[7]],
            [['id' => 1, 'supplier_id' => 7, 'label' => 'Synthetic']],
            PDO::FETCH_ASSOC,
        );
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(3))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($schema, $primaryKey, $page);

        self::assertSame(
            [['id' => 1, 'supplier_id' => 7, 'label' => 'Synthetic']],
            iterator_to_array((new CompanyBackupSqlRowSource())->rows(
                $pdo,
                7,
                $this->definition(protectedSecret: true),
            )),
        );
    }

    public function testRejectsTamperedDerivedHashBeforeYieldingRow(): void
    {
        $schemaRows = [];
        foreach (
            [
                'id' => 'bigint',
                'supplier_id' => 'int',
                'payload_json' => 'longtext',
                'payload_hash' => 'char',
            ] as $column => $type
        ) {
            $schemaRows[] = [
                'COLUMN_NAME' => $column,
                'DATA_TYPE' => $type,
                'EXTRA' => $column === 'id' ? 'auto_increment' : '',
                'GENERATION_EXPRESSION' => null,
                'TABLE_TYPE' => 'BASE TABLE',
            ];
        }
        $schema = $this->statement(
            [['synthetic_records']],
            $schemaRows,
            PDO::FETCH_ASSOC,
        );
        $primaryKey = $this->statement(
            [['synthetic_records']],
            ['id'],
            PDO::FETCH_COLUMN,
        );
        $page = $this->statement(
            [[7]],
            [[
                'id' => 1,
                'supplier_id' => 7,
                'payload_json' => '{"employee_id":17}',
                'payload_hash' => str_repeat('0', 64),
            ]],
            PDO::FETCH_ASSOC,
        );
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(3))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($schema, $primaryKey, $page);

        $definition = $this->derivedHashDefinition(binary: false);

        try {
            iterator_to_array((new CompanyBackupSqlRowSource())->rows(
                $pdo,
                7,
                $definition,
            ));
            self::fail('Export nesmí vydat JSON s neplatnou odvozenou pečetí.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_derived_hash_value_invalid', $e->errorCode);
            self::assertSame('payload_hash', $e->column);
        }
    }

    private function derivedHashDefinition(bool $binary): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:synthetic_records',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                'company_backup' => [
                    ...($binary ? [
                        'column_codecs' => ['payload_hash' => 'binary_hex'],
                    ] : []),
                    'data_columns' => [
                        'id',
                        'supplier_id',
                        'payload_json',
                        'payload_hash',
                    ],
                    'derived_hashes' => [[
                        'algorithm' => 'sha256_canonical_json',
                        'hash_column' => 'payload_hash',
                        'nullable' => false,
                        'source_column' => 'payload_json',
                    ]],
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
        );
    }

    /**
     * @param list<list<mixed>> $executions
     * @param list<mixed> $rows
     */
    private function statement(array $executions, array $rows, int $fetchMode): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $matcher = self::exactly(count($executions));
        $statement->expects($matcher)
            ->method('execute')
            ->willReturnCallback(static function (?array $params = null) use ($matcher, $executions): bool {
                self::assertSame($executions[$matcher->numberOfInvocations() - 1], $params);
                return true;
            });
        $statement->expects(self::once())
            ->method('fetchAll')
            ->with($fetchMode)
            ->willReturn($rows);
        $statement->expects(self::once())->method('closeCursor')->willReturn(true);
        return $statement;
    }

    /** @param array<string,string> $columnCodecs */
    private function definition(
        bool $protectedSecret = false,
        array $columnCodecs = [],
    ): TenantDataDefinition
    {
        $secrets = $protectedSecret ? [
            'protected_value_enc' => [
                'policy' => TenantSecretPolicy::ProtectedDomainSecret->value,
                'storage' => 'application_encrypted',
            ],
        ] : [];
        return new TenantDataDefinition(
            'table:synthetic_records',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => $secrets,
                'company_backup' => [
                    ...($columnCodecs === [] ? [] : [
                        'column_codecs' => $columnCodecs,
                    ]),
                    'data_columns' => ['id', 'supplier_id', 'label'],
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
        );
    }
}
