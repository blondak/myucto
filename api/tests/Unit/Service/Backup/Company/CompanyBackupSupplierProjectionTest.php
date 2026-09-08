<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupSupplierProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSupplierProjectionTest extends TestCase
{
    public function testRemapsDefaultsAndDpaActorWithoutActivatingAutomation(): void
    {
        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:supplier');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $projection->assertRegistryTargets($registry);
        $row = array_replace(array_fill_keys($projection->dataColumns, null), [
            'id' => 7, 'country_id' => 1, 'default_currency_id' => 2,
            'default_vat_rate_id' => 3, 'default_branding_profile_id' => 4,
            'default_prices_include_vat' => 1, 'default_hourly_rate' => '1210.00',
            'accounting_activation_status' => 'completed', 'stock_enabled' => 1,
            'ai_dpa_confirmations' => '{"anthropic":{"confirmed_at":"2026-01-01T12:00:00Z","user_id":9}}',
        ]);
        foreach (CompanyBackupSupplierProjection::restoreOverrides() as $column => $override) {
            $row[$column] = 1;
        }
        $projection->assertCompleteSourceRow($row);
        $mapped = $projection->references->remap($row,
            static fn (CompanyBackupReference $reference, array $values): array => [$values[0] + 100]);
        $mapped = $projection->embeddedReferences->remap($mapped, static fn (): int => 91);
        $restored = $projection->restoreOverrides->apply($mapped);
        self::assertSame(102, $restored['default_currency_id']);
        self::assertSame(104, $restored['default_branding_profile_id']);
        $dpa = json_decode($restored['ai_dpa_confirmations'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(91, $dpa['anthropic']['user_id']);
        self::assertSame('2026-01-01T12:00:00Z', $dpa['anthropic']['confirmed_at']);
        foreach (CompanyBackupSupplierProjection::restoreOverrides() as $column => $override) {
            self::assertSame(0, $restored[$column]);
            self::assertSame(1, $row[$column]);
        }
        self::assertSame(1, $restored['default_prices_include_vat']);
        self::assertSame('1210.00', $restored['default_hourly_rate']);
        self::assertSame('completed', $restored['accounting_activation_status']);
        self::assertSame(1, $restored['stock_enabled']);
        foreach (array_keys($definition->details['secrets']) as $secret) {
            self::assertNotContains($secret, $projection->dataColumns);
        }
    }

    public function testRejectsUnresolvedLegacySignatureRatherThanDiscardingIt(): void
    {
        $this->expectException(CompanyBackupDataSourceException::class);
        $this->expectExceptionMessage('data_supplier_legacy_signature_unsupported');
        $this->projection()->assertCompleteSourceRow(array_replace($this->validRow(),
            ['signature_path' => 'storage/legacy/synthetic.png']));
    }

    public function testRejectsRunningAccountingActivation(): void
    {
        $this->expectExceptionMessage('data_supplier_activation_running');
        $this->projection()->assertCompleteSourceRow(array_replace($this->validRow(),
            ['accounting_activation_status' => 'running']));
    }

    private function projection(): CompanyBackupTableProjection
    {
        $definition = TenantDataRegistryFactory::draftV1()->definition('table:supplier');
        self::assertNotNull($definition);
        return CompanyBackupTableProjection::fromDefinition($definition);
    }

    /** @return array<string,mixed> */
    private function validRow(): array
    {
        return array_replace(array_fill_keys(CompanyBackupSupplierProjection::dataColumns(), null), [
            'id' => 7, 'country_id' => 1, 'default_currency_id' => 2, 'default_vat_rate_id' => 3,
        ]);
    }
}
