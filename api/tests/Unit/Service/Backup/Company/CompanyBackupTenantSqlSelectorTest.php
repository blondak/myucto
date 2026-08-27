<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTenantSqlSelector;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupTenantSqlSelectorTest extends TestCase
{
    public function testBuildsDirectTenantRootAndOwnedSelections(): void
    {
        $selector = new CompanyBackupTenantSqlSelector();

        $root = $selector->select($this->projection(
            'supplier',
            TenantDataPolicy::TenantRoot,
            ['strategy' => 'selected_supplier', 'column' => 'id'],
            ['id', 'company_name'],
        ), 7);
        $owned = $selector->select($this->projection(
            'invoices',
            TenantDataPolicy::TenantOwned,
            ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
            ['id', 'supplier_id'],
        ), 7);

        self::assertSame('`_company_source`.`id` = ?', $root->where);
        self::assertSame([7], $root->params);
        self::assertSame('`_company_source`.`supplier_id` = ?', $owned->where);
        self::assertSame([7], $owned->params);
    }

    public function testBuildsValidatedIndirectOwnershipPath(): void
    {
        $selection = (new CompanyBackupTenantSqlSelector())->select($this->projection(
            'invoice_items',
            TenantDataPolicy::TenantOwnedIndirect,
            [
                'strategy' => 'foreign_key_path',
                'path' => [
                    [
                        'from_column' => 'invoice_id',
                        'to_table' => 'invoices',
                        'to_column' => 'id',
                    ],
                    [
                        'from_column' => 'supplier_id',
                        'to_table' => 'supplier',
                        'to_column' => 'id',
                    ],
                ],
            ],
            ['id', 'invoice_id'],
        ), 11);

        self::assertSame(
            'EXISTS (SELECT 1 FROM `invoices` AS `_tenant_path_0`'
            . ' JOIN `supplier` AS `_tenant_path_1`'
            . ' ON `_tenant_path_0`.`supplier_id` = `_tenant_path_1`.`id`'
            . ' WHERE `_company_source`.`invoice_id` = `_tenant_path_0`.`id`'
            . ' AND `_tenant_path_1`.`id` = ?)',
            $selection->where,
        );
        self::assertSame([11], $selection->params);
    }

    public function testBuildsExplicitRelationshipAndGlobalReferenceSelections(): void
    {
        $selector = new CompanyBackupTenantSqlSelector();
        $transactions = $selector->select($this->projection(
            'bank_transactions',
            TenantDataPolicy::TenantOwnedIndirect,
            ['strategy' => 'bank_transaction_relationships'],
            ['id', 'matched_invoice_id'],
        ), 13);
        $statements = $selector->select($this->projection(
            'bank_statements',
            TenantDataPolicy::GlobalReference,
            ['strategy' => 'bank_statement_relationships'],
            ['id'],
        ), 13);
        $rates = $selector->select($this->projection(
            'exchange_rates',
            TenantDataPolicy::GlobalReference,
            ['strategy' => 'accounting_period_currency'],
            ['rate_date', 'currency_code'],
            ['rate_date', 'currency_code'],
        ), 13);

        self::assertStringContainsString('`payment_matches`', $transactions->where);
        self::assertSame([13, 13, 13], $transactions->params);
        self::assertStringContainsString('`bank_transactions` AS `_related_transaction`', $statements->where);
        self::assertSame([13, 13, 13], $statements->params);
        self::assertStringContainsString('MIN(`starts_on`)', $rates->where);
        self::assertStringContainsString('MAX(`ends_on`)', $rates->where);
        self::assertSame([13, 13, 13], $rates->params);
    }

    public function testRejectsPolicyAndStrategyCombinationOutsideAllowlist(): void
    {
        $projection = $this->projection(
            'supplier',
            TenantDataPolicy::TenantRoot,
            ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
            ['id', 'supplier_id'],
        );

        try {
            (new CompanyBackupTenantSqlSelector())->select($projection, 7);
            self::fail('Policy a ownership strategie se nesmějí libovolně kombinovat.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame('data_ownership_unsupported', $e->errorCode);
            self::assertSame('table:supplier', $e->registryKey);
        }
    }

    public function testRejectsMisleadingDirectColumnAndRootTable(): void
    {
        $selector = new CompanyBackupTenantSqlSelector();
        $wrongColumn = $this->projection(
            'clients',
            TenantDataPolicy::TenantOwned,
            ['strategy' => 'supplier_id', 'column' => 'id'],
            ['id', 'supplier_id'],
        );
        $wrongRoot = $this->projection(
            'clients',
            TenantDataPolicy::TenantRoot,
            ['strategy' => 'selected_supplier', 'column' => 'id'],
            ['id'],
        );

        foreach ([$wrongColumn, $wrongRoot] as $projection) {
            try {
                $selector->select($projection, 7);
                self::fail('Ownership metadata nesmějí přesměrovat tenantový filtr.');
            } catch (CompanyBackupDataSourceException $e) {
                self::assertContains(
                    $e->errorCode,
                    ['data_ownership_invalid', 'data_ownership_unsupported'],
                );
            }
        }
    }

    public function testProductionPostingRulesExcludeGlobalSeedRows(): void
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:posting_rules');
        self::assertNotNull($definition);
        $selection = (new CompanyBackupTenantSqlSelector())->select(
            CompanyBackupTableProjection::fromDefinition($definition),
            19,
        );

        self::assertSame('`_company_source`.`supplier_id` = ?', $selection->where);
        self::assertSame([19], $selection->params);
    }

    /**
     * @param array<string,mixed> $ownership
     * @param list<string> $dataColumns
     * @param list<string> $primaryKey
     */
    private function projection(
        string $table,
        TenantDataPolicy $policy,
        array $ownership,
        array $dataColumns,
        array $primaryKey = ['id'],
    ): CompanyBackupTableProjection {
        return CompanyBackupTableProjection::fromDefinition(new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => $primaryKey,
                'ownership' => $ownership,
                'secrets' => [],
                'company_backup' => [
                    'data_columns' => $dataColumns,
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' => $this->references($dataColumns),
                ],
            ],
        ));
    }

    /**
     * @param list<string> $dataColumns
     * @return list<array<string,mixed>>
     */
    private function references(array $dataColumns): array
    {
        $references = [];
        foreach ($dataColumns as $column) {
            if ($column === 'id'
                || !str_ends_with($column, '_id') && !str_ends_with($column, '_by')
            ) {
                continue;
            }
            $references[] = [
                'columns' => [$column],
                'target' => match ($column) {
                    'invoice_id', 'matched_invoice_id' => 'table:invoices',
                    default => 'table:supplier',
                },
                'target_columns' => ['id'],
                'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                'constraint' => CompanyBackupReferenceConstraint::Optional->value,
                'nullable_columns' => [],
                'fallbacks' => [],
            ];
        }
        usort(
            $references,
            static fn (array $left, array $right): int => strcmp(
                (string) $left['columns'][0],
                (string) $right['columns'][0],
            ),
        );
        return $references;
    }
}
