<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaForeignKeyInventory;
use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;
use MyInvoice\Service\TenantTransfer\Registry\IncompleteTenantDataRegistryCoverage;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistryCoverageValidator;
use PHPUnit\Framework\TestCase;

final class TenantDataRegistryCoverageValidatorTest extends TestCase
{
    public function testExactTableAndSecretCoveragePasses(): void
    {
        $registry = $this->registry([
            $this->definition('supplier', [
                'client_secret_enc' => ['policy' => 'reencrypt_v1'],
                'access_token_expires_at' => [
                    'policy' => 'not_secret',
                    'reason' => 'expiry_timestamp_only',
                ],
            ]),
        ]);

        (new TenantDataRegistryCoverageValidator())->assertComplete($registry, [
            new TenantSchemaTableInventory(
                'supplier',
                'BASE TABLE',
                ['id', 'client_secret_enc', 'access_token_expires_at'],
                ['id'],
                [],
            ),
        ]);

        self::addToAssertionCount(1);
    }

    public function testUnregisteredAndRemovedTablesFailClosedWithStableIssues(): void
    {
        $registry = $this->registry([
            $this->definition('supplier'),
            $this->definition('removed_table'),
        ]);

        try {
            (new TenantDataRegistryCoverageValidator())->assertComplete($registry, [
                new TenantSchemaTableInventory('supplier', 'BASE TABLE', ['id'], ['id'], []),
                new TenantSchemaTableInventory('new_table', 'BASE TABLE', ['id'], ['id'], []),
            ]);
            self::fail('Schema drift nesmí projít úplným tenantovým registrem.');
        } catch (IncompleteTenantDataRegistryCoverage $exception) {
            self::assertSame([
                'registered_table_missing:removed_table',
                'unregistered_table:new_table',
            ], $exception->issues);
        }
    }

    public function testEverySecretLikeColumnNeedsAnExplicitAllowedPolicy(): void
    {
        $registry = $this->registry([
            $this->definition('supplier', [
                'ghost_secret' => ['policy' => 'reencrypt_v1'],
                'api_token' => ['policy' => 'copy_plaintext'],
                'credential_id' => ['policy' => 'not_secret'],
            ]),
        ]);

        try {
            (new TenantDataRegistryCoverageValidator())->assertComplete($registry, [
                new TenantSchemaTableInventory(
                    'supplier',
                    'BASE TABLE',
                    ['id', 'api_token', 'password_hash', 'credential_id'],
                    ['id'],
                    [],
                ),
            ]);
            self::fail('Neúplná nebo neplatná secret politika nesmí projít.');
        } catch (IncompleteTenantDataRegistryCoverage $exception) {
            self::assertSame([
                'invalid_secret_policy:supplier.api_token',
                'missing_not_secret_reason:supplier.credential_id',
                'secret_policy_missing:supplier.password_hash',
                'secret_policy_unknown_column:supplier.ghost_secret',
            ], $exception->issues);
        }
    }

    public function testDraftRegistryCanReportGapsWithoutPretendingCompleteness(): void
    {
        $draft = new TenantDataRegistry(1, [$this->definition('supplier')]);

        self::assertSame(
            ['unregistered_table:invoices'],
            (new TenantDataRegistryCoverageValidator())->issues($draft, [
                new TenantSchemaTableInventory('supplier', 'BASE TABLE', ['id'], ['id'], []),
                new TenantSchemaTableInventory('invoices', 'BASE TABLE', ['id'], ['id'], []),
            ]),
        );
    }

    public function testOwnershipSelectorsAreCheckedAgainstActualColumns(): void
    {
        $registry = $this->registry([
            $this->definition('supplier'),
            $this->definition('invoices'),
        ]);

        self::assertSame(
            ['ownership_column_missing:invoices.supplier_id'],
            (new TenantDataRegistryCoverageValidator())->issues($registry, [
                new TenantSchemaTableInventory('supplier', 'BASE TABLE', ['id'], ['id'], []),
                new TenantSchemaTableInventory('invoices', 'BASE TABLE', ['id'], ['id'], []),
            ]),
        );
    }

    public function testDeclaredPrimaryKeyMustMatchSchemaOrderExactly(): void
    {
        self::assertSame(
            ['primary_key_mismatch:supplier'],
            (new TenantDataRegistryCoverageValidator())->issues(
                $this->registry([$this->definition('supplier')]),
                [new TenantSchemaTableInventory(
                    'supplier',
                    'BASE TABLE',
                    ['id'],
                    [],
                    [],
                )],
            ),
        );
    }

    public function testIndirectOwnershipPathMustReachSupplierRoot(): void
    {
        $indirect = new TenantDataDefinition(
            'table:invoice_items',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwnedIndirect,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
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
            ],
        );
        $registry = $this->registry([
            $this->definition('supplier'),
            $this->definition('invoices'),
            $indirect,
        ]);

        (new TenantDataRegistryCoverageValidator())->assertComplete($registry, [
            new TenantSchemaTableInventory('supplier', 'BASE TABLE', ['id'], ['id'], []),
            new TenantSchemaTableInventory(
                'invoices',
                'BASE TABLE',
                ['id', 'supplier_id'],
                ['id'],
                [new TenantSchemaForeignKeyInventory(
                    'supplier_id',
                    'supplier',
                    'id',
                )],
            ),
            new TenantSchemaTableInventory(
                'invoice_items',
                'BASE TABLE',
                ['id', 'invoice_id'],
                ['id'],
                [new TenantSchemaForeignKeyInventory(
                    'invoice_id',
                    'invoices',
                    'id',
                )],
            ),
        ]);

        self::assertSame(
            ['ownership_path_fk_missing:invoice_items.invoice_id->invoices.id'],
            (new TenantDataRegistryCoverageValidator())->issues($registry, [
                new TenantSchemaTableInventory(
                    'supplier',
                    'BASE TABLE',
                    ['id'],
                    ['id'],
                    [],
                ),
                new TenantSchemaTableInventory(
                    'invoices',
                    'BASE TABLE',
                    ['id', 'supplier_id'],
                    ['id'],
                    [new TenantSchemaForeignKeyInventory(
                        'supplier_id',
                        'supplier',
                        'id',
                    )],
                ),
                new TenantSchemaTableInventory(
                    'invoice_items',
                    'BASE TABLE',
                    ['id', 'invoice_id'],
                    ['id'],
                    [],
                ),
            ]),
        );
    }

    public function testExcludedAndGlobalPoliciesRequireExplicitReasonsOrKeys(): void
    {
        $instance = new TenantDataDefinition(
            'table:sessions',
            TenantDataObjectKind::Table,
            TenantDataPolicy::InstanceOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            ['primary_key' => ['id']],
        );
        $global = new TenantDataDefinition(
            'table:countries',
            TenantDataObjectKind::Table,
            TenantDataPolicy::GlobalReference,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'mapping' => ['strategy' => 'natural_key', 'keys' => ['missing_code']],
            ],
        );

        self::assertSame(
            [
                'global_mapping_column_missing:countries.missing_code',
                'missing_policy_reason:sessions',
            ],
            (new TenantDataRegistryCoverageValidator())->issues(
                $this->registry([$instance, $global]),
                [
                    new TenantSchemaTableInventory('sessions', 'BASE TABLE', ['id'], ['id'], []),
                    new TenantSchemaTableInventory(
                        'countries',
                        'BASE TABLE',
                        ['id', 'code'],
                        ['id'],
                        [],
                    ),
                ],
            ),
        );
    }

    /**
     * @param list<TenantDataDefinition> $definitions
     */
    private function registry(array $definitions): TenantDataRegistry
    {
        return new TenantDataRegistry(
            1,
            $definitions,
            [TenantDataRegistry::TRANSFER_PROFILE],
        );
    }

    /** @param array<string,array<string,string>> $secrets */
    private function definition(string $table, array $secrets = []): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            $table === 'supplier'
                ? TenantDataPolicy::TenantRoot
                : TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => $table === 'supplier' ? 'selected_supplier' : 'supplier_id',
                    'column' => $table === 'supplier' ? 'id' : 'supplier_id',
                ],
                'secrets' => $secrets,
            ],
        );
    }
}
