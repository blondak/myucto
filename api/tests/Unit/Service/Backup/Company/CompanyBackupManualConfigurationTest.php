<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company as Backup;
use MyInvoice\Service\Backup\Registry\CompanyBackupSupplierDomainsDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupManualConfigurationTest extends TestCase
{
    public function testReportPreservesConfigurationButNoIdentityOrTrust(): void
    {
        $report = Backup\CompanyBackupManualConfiguration::collect($this->source([$this->row()]));
        self::assertSame(1, $report->rowCount());
        self::assertSame(2, $report->sourceKeyCount);
        self::assertSame([['hostname' => 'portal.example.test', 'purpose' => 'all',
            'is_primary_portal' => true, 'is_primary_public' => false]], $report->domains);
        self::assertCount(3, $report->toArray()['required_actions']);
        self::assertSame(str_repeat('a', 64), $report->technicalValidationBindingSha256);
        $changed = Backup\CompanyBackupManualConfiguration::collect($this->source([
            array_replace($this->row(), ['hostname' => 'other.example.test']),
        ]));
        self::assertNotSame($report->bindingSha256(), $changed->bindingSha256());
    }

    public function testEmptyConfigurationNeedsNoManualSteps(): void
    {
        $report = Backup\CompanyBackupManualConfiguration::collect($this->source([]));
        self::assertSame(['domains' => [], 'required_actions' => []], $report->toArray());
        self::assertSame(0, $report->sourceKeyCount);
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalidDomains')]
    public function testExportAndPreflightRejectInvalidOrTrustedPayload(array $changes): void
    {
        $projection = Backup\CompanyBackupTableProjection::fromDefinition(
            CompanyBackupSupplierDomainsDefinition::definition(),
        );
        $this->expectException(Backup\CompanyBackupDataSourceException::class);
        $this->expectExceptionMessage('data_manual_domain_invalid');
        $projection->assertCompleteSourceRow(array_replace($this->row(), $changes));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidDomains(): iterable
    {
        yield 'noncanonical host' => [['hostname' => 'PORTAL.example.test']];
        yield 'url' => [['hostname' => 'https://portal.example.test']];
        yield 'path traversal' => [['hostname' => '../example.test']];
        yield 'ip' => [['hostname' => '127.0.0.1']];
        yield 'unknown purpose' => [['purpose' => 'login']];
        yield 'wrong primary purpose' => [['purpose' => 'public_links']];
        yield 'invalid flag' => [['is_primary_portal' => 'yes']];
        yield 'token' => [['verification_token' => str_repeat('x', 64)]];
        yield 'trusted status' => [['status' => 'active']];
        yield 'actor' => [['created_by' => 42]];
    }

    public function testPolicyCannotHideAnotherTableOrAdditionalColumn(): void
    {
        $original = CompanyBackupSupplierDomainsDefinition::definition();
        $details = $original->details;
        $details['company_backup']['data_columns'][] = 'status';
        $this->expectExceptionMessage('data_manual_configuration_contract_invalid');
        Backup\CompanyBackupTableProjection::fromDefinition(new TenantDataDefinition(
            $original->key, $original->kind, $original->policy, $original->profiles, $details,
        ));
    }

    public function testOnlyExactOmittedActorForeignKeysAreExempt(): void
    {
        $projection = Backup\CompanyBackupTableProjection::fromDefinition(
            CompanyBackupSupplierDomainsDefinition::definition(),
        );
        $supplier = new Backup\CompanyBackupForeignKey(['supplier_id'], 'supplier', ['id']);
        $schema = new Backup\CompanyBackupTableReferenceSchema(['created_by', 'updated_by'], [
            $supplier, new Backup\CompanyBackupForeignKey(['created_by'], 'users', ['id']),
            new Backup\CompanyBackupForeignKey(['updated_by'], 'users', ['id']),
        ]);
        $filtered = Backup\CompanyBackupManualConfiguration::referenceSchema($projection, $schema);
        self::assertSame([$supplier], $filtered->foreignKeys);
        $projection->references->assertRuntimeSchema($filtered);
        $drift = new Backup\CompanyBackupTableReferenceSchema([], [
            $supplier, new Backup\CompanyBackupForeignKey(['created_by'], 'other_actors', ['id']),
        ]);
        $this->expectExceptionMessage('data_reference_foreign_key_unclassified');
        $projection->references->assertRuntimeSchema(
            Backup\CompanyBackupManualConfiguration::referenceSchema($projection, $drift),
        );
    }

    public function testDuplicateHostnameCannotSilentlyDisappearFromReport(): void
    {
        $this->expectExceptionMessage('import_manual_configuration_invalid');
        Backup\CompanyBackupManualConfiguration::collect($this->source([
            $this->row(), array_replace($this->row(), ['id' => 2]),
        ]));
    }

    public function testManualConfigurationCannotBeUsedAsAnImportReferenceTarget(): void
    {
        $domain = CompanyBackupSupplierDomainsDefinition::definition();
        $details = $domain->details;
        $details['secrets'] = [];
        $details['company_backup'] = [
            'data_columns' => ['id', 'supplier_id'], 'generated_columns' => [],
            'omit_columns' => [], 'embedded_references' => [], 'restore_overrides' => [],
            'references' => [[
                'columns' => ['supplier_id'], 'target' => $domain->key,
                'target_columns' => ['id'], 'mapping' => 'tenant_id',
                'constraint' => 'required', 'nullable_columns' => [], 'fallbacks' => [],
            ]],
        ];
        $dependent = new TenantDataDefinition('table:synthetic_dependent', $domain->kind,
            \MyInvoice\Service\Backup\Registry\TenantDataPolicy::TenantOwned, $domain->profiles, $details);
        $this->expectException(Backup\CompanyBackupDataSourceException::class);
        Backup\CompanyBackupTableProjection::fromDefinition($dependent)->assertRegistryTargets(
            new TenantDataRegistry(1, [$domain, $dependent], [TenantDataRegistry::COMPANY_BACKUP_PROFILE]),
        );
    }

    public function testSqlWriterCannotInsertManualConfigurationEvenWhenCalledDirectly(): void
    {
        $this->expectExceptionMessage('import_insert_writer_context_invalid');
        new Backup\CompanyBackupSqlInsertWriter($this->createStub(\PDO::class),
            CompanyBackupSupplierDomainsDefinition::definition(),
            new Backup\CompanyBackupTableSchema([], [], [], []), 1);
    }

    public function testLimitAppliesToInventoryBeforeArchiveCanBePublished(): void
    {
        $this->expectExceptionMessage('Ruční konfigurace překračuje limit domén');
        $this->source(array_fill(0, Backup\CompanyBackupManualConfiguration::MAX_DOMAINS + 1, $this->row()));
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        return ['id' => 1, 'supplier_id' => 7, 'hostname' => 'portal.example.test',
            'purpose' => 'all', 'is_primary_portal' => 1, 'is_primary_public' => 0];
    }

    /** @param list<array<string,mixed>> $rows */
    private function source(array $rows): Backup\CompanyBackupImportSource
    {
        $definition = CompanyBackupSupplierDomainsDefinition::definition();
        $registry = TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(1, [$definition],
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE]), TenantDataRegistry::COMPANY_BACKUP_PROFILE);
        $inventory = Backup\CompanyBackupDataInventory::fromObjects([
            Backup\CompanyBackupDataObject::fromWrittenPayload($definition, 1, count($rows), 0, str_repeat('b', 64)),
        ], $registry);
        $source = $this->createStub(Backup\CompanyBackupImportSource::class);
        $source->method('targetRegistry')->willReturn($registry);
        $source->method('dataInventory')->willReturn($inventory);
        $source->method('technicalValidationBindingSha256')->willReturn(str_repeat('a', 64));
        $source->method('consumeRows')->willReturnCallback(static function (string $key, callable $visitor) use ($rows): int {
            foreach ($rows as $row) {
                $visitor($row);
            }
            return count($rows);
        });
        return $source;
    }
}
