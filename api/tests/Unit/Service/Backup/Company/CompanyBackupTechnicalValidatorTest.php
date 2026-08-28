<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveInspector;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveWriter;
use MyInvoice\Service\Backup\Company\CompanyBackupDataInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFormat;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupTechnicalValidationException;
use MyInvoice\Service\Backup\Company\CompanyBackupTechnicalValidator;
use MyInvoice\Service\Backup\Company\Upcast\BackupUpcasterRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryCoverageValidator;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PHPUnit\Framework\TestCase;

final class CompanyBackupTechnicalValidatorTest extends TestCase
{
    private const PASSWORD = 'synthetic-backup-password-42';

    /** @var list<string> */
    private array $archives = [];

    protected function tearDown(): void
    {
        foreach ($this->archives as $archive) {
            if (is_file($archive) || is_link($archive)) {
                @unlink($archive);
            }
        }
    }

    public function testBindsValidatedArchiveToCurrentTargetRegistry(): void
    {
        $sourceRegistry = $this->registry([
            $this->definition('supplier', TenantDataPolicy::TenantRoot),
        ]);
        $targetRegistry = $this->registry([
            $this->definition('supplier', TenantDataPolicy::TenantRoot),
            $this->definition('derived_cache', TenantDataPolicy::RuntimeDerived),
        ], version: 2);
        $archive = $this->archive($sourceRegistry);

        $validation = $this->validator($targetRegistry)->validate(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
            ['table:derived_cache', 'table:supplier'],
        );

        self::assertTrue($validation->registryChanged);
        self::assertSame(
            $sourceRegistry->fingerprintFor(TenantDataRegistry::COMPANY_BACKUP_PROFILE),
            $validation->sourceRegistryFingerprint,
        );
        self::assertSame(
            $targetRegistry->fingerprintFor(TenantDataRegistry::COMPANY_BACKUP_PROFILE),
            $validation->targetRegistryFingerprint,
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $validation->bindingSha256);
        self::assertSame([], $validation->toArray()['upcasters']);
        self::assertSame([], $validation->toArray()['warnings']);
        self::assertSame(
            $validation->bindingSha256,
            $this->validator($targetRegistry)->validate(
                $archive,
                self::PASSWORD,
                '5.28.1',
                CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
                ['table:supplier', 'table:derived_cache'],
            )->bindingSha256,
        );
    }

    public function testExactRegistryIsReportedWithoutFalseCompatibilityGate(): void
    {
        $registry = $this->registry([
            $this->definition('supplier', TenantDataPolicy::TenantRoot),
        ]);
        $archive = $this->archive($registry);

        $validation = $this->validator($registry)->validate(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
            ['table:supplier'],
        );

        self::assertFalse($validation->registryChanged);
    }

    public function testIncompleteTargetRegistryStopsBeforeArchiveIsOpened(): void
    {
        $draft = new TenantDataRegistry(1, [
            $this->definition('supplier', TenantDataPolicy::TenantRoot),
        ]);

        try {
            $this->validator($draft)->validate(
                '/does/not/exist.zip',
                'wrong-password',
                '5.28.1',
                CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
                ['table:supplier'],
            );
            self::fail('Neúplný cílový registr nesmí pustit obnovu k archivu.');
        } catch (CompanyBackupTechnicalValidationException $e) {
            self::assertSame('target_registry_incomplete', $e->errorCode);
            self::assertSame(
                ['profile_incomplete'],
                array_column($e->coverage->toArray()['issues'], 'code'),
            );
        }
    }

    public function testUnknownRuntimeObjectStopsBeforeArchiveIsOpened(): void
    {
        $registry = $this->registry([
            $this->definition('supplier', TenantDataPolicy::TenantRoot),
        ]);

        try {
            $this->validator($registry)->validate(
                '/does/not/exist.zip',
                'wrong-password',
                '5.28.1',
                CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
                ['table:supplier', 'table:new_agenda'],
            );
            self::fail('Neznámý runtime objekt musí zastavit obnovu před archivem.');
        } catch (CompanyBackupTechnicalValidationException $e) {
            self::assertSame('target_registry_incomplete', $e->errorCode);
            self::assertSame('table:new_agenda', $e->coverage->issues[0]->object);
            self::assertSame('object_unclassified', $e->coverage->issues[0]->code);
        }
    }

    private function validator(TenantDataRegistry $registry): CompanyBackupTechnicalValidator
    {
        $format = new CompanyBackupFormat();
        return new CompanyBackupTechnicalValidator(
            new CompanyBackupArchiveInspector(
                $format,
                BackupUpcasterRegistry::empty(),
                $this->limits(),
            ),
            $registry,
            new TenantDataRegistryCoverageValidator(),
        );
    }

    private function archive(TenantDataRegistry $registry): string
    {
        $path = tempnam(sys_get_temp_dir(), 'myucto-technical-validation-');
        if ($path === false) {
            throw new \RuntimeException('Nelze vytvořit cestu syntetické zálohy.');
        }
        @unlink($path);
        $path .= '.zip';
        $this->archives[] = $path;
        $format = new CompanyBackupFormat();
        $supplier = "{\"id\":1}\n";
        $manifest = $format->parseManifest($format->encodeManifest([
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
            'secrets' => [
                'format' => CompanyBackupSecretInventory::FORMAT,
                'version' => CompanyBackupSecretInventory::VERSION,
                'omissions' => [],
            ],
        ]));
        $writer = new CompanyBackupArchiveWriter(
            $path,
            self::PASSWORD,
            $format,
            $this->limits(),
        );
        $writer->addString('data/table-supplier.jsonl', $supplier);
        $writer->finish($manifest, "Syntetická záloha.\n");
        return $path;
    }

    /** @param list<TenantDataDefinition> $definitions */
    private function registry(array $definitions, int $version = 1): TenantDataRegistry
    {
        return new TenantDataRegistry(
            $version,
            $definitions,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
        );
    }

    private function definition(string $table, TenantDataPolicy $policy): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'ownership' => $table === 'supplier'
                    ? ['strategy' => 'selected_supplier', 'column' => 'id']
                    : ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
            ],
        );
    }

    private function limits(): CompanyBackupArchiveLimits
    {
        return new CompanyBackupArchiveLimits(
            maxArchiveBytes: 1_000_000,
            maxEntries: 20,
            maxEntryBytes: 20_000,
            maxExpandedBytes: 80_000,
            maxCompressionRatio: 1_000,
            maxManifestBytes: 20_000,
            maxChecksumsBytes: 4_096,
        );
    }
}
