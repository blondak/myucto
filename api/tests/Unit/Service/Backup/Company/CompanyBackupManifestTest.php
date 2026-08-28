<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupFormat;
use MyInvoice\Service\Backup\Company\CompanyBackupFormatException;
use MyInvoice\Service\Backup\Company\CompanyBackupDataInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PHPUnit\Framework\TestCase;

final class CompanyBackupManifestTest extends TestCase
{
    public function testParsesRegistryBoundCanonicalManifest(): void
    {
        $format = new CompanyBackupFormat();
        $json = $format->encodeManifest($this->manifest());

        $manifest = $format->parseManifest($json);

        self::assertSame('0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1', $manifest->header->backupId);
        self::assertSame(TenantDataRegistry::COMPANY_BACKUP_PROFILE, $manifest->registry->profile);
        self::assertSame('table:supplier', $manifest->data->objects[0]->registryKey);
        self::assertSame([], $manifest->files->areas);
        self::assertSame($json, $manifest->canonicalJson());
        self::assertSame(hash('sha256', $json), $manifest->sha256());
    }

    public function testHeaderCanBeReadBeforeMissingRegistryIsRejected(): void
    {
        $format = new CompanyBackupFormat();
        $manifest = $this->manifest();
        unset($manifest['registry']);
        $json = $format->encodeManifest($manifest);

        self::assertSame(
            '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1',
            $format->parseManifestHeader($json)->backupId,
        );

        try {
            $format->parseManifest($json);
            self::fail('Obnovitelný manifest musí být svázaný se zdrojovým registrem.');
        } catch (CompanyBackupFormatException $e) {
            self::assertSame('manifest_registry_invalid', $e->errorCode);
            self::assertSame('registry', $e->field);
        }
    }

    public function testTamperedRegistryFingerprintHasStableManifestError(): void
    {
        $format = new CompanyBackupFormat();
        $manifest = $this->manifest();
        $manifest['registry']['fingerprint'] = 'sha256:' . str_repeat('f', 64);

        try {
            $format->parseManifest($format->encodeManifest($manifest));
            self::fail('Manifest nesmí přijmout fingerprint jiné sady definic.');
        } catch (CompanyBackupFormatException $e) {
            self::assertSame('manifest_registry_invalid', $e->errorCode);
            self::assertSame('registry', $e->field);
            self::assertStringContainsString('fingerprint', $e->getMessage());
        }
    }

    public function testHeaderCanBeReadBeforeMissingDataInventoryIsRejected(): void
    {
        $format = new CompanyBackupFormat();
        $manifest = $this->manifest();
        unset($manifest['data']);
        $json = $format->encodeManifest($manifest);

        self::assertSame(
            '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1',
            $format->parseManifestHeader($json)->backupId,
        );

        try {
            $format->parseManifest($json);
            self::fail('Obnovitelný manifest musí inventarizovat všechna strojová data.');
        } catch (CompanyBackupFormatException $e) {
            self::assertSame('manifest_data_invalid', $e->errorCode);
            self::assertSame('data', $e->field);
        }
    }

    public function testHeaderCanBeReadBeforeMissingFileInventoryIsRejected(): void
    {
        $format = new CompanyBackupFormat();
        $manifest = $this->manifest();
        unset($manifest['files']);
        $json = $format->encodeManifest($manifest);

        self::assertSame(
            '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1',
            $format->parseManifestHeader($json)->backupId,
        );

        try {
            $format->parseManifest($json);
            self::fail('Obnovitelný manifest musí inventarizovat souborové oblasti.');
        } catch (CompanyBackupFormatException $e) {
            self::assertSame('manifest_files_invalid', $e->errorCode);
            self::assertSame('files', $e->field);
        }
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        $registry = new TenantDataRegistry(
            1,
            [new TenantDataDefinition(
                'table:supplier',
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantRoot,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
                [
                    'primary_key' => ['id'],
                    'ownership' => ['strategy' => 'selected_supplier', 'column' => 'id'],
                ],
            )],
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
        );
        $supplier = "{\"id\":1}\n";
        return [
            'product' => CompanyBackupFormat::PRODUCT,
            'format' => CompanyBackupFormat::FORMAT,
            'format_version' => ['major' => 1, 'minor' => 0],
            'backup_id' => '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1',
            'source' => [
                'app_version' => '5.28.1',
                'schema_revision' => CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
            ],
            'capabilities' => ['required' => [], 'optional' => []],
            'registry' => TenantDataRegistrySnapshot::fromRegistry(
                $registry,
                TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            )->toArray(),
            'data' => [
                'format' => CompanyBackupDataInventory::FORMAT,
                'version' => CompanyBackupDataInventory::VERSION,
                'objects' => [[
                    'registry_key' => 'table:supplier',
                    'path' => 'data/table-supplier.jsonl',
                    'order' => 1,
                    'rows' => 1,
                    'bytes' => strlen($supplier),
                    'sha256' => hash('sha256', $supplier),
                ]],
            ],
            'files' => [
                'format' => CompanyBackupFileInventory::FORMAT,
                'version' => CompanyBackupFileInventory::VERSION,
                'areas' => [],
            ],
        ];
    }
}
