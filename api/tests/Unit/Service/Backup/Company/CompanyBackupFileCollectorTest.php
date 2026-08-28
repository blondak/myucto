<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupFileAreaRootResolver;
use MyInvoice\Service\Backup\Company\CompanyBackupFileCollector;
use MyInvoice\Service\Backup\Company\CompanyBackupFileReference;
use MyInvoice\Service\Backup\Company\CompanyBackupFileReferenceSource;
use MyInvoice\Service\Backup\Company\CompanyBackupFileSourceException;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupFileCollectorTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->directories) as $directory) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                if (is_file($path) || is_link($path)) {
                    @unlink($path);
                }
            }
            @rmdir($directory);
        }
    }

    public function testCollectsPresentAndHistoricalMissingFilesWithCanonicalOwners(): void
    {
        $storage = $this->directory();
        $logos = $this->subdirectory($storage, 'supplier-logos');
        $contents = "\x89PNG\r\n";
        $present = $logos . DIRECTORY_SEPARATOR . 'present.PNG';
        self::assertSame(strlen($contents), file_put_contents($present, $contents));
        $source = new ArrayFileReferenceSource([
            new CompanyBackupFileReference(
                'present.PNG',
                'table:supplier',
                ['id' => 7],
                'logo_path',
            ),
            new CompanyBackupFileReference(
                'missing.png',
                'table:branding_profiles',
                ['id' => 12],
                'logo_path',
            ),
            new CompanyBackupFileReference(
                'present.PNG',
                'table:branding_profiles',
                ['id' => 11],
                'logo_path',
            ),
            new CompanyBackupFileReference(
                'present.PNG',
                'table:invoices',
                ['id' => 101],
                'supplier_snapshot',
                ['logo_path'],
            ),
        ]);

        $files = (new CompanyBackupFileCollector($this->roots($storage)))->collect(
            $this->createStub(PDO::class),
            $this->snapshot(),
            7,
            $source,
        );

        self::assertCount(1, $files->inventory->areas);
        $entries = $files->inventory->areas[0]->entries;
        self::assertCount(2, $entries);
        self::assertSame('missing.png', $entries[0]->sourcePath);
        self::assertSame('missing', $entries[0]->state->value);
        self::assertSame('present.PNG', $entries[1]->sourcePath);
        self::assertSame('present', $entries[1]->state->value);
        self::assertSame(
            ['table:branding_profiles', 'table:invoices', 'table:supplier'],
            array_column($entries[1]->owners, 'registry_key'),
        );
        self::assertSame([[], ['logo_path'], []], array_column($entries[1]->owners, 'path'));

        $sha256 = hash('sha256', $contents);
        $archivePath = 'files/supplier-logos/' . $sha256 . '.png';
        self::assertSame($archivePath, $entries[1]->archivePath);
        self::assertSame([realpath($present)], array_values($files->sourceFiles));
        self::assertSame([$archivePath], array_keys($files->sourceFiles));
        self::assertSame(
            ['file-area:supplier-logos@7'],
            $source->calls,
        );
    }

    public function testRequiredMissingFileStopsCollection(): void
    {
        $storage = $this->directory();
        $source = new ArrayFileReferenceSource([
            new CompanyBackupFileReference(
                'missing.png',
                'table:branding_profiles',
                ['id' => 12],
                'logo_path',
            ),
        ]);

        try {
            (new CompanyBackupFileCollector($this->roots($storage)))->collect(
                $this->createStub(PDO::class),
                $this->snapshot('required'),
                7,
                $source,
            );
            self::fail('Povinný chybějící soubor musí zastavit snapshot.');
        } catch (CompanyBackupFileSourceException $e) {
            self::assertSame('file_source_missing', $e->errorCode);
            self::assertSame('file-area:supplier-logos', $e->registryKey);
            self::assertSame('missing.png', $e->sourcePath);
        }
    }

    public function testRejectsReferenceOutsideAreaTenantPathPolicy(): void
    {
        $storage = $this->directory();
        $source = new ArrayFileReferenceSource([
            new CompanyBackupFileReference(
                'sup-999.png',
                'table:supplier',
                ['id' => 7],
                'logo_path',
            ),
        ]);

        try {
            (new CompanyBackupFileCollector($this->roots($storage)))->collect(
                $this->createStub(PDO::class),
                $this->snapshot(pathPolicy: 'supplier_logo'),
                7,
                $source,
            );
            self::fail('Kolektor nesmí důvěřovat tenantově cizí cestě ze zdroje.');
        } catch (CompanyBackupFileSourceException $e) {
            self::assertSame('file_source_tenant_mismatch', $e->errorCode);
            self::assertSame('sup-999.png', $e->sourcePath);
        }
    }

    public function testSymlinkIsRejectedEvenForHistoricalOptionalArea(): void
    {
        $storage = $this->directory();
        $logos = $this->subdirectory($storage, 'supplier-logos');
        $target = $logos . DIRECTORY_SEPARATOR . 'target.png';
        self::assertSame(9, file_put_contents($target, 'synthetic'));
        $link = $logos . DIRECTORY_SEPARATOR . 'linked.png';
        if (!@symlink($target, $link)) {
            self::markTestSkipped('Platforma testu nedovoluje vytvořit symlink.');
        }
        $source = new ArrayFileReferenceSource([
            new CompanyBackupFileReference(
                'linked.png',
                'table:branding_profiles',
                ['id' => 11],
                'logo_path',
            ),
        ]);

        try {
            (new CompanyBackupFileCollector($this->roots($storage)))->collect(
                $this->createStub(PDO::class),
                $this->snapshot(),
                7,
                $source,
            );
            self::fail('Symlink nesmí být považován za historicky chybějící soubor.');
        } catch (CompanyBackupFileSourceException $e) {
            self::assertSame('file_source_path_unsafe', $e->errorCode);
            self::assertSame('linked.png', $e->sourcePath);
        }
    }

    public function testSnapshotDetectsSameSizeSourceChangeBeforeArchiveWrite(): void
    {
        $storage = $this->directory();
        $logos = $this->subdirectory($storage, 'supplier-logos');
        $present = $logos . DIRECTORY_SEPARATOR . 'present.png';
        self::assertSame(5, file_put_contents($present, 'first'));
        $source = new ArrayFileReferenceSource([
            new CompanyBackupFileReference(
                'present.png',
                'table:branding_profiles',
                ['id' => 11],
                'logo_path',
            ),
        ]);
        $files = (new CompanyBackupFileCollector($this->roots($storage)))->collect(
            $this->createStub(PDO::class),
            $this->snapshot(),
            7,
            $source,
        );
        self::assertSame(5, file_put_contents($present, 'other'));

        try {
            $files->assertSourcesUnchanged();
            self::fail('Změna obsahu se stejnou délkou nesmí uniknout kontrole.');
        } catch (CompanyBackupFileSourceException $e) {
            self::assertSame('file_source_changed', $e->errorCode);
            self::assertSame('present.png', $e->sourcePath);
        }
    }

    public function testRejectsSourcePathsThatCollideOnWindows(): void
    {
        $storage = $this->directory();
        $source = new ArrayFileReferenceSource([
            new CompanyBackupFileReference(
                'Logo.png',
                'table:branding_profiles',
                ['id' => 11],
                'logo_path',
            ),
            new CompanyBackupFileReference(
                'logo.png',
                'table:branding_profiles',
                ['id' => 12],
                'logo_path',
            ),
        ]);

        try {
            (new CompanyBackupFileCollector($this->roots($storage)))->collect(
                $this->createStub(PDO::class),
                $this->snapshot(),
                7,
                $source,
            );
            self::fail('Cesty lišící se jen casingem nejsou přenositelné na Windows.');
        } catch (CompanyBackupFileSourceException $e) {
            self::assertSame('file_source_path_collision', $e->errorCode);
            self::assertSame('logo.png', $e->sourcePath);
        }
    }

    private function snapshot(
        string $filePolicy = 'historical_optional',
        string $pathPolicy = 'relative',
    ): TenantDataRegistrySnapshot {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [
                new TenantDataDefinition(
                    'table:branding_profiles',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    ['primary_key' => ['id']],
                ),
                new TenantDataDefinition(
                    'table:invoices',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    ['primary_key' => ['id']],
                ),
                new TenantDataDefinition(
                    'table:supplier',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::TenantRoot,
                    [$profile],
                    ['primary_key' => ['id']],
                ),
                new TenantDataDefinition(
                    'file-area:supplier-logos',
                    TenantDataObjectKind::FileArea,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    [
                        'file_policy' => $filePolicy,
                        'path_policy' => $pathPolicy,
                        'file_owners' => [
                            [
                                'registry_key' => 'table:branding_profiles',
                                'column' => 'logo_path',
                                'path' => [],
                                'stored_prefix' => 'storage/supplier-logos/',
                            ],
                            [
                                'registry_key' => 'table:invoices',
                                'column' => 'supplier_snapshot',
                                'path' => ['logo_path'],
                                'stored_prefix' => 'storage/supplier-logos/',
                            ],
                            [
                                'registry_key' => 'table:supplier',
                                'column' => 'logo_path',
                                'path' => [],
                                'stored_prefix' => 'storage/supplier-logos/',
                            ],
                        ],
                        'ownership' => ['strategy' => 'database_references'],
                        'storage_subdirectory' => 'supplier-logos',
                    ],
                ),
            ],
            [$profile],
        ), $profile);
    }

    private function roots(string $storage): CompanyBackupFileAreaRootResolver
    {
        return new class($storage) implements CompanyBackupFileAreaRootResolver {
            public function __construct(private readonly string $storage) {}

            public function resolve(string $storageSubdirectory): string
            {
                return $this->storage . DIRECTORY_SEPARATOR . $storageSubdirectory;
            }
        };
    }

    private function directory(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'myucto-company-files-' . bin2hex(random_bytes(8));
        if (!mkdir($path, 0700)) {
            throw new \RuntimeException('Nelze vytvořit adresář souborového testu.');
        }
        $this->directories[] = $path;
        return $path;
    }

    private function subdirectory(string $parent, string $relative): string
    {
        $path = $parent;
        foreach (explode('/', $relative) as $segment) {
            $path .= DIRECTORY_SEPARATOR . $segment;
            if (!is_dir($path) && !mkdir($path, 0700)) {
                throw new \RuntimeException('Nelze vytvořit adresář souborového testu.');
            }
            $this->directories[] = $path;
        }
        return $path;
    }
}

final class ArrayFileReferenceSource implements CompanyBackupFileReferenceSource
{
    /** @var list<string> */
    public array $calls = [];

    /** @param list<CompanyBackupFileReference> $references */
    public function __construct(private readonly array $references) {}

    public function references(
        PDO $snapshot,
        int $supplierId,
        TenantDataDefinition $definition,
        TenantDataRegistry $registry,
    ): iterable {
        $this->calls[] = $definition->key . '@' . $supplierId;
        return $this->references;
    }
}
