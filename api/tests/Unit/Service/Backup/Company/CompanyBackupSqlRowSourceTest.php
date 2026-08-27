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
                    'EXTRA' => 'auto_increment',
                    'GENERATION_EXPRESSION' => null,
                    'TABLE_TYPE' => 'BASE TABLE',
                ],
                [
                    'COLUMN_NAME' => 'supplier_id',
                    'EXTRA' => '',
                    'GENERATION_EXPRESSION' => null,
                    'TABLE_TYPE' => 'BASE TABLE',
                ],
                [
                    'COLUMN_NAME' => 'label',
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

    public function testRefusesProtectedSecretBeforeReadingBusinessRows(): void
    {
        $schema = $this->statement(
            [['synthetic_records']],
            [
                [
                    'COLUMN_NAME' => 'id',
                    'EXTRA' => 'auto_increment',
                    'GENERATION_EXPRESSION' => null,
                    'TABLE_TYPE' => 'BASE TABLE',
                ],
                [
                    'COLUMN_NAME' => 'supplier_id',
                    'EXTRA' => '',
                    'GENERATION_EXPRESSION' => null,
                    'TABLE_TYPE' => 'BASE TABLE',
                ],
                [
                    'COLUMN_NAME' => 'label',
                    'EXTRA' => '',
                    'GENERATION_EXPRESSION' => null,
                    'TABLE_TYPE' => 'BASE TABLE',
                ],
                [
                    'COLUMN_NAME' => 'protected_value_enc',
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
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($schema, $primaryKey);

        try {
            iterator_to_array((new CompanyBackupSqlRowSource())->rows(
                $pdo,
                7,
                $this->definition(protectedSecret: true),
            ));
            self::fail('Povinný chráněný secret se nesmí ztratit vynecháním z JSONL.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_secret_envelope_required', $e->errorCode);
            self::assertSame('protected_value_enc', $e->column);
        }
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

    private function definition(bool $protectedSecret = false): TenantDataDefinition
    {
        $secrets = $protectedSecret ? [
            'protected_value_enc' => [
                'policy' => TenantSecretPolicy::ProtectedDomainSecret->value,
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
                    'data_columns' => ['id', 'supplier_id', 'label'],
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
