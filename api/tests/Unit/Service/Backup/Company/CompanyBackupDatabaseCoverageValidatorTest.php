<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDatabaseCoverageValidator;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupSigningCredentialsProjection;
use MyInvoice\Service\Backup\Registry\IncompleteTenantDataRegistryCoverage;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class CompanyBackupDatabaseCoverageValidatorTest extends TestCase
{
    public function testCompleteExplicitDatabaseInventoryIsSafe(): void
    {
        $registry = $this->registry([
            $this->supplierDefinition(),
            $this->definition('migrations', TenantDataPolicy::InstanceOwned),
        ]);
        $pdo = $this->pdo([
            [[], ['migrations', 'supplier'], PDO::FETCH_COLUMN],
            [['supplier'], $this->supplierColumns(), PDO::FETCH_ASSOC],
            [['supplier'], ['id'], PDO::FETCH_COLUMN],
            [['supplier'], $this->supplierNullability(), PDO::FETCH_ASSOC],
            [['supplier'], [], PDO::FETCH_ASSOC],
        ]);

        $report = (new CompanyBackupDatabaseCoverageValidator())->evaluate($pdo, $registry);

        self::assertTrue($report->isSafe());
        self::assertSame([], $report->issues);
    }

    public function testReportsUnknownTableAndMissingProjectionTogether(): void
    {
        $registry = $this->registry([
            $this->definition('supplier', TenantDataPolicy::TenantRoot, [
                'primary_key' => ['id'],
                'ownership' => ['strategy' => 'selected_supplier', 'column' => 'id'],
                'secrets' => [],
            ]),
        ]);
        $pdo = $this->pdo([
            [[], ['new_agenda', 'supplier'], PDO::FETCH_COLUMN],
        ]);

        $report = (new CompanyBackupDatabaseCoverageValidator())->evaluate($pdo, $registry);

        self::assertSame(
            [
                ['object_unclassified', 'table:new_agenda'],
                ['data_projection_missing', 'table:supplier'],
            ],
            array_map(
                static fn ($issue): array => [$issue->code, $issue->object],
                $report->issues,
            ),
        );
    }

    public function testReportsUnclassifiedSecretLikeSchemaColumn(): void
    {
        $registry = $this->registry([$this->supplierDefinition()]);
        $columns = $this->supplierColumns();
        $columns[] = [
            'COLUMN_NAME' => 'webhook_token',
            'DATA_TYPE' => 'varchar',
            'EXTRA' => '',
            'GENERATION_EXPRESSION' => null,
            'TABLE_TYPE' => 'BASE TABLE',
        ];
        $pdo = $this->pdo([
            [[], ['supplier'], PDO::FETCH_COLUMN],
            [['supplier'], $columns, PDO::FETCH_ASSOC],
            [['supplier'], ['id'], PDO::FETCH_COLUMN],
        ]);

        $report = (new CompanyBackupDatabaseCoverageValidator())->evaluate($pdo, $registry);

        self::assertFalse($report->isSafe());
        self::assertSame('data_secret_column_unclassified', $report->issues[0]->code);
        self::assertSame('table:supplier', $report->issues[0]->object);
        self::assertStringContainsString('webhook_token', $report->issues[0]->message);
    }

    public function testAssertSafePreservesDeterministicCoverageReport(): void
    {
        $registry = $this->registry([$this->supplierDefinition()]);
        $pdo = $this->pdo([
            [[], ['supplier', 'unknown_table'], PDO::FETCH_COLUMN],
            [['supplier'], $this->supplierColumns(), PDO::FETCH_ASSOC],
            [['supplier'], ['id'], PDO::FETCH_COLUMN],
            [['supplier'], $this->supplierNullability(), PDO::FETCH_ASSOC],
            [['supplier'], [], PDO::FETCH_ASSOC],
        ]);

        try {
            (new CompanyBackupDatabaseCoverageValidator())->assertSafe($pdo, $registry);
            self::fail('Neznámá DB tabulka musí zastavit company backup coverage bránu.');
        } catch (IncompleteTenantDataRegistryCoverage $e) {
            self::assertSame('object_unclassified', $e->report->issues[0]->code);
            self::assertSame('table:unknown_table', $e->report->issues[0]->object);
        }
    }

    public function testReportsMissingRequiredReferenceConstraint(): void
    {
        $registry = $this->registry([$this->definition(
            'synthetic_records',
            TenantDataPolicy::TenantRoot,
            [
                'primary_key' => ['id'],
                'ownership' => ['strategy' => 'selected_supplier', 'column' => 'id'],
                'secrets' => [],
                'company_backup' => [
                    'data_columns' => ['id', 'parent_id'],
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' => [[
                        'columns' => ['parent_id'],
                        'target' => 'table:synthetic_records',
                        'target_columns' => ['id'],
                        'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                        'constraint' => CompanyBackupReferenceConstraint::Required->value,
                        'nullable_columns' => ['parent_id'],
                        'fallbacks' => [],
                    ]],
                    'restore_overrides' => [],
                ],
            ],
        )]);
        $pdo = $this->pdo([
            [[], ['synthetic_records'], PDO::FETCH_COLUMN],
            [['synthetic_records'], [
                [
                    'COLUMN_NAME' => 'id',
                    'DATA_TYPE' => 'bigint',
                    'EXTRA' => 'auto_increment',
                    'GENERATION_EXPRESSION' => null,
                    'TABLE_TYPE' => 'BASE TABLE',
                ],
                [
                    'COLUMN_NAME' => 'parent_id',
                    'DATA_TYPE' => 'bigint',
                    'EXTRA' => '',
                    'GENERATION_EXPRESSION' => null,
                    'TABLE_TYPE' => 'BASE TABLE',
                ],
            ], PDO::FETCH_ASSOC],
            [['synthetic_records'], ['id'], PDO::FETCH_COLUMN],
            [['synthetic_records'], [
                ['COLUMN_NAME' => 'id', 'IS_NULLABLE' => 'NO'],
                ['COLUMN_NAME' => 'parent_id', 'IS_NULLABLE' => 'YES'],
            ], PDO::FETCH_ASSOC],
            [['synthetic_records'], [], PDO::FETCH_ASSOC],
        ]);

        $report = (new CompanyBackupDatabaseCoverageValidator())->evaluate($pdo, $registry);

        self::assertFalse($report->isSafe());
        self::assertSame('data_reference_constraint_missing', $report->issues[0]->code);
        self::assertStringContainsString('parent_id', $report->issues[0]->message);
    }

    public function testOptionalCredentialStillRequiresExactRuntimeSchema(): void
    {
        $registry = $this->registry([
            new TenantDataDefinition(
                'table:signing_credentials',
                TenantDataObjectKind::Table,
                TenantDataPolicy::OptionalCredential,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
                [
                    'primary_key' => ['id'],
                    'ownership' =>
                        CompanyBackupSigningCredentialsProjection::ownership(),
                    'company_backup_credential' =>
                        CompanyBackupSigningCredentialsProjection::metadata(),
                ],
            ),
            $this->definition('signing_profiles', TenantDataPolicy::TenantOwned, [
                'primary_key' => ['id'],
            ]),
            $this->definition(
                'epo_signing_credentials',
                TenantDataPolicy::PersonalSecretAttachment,
                ['primary_key' => ['id']],
            ),
            $this->definition('users', TenantDataPolicy::InstanceOwned, [
                'primary_key' => ['id'],
            ]),
        ]);
        $columns = array_map(
            static fn (string $column): array => [
                'COLUMN_NAME' => $column,
                'DATA_TYPE' => $column === 'id' ? 'bigint' : 'varchar',
                'EXTRA' => $column === 'id' ? 'auto_increment' : '',
                'GENERATION_EXPRESSION' => null,
                'TABLE_TYPE' => 'BASE TABLE',
            ],
            CompanyBackupSigningCredentialsProjection::columns(),
        );
        $columns[] = [
            'COLUMN_NAME' => 'future_secret',
            'DATA_TYPE' => 'varchar',
            'EXTRA' => '',
            'GENERATION_EXPRESSION' => null,
            'TABLE_TYPE' => 'BASE TABLE',
        ];
        $nullable = array_fill_keys(
            CompanyBackupSigningCredentialsProjection::nullableColumns(),
            true,
        );
        $nullability = array_map(
            static fn (string $column): array => [
                'COLUMN_NAME' => $column,
                'IS_NULLABLE' => isset($nullable[$column]) ? 'YES' : 'NO',
            ],
            [...CompanyBackupSigningCredentialsProjection::columns(), 'future_secret'],
        );
        $pdo = $this->pdo([
            [[], ['signing_credentials'], PDO::FETCH_COLUMN],
            [['signing_credentials'], $columns, PDO::FETCH_ASSOC],
            [['signing_credentials'], ['id'], PDO::FETCH_COLUMN],
            [['signing_credentials'], $nullability, PDO::FETCH_ASSOC],
            [['signing_credentials'], [
                $this->foreignKeyRow('fk_created_by', 'created_by', 'users'),
                $this->foreignKeyRow(
                    'fk_profile',
                    'profile_id',
                    'signing_profiles',
                ),
                $this->foreignKeyRow(
                    'fk_vault',
                    'vault_credential_id',
                    'epo_signing_credentials',
                ),
            ], PDO::FETCH_ASSOC],
        ]);

        $report = (new CompanyBackupDatabaseCoverageValidator())->evaluate(
            $pdo,
            $registry,
        );

        self::assertContains(
            'credential_schema_columns_mismatch',
            array_column($report->toArray()['issues'], 'code'),
            'Non-payload credential nesmí obejít DB schema coverage.',
        );
    }

    /**
     * @param list<array{0:list<mixed>,1:list<mixed>,2:int}> $responses
     */
    private function pdo(array $responses): PDO
    {
        $statements = array_map(
            fn (array $response): PDOStatement => $this->statement(...$response),
            $responses,
        );
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(count($statements)))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls(...$statements);
        return $pdo;
    }

    /**
     * @param list<mixed> $params
     * @param list<mixed> $rows
     */
    private function statement(array $params, array $rows, int $fetchMode): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with($params)
            ->willReturn(true);
        $statement->expects(self::once())
            ->method('fetchAll')
            ->with($fetchMode)
            ->willReturn($rows);
        $statement->expects(self::once())->method('closeCursor')->willReturn(true);
        return $statement;
    }

    /** @return array<string,int|string> */
    private function foreignKeyRow(
        string $constraint,
        string $column,
        string $target,
    ): array {
        return [
            'CONSTRAINT_NAME' => $constraint,
            'COLUMN_NAME' => $column,
            'ORDINAL_POSITION' => 1,
            'REFERENCED_TABLE_NAME' => $target,
            'REFERENCED_COLUMN_NAME' => 'id',
        ];
    }

    /** @return list<array<string,mixed>> */
    private function supplierColumns(): array
    {
        return [
            [
                'COLUMN_NAME' => 'id',
                'DATA_TYPE' => 'int',
                'EXTRA' => 'auto_increment',
                'GENERATION_EXPRESSION' => null,
                'TABLE_TYPE' => 'BASE TABLE',
            ],
            [
                'COLUMN_NAME' => 'company_name',
                'DATA_TYPE' => 'varchar',
                'EXTRA' => '',
                'GENERATION_EXPRESSION' => null,
                'TABLE_TYPE' => 'BASE TABLE',
            ],
        ];
    }

    /** @return list<array{COLUMN_NAME:string,IS_NULLABLE:string}> */
    private function supplierNullability(): array
    {
        return [
            ['COLUMN_NAME' => 'id', 'IS_NULLABLE' => 'NO'],
            ['COLUMN_NAME' => 'company_name', 'IS_NULLABLE' => 'NO'],
        ];
    }

    private function supplierDefinition(): TenantDataDefinition
    {
        return $this->definition('supplier', TenantDataPolicy::TenantRoot, [
            'primary_key' => ['id'],
            'ownership' => ['strategy' => 'selected_supplier', 'column' => 'id'],
            'secrets' => [],
            'company_backup' => [
                'data_columns' => ['id', 'company_name'],
                'embedded_references' => [],
                'generated_columns' => [],
                'omit_columns' => [],
                'references' => [],
                'restore_overrides' => [],
            ],
        ]);
    }

    /** @param list<TenantDataDefinition> $definitions */
    private function registry(array $definitions): TenantDataRegistry
    {
        return new TenantDataRegistry(
            1,
            $definitions,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
        );
    }

    /** @param array<string,mixed> $details */
    private function definition(
        string $table,
        TenantDataPolicy $policy,
        array $details = [],
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            $details,
        );
    }
}
