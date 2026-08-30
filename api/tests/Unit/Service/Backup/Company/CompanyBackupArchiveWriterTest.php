<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveException;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveWriter;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveWriteException;
use MyInvoice\Service\Backup\Company\CompanyBackupDataInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupDataObject;
use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFormat;
use MyInvoice\Service\Backup\Company\CompanyBackupJsonlWriter;
use MyInvoice\Service\Backup\Company\CompanyBackupManifest;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupSealedSecretEnvelope;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeCipher;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeDescriptor;
use MyInvoice\Service\Backup\Company\Upcast\BackupUpcasterRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PHPUnit\Framework\TestCase;

final class CompanyBackupArchiveWriterTest extends TestCase
{
    private const PASSWORD = 'synthetic-backup-password-42';

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
    }

    public function testPublishesOnlyArchiveThatPassesTheIndependentInspector(): void
    {
        $archive = $this->unusedPath('zip');
        $fileContents = "\x89PNG\r\n";
        $source = $this->temporaryFile($fileContents);
        $filePath = $this->fileArchivePath($fileContents);
        $jsonl = $this->unusedPath('jsonl');
        $format = new CompanyBackupFormat();
        $dataObject = (new CompanyBackupJsonlWriter($this->limits()))->write(
            $this->supplierDefinition(),
            1,
            [['id' => 1]],
            $jsonl,
        );
        $writer = new CompanyBackupArchiveWriter(
            $archive,
            self::PASSWORD,
            $format,
            $this->limits(),
        );
        $writer->addFile($dataObject->path, $jsonl);
        $writer->addFile($filePath, $source);

        self::assertFileDoesNotExist($archive);
        $result = $writer->finish(
            $this->manifest($format, $dataObject, $fileContents),
            "Syntetická záloha.\n",
        );

        self::assertFileExists($archive);
        self::assertSame($archive, $result->archivePath);
        self::assertSame(hash_file('sha256', $archive), $result->archiveSha256);
        self::assertSame(filesize($archive), $result->archiveBytes);
        self::assertSame(5, $result->entryCount);

        $inspection = (new \MyInvoice\Service\Backup\Company\CompanyBackupArchiveInspector(
            $format,
            BackupUpcasterRegistry::empty(),
            $this->limits(),
        ))->inspect(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
        self::assertSame($result->archiveSha256, $inspection->archiveSha256);
        self::assertSame(
            [
                'README.txt',
                'data/table-supplier.jsonl',
                $filePath,
                'manifest.json',
            ],
            array_keys($inspection->entryHashes),
        );
    }

    public function testWeakPasswordFailsBeforeCreatingTemporaryArchive(): void
    {
        $archive = $this->unusedPath('zip');

        try {
            new CompanyBackupArchiveWriter(
                $archive,
                'short',
                new CompanyBackupFormat(),
                $this->limits(),
            );
            self::fail('Přenositelná záloha nesmí vzniknout se slabým heslem.');
        } catch (CompanyBackupArchiveWriteException $e) {
            self::assertSame('archive_password_weak', $e->errorCode);
        }

        self::assertFileDoesNotExist($archive);
        self::assertSame([], glob($archive . '.part-*') ?: []);
    }

    public function testExistingDestinationIsNeverOverwritten(): void
    {
        $archive = $this->temporaryFile('existing-user-file');

        try {
            new CompanyBackupArchiveWriter(
                $archive,
                self::PASSWORD,
                new CompanyBackupFormat(),
                $this->limits(),
            );
            self::fail('Existující cíl nesmí writer přepsat.');
        } catch (CompanyBackupArchiveWriteException $e) {
            self::assertSame('archive_destination_exists', $e->errorCode);
        }

        self::assertSame('existing-user-file', file_get_contents($archive));
    }

    public function testReservedEntriesCannotBeShadowedByPayload(): void
    {
        $archive = $this->unusedPath('zip');
        $writer = new CompanyBackupArchiveWriter(
            $archive,
            self::PASSWORD,
            new CompanyBackupFormat(),
            $this->limits(),
        );

        try {
            $writer->addString('Manifest.json', '{}');
            self::fail('Payload nesmí zastínit manifest rozdílným casingem.');
        } catch (CompanyBackupArchiveException $e) {
            self::assertSame('entry_path_duplicate', $e->errorCode);
        }

        $writer->abort();
        self::assertFileDoesNotExist($archive);
        self::assertSame([], glob($archive . '.part-*') ?: []);
    }

    public function testAddsTypedSecretEnvelopeAndSelfAuthenticatesIt(): void
    {
        $archive = $this->unusedPath('zip');
        $format = new CompanyBackupFormat([
            CompanyBackupSecretEnvelopeDescriptor::CAPABILITY,
        ]);
        $registry = $this->registry(protectedSecret: true);
        $snapshot = TenantDataRegistrySnapshot::fromRegistry(
            $registry,
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
        );
        $sealed = (new CompanyBackupSecretEnvelopeCipher())->seal(
            '{"entries":[],"format":"synthetic-secret-payload","version":1}',
            self::PASSWORD,
            '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1',
            $snapshot->fingerprint,
        );
        $writer = new CompanyBackupArchiveWriter(
            $archive,
            self::PASSWORD,
            $format,
            $this->limits(),
        );
        $writer->addString('data/table-supplier.jsonl', "{\"id\":1}\n");
        $writer->addSecretEnvelope($sealed);

        $result = $writer->finish(
            $this->manifest($format, secretEnvelope: $sealed),
            "Syntetická záloha.\n",
        );

        self::assertSame(5, $result->entryCount);
        self::assertFileExists($archive);
    }

    public function testGenericPayloadCannotEnterSecretNamespace(): void
    {
        $archive = $this->unusedPath('zip');
        $writer = new CompanyBackupArchiveWriter(
            $archive,
            self::PASSWORD,
            new CompanyBackupFormat(),
            $this->limits(),
        );

        try {
            $writer->addString('secrets/plaintext.txt', 'synthetic-secret');
            self::fail('Secret namespace smí plnit jen typovaný envelope.');
        } catch (CompanyBackupArchiveWriteException $e) {
            self::assertSame(
                'archive_secret_entry_requires_envelope',
                $e->errorCode,
            );
        }
        self::assertFileDoesNotExist($archive);
    }

    public function testSymlinkSourceIsNeverFollowedIntoArchive(): void
    {
        $archive = $this->unusedPath('zip');
        $source = $this->temporaryFile("synthetic\n");
        $link = $this->unusedPath('link');
        if (!@symlink($source, $link)) {
            self::markTestSkipped('Platforma testu nedovoluje vytvořit symlink.');
        }
        $writer = new CompanyBackupArchiveWriter(
            $archive,
            self::PASSWORD,
            new CompanyBackupFormat(),
            $this->limits(),
        );

        try {
            $writer->addFile('files/source.txt', $link);
            self::fail('Writer nesmí následovat symlink zdrojového souboru.');
        } catch (CompanyBackupArchiveWriteException $e) {
            self::assertSame('archive_source_unreadable', $e->errorCode);
            self::assertSame('files/source.txt', $e->entry);
        }

        self::assertFileDoesNotExist($archive);
        self::assertSame([], glob($archive . '.part-*') ?: []);
    }

    public function testChangedSourceFileAbortsAtomicPublication(): void
    {
        $archive = $this->unusedPath('zip');
        $source = $this->temporaryFile("first\n");
        $format = new CompanyBackupFormat();
        $writer = new CompanyBackupArchiveWriter(
            $archive,
            self::PASSWORD,
            $format,
            $this->limits(),
        );
        $writer->addFile('files/source.txt', $source);
        self::assertSame(6, file_put_contents($source, "other\n"));

        try {
            $writer->finish($this->manifest($format), "Syntetická záloha.\n");
            self::fail('Změna zdroje během zápisu musí zneplatnit celý balíček.');
        } catch (CompanyBackupArchiveWriteException $e) {
            self::assertSame('archive_source_changed', $e->errorCode);
            self::assertSame('files/source.txt', $e->entry);
        }

        self::assertFileDoesNotExist($archive);
    }

    public function testSelfInspectionRejectsArchiveOutsideCompressionLimits(): void
    {
        $archive = $this->unusedPath('zip');
        $format = new CompanyBackupFormat();
        $limits = new CompanyBackupArchiveLimits(
            maxArchiveBytes: 1_000_000,
            maxEntries: 20,
            maxEntryBytes: 20_000,
            maxExpandedBytes: 40_000,
            maxCompressionRatio: 2,
            maxManifestBytes: 4_096,
            maxChecksumsBytes: 4_096,
        );
        $writer = new CompanyBackupArchiveWriter(
            $archive,
            self::PASSWORD,
            $format,
            $limits,
        );
        $writer->addString('data/repetitive.jsonl', str_repeat('A', 10_000));

        try {
            $writer->finish($this->manifest($format), "Syntetická záloha.\n");
            self::fail('Writer nesmí zveřejnit archiv, který jeho reader odmítne.');
        } catch (CompanyBackupArchiveWriteException $e) {
            self::assertSame('archive_self_check_failed', $e->errorCode);
            self::assertInstanceOf(CompanyBackupArchiveException::class, $e->getPrevious());
            self::assertSame(
                'archive_compression_ratio_exceeded',
                $e->getPrevious()->errorCode,
            );
        }

        self::assertFileDoesNotExist($archive);
    }

    public function testDestinationCreatedDuringBuildWinsWithoutBeingOverwritten(): void
    {
        $archive = $this->unusedPath('zip');
        $format = new CompanyBackupFormat();
        $writer = new CompanyBackupArchiveWriter(
            $archive,
            self::PASSWORD,
            $format,
            $this->limits(),
        );
        $writer->addString('data/table-supplier.jsonl', "{\"id\":1}\n");
        self::assertSame(12, file_put_contents($archive, 'racing-owner'));

        try {
            $writer->finish($this->manifest($format), "Syntetická záloha.\n");
            self::fail('Souběžně vytvořený cíl nesmí atomický publish přepsat.');
        } catch (CompanyBackupArchiveWriteException $e) {
            self::assertSame('archive_destination_exists', $e->errorCode);
        }

        self::assertSame('racing-owner', file_get_contents($archive));
        self::assertSame([], glob($archive . '.part-*') ?: []);
    }

    private function manifest(
        CompanyBackupFormat $format,
        ?CompanyBackupDataObject $dataObject = null,
        ?string $fileContents = null,
        ?CompanyBackupSealedSecretEnvelope $secretEnvelope = null,
    ): CompanyBackupManifest
    {
        $definitions = [$this->supplierDefinition($secretEnvelope !== null)];
        $areas = [];
        if ($fileContents !== null) {
            $definitions[] = $this->supplierLogosDefinition();
            $sha256 = hash('sha256', $fileContents);
            $areas[] = [
                'registry_key' => 'file-area:supplier-logos',
                'order' => 1,
                'entries' => [[
                    'source_path' => '00000001.png',
                    'archive_path' => $this->fileArchivePath($fileContents),
                    'state' => 'present',
                    'bytes' => strlen($fileContents),
                    'sha256' => $sha256,
                    'owners' => [[
                        'registry_key' => 'table:supplier',
                        'primary_key' => ['id' => 1],
                        'column' => 'logo_path',
                        'path' => [],
                    ]],
                ]],
            ];
        }
        $registry = new TenantDataRegistry(
            1,
            $definitions,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
        );
        $supplier = "{\"id\":1}\n";
        return $format->parseManifest($format->encodeManifest([
            'product' => CompanyBackupFormat::PRODUCT,
            'format' => CompanyBackupFormat::FORMAT,
            'format_version' => ['major' => 1, 'minor' => 0],
            'backup_id' => '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1',
            'source' => [
                'app_version' => '5.28.1',
                'schema_revision' => CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
            ],
            'capabilities' => [
                'required' => $secretEnvelope === null
                    ? []
                    : [CompanyBackupSecretEnvelopeDescriptor::CAPABILITY],
                'optional' => [],
            ],
            'registry' => TenantDataRegistrySnapshot::fromRegistry(
                $registry,
                TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            )->toArray(),
            'data' => [
                'format' => CompanyBackupDataInventory::FORMAT,
                'version' => CompanyBackupDataInventory::VERSION,
                'objects' => [$dataObject?->toArray() ?? [
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
                'areas' => $areas,
            ],
            'secrets' => [
                'format' => CompanyBackupSecretInventory::FORMAT,
                'version' => CompanyBackupSecretInventory::VERSION,
                'omissions' => [],
                ...($secretEnvelope === null ? [] : [
                    'envelope' => $secretEnvelope->descriptor->toArray(),
                ]),
            ],
        ]));
    }

    private function supplierDefinition(
        bool $protectedSecret = false,
    ): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:supplier',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantRoot,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'ownership' => ['strategy' => 'selected_supplier', 'column' => 'id'],
                'primary_key' => ['id'],
                ...($protectedSecret ? [
                    'secrets' => [
                        'domain_salt' => [
                            'policy' =>
                                TenantSecretPolicy::ProtectedDomainSecret->value,
                        ],
                    ],
                ] : []),
            ],
        );
    }

    private function registry(bool $protectedSecret = false): TenantDataRegistry
    {
        return new TenantDataRegistry(
            1,
            [$this->supplierDefinition($protectedSecret)],
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
        );
    }

    private function supplierLogosDefinition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'file-area:supplier-logos',
            TenantDataObjectKind::FileArea,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'file_policy' => 'historical_optional',
                'path_policy' => 'relative',
                'file_owners' => [[
                    'registry_key' => 'table:supplier',
                    'column' => 'logo_path',
                    'path' => [],
                    'stored_prefix' => 'storage/supplier-logos/',
                ]],
                'ownership' => ['strategy' => 'database_references'],
            ],
        );
    }

    private function fileArchivePath(string $contents): string
    {
        return 'files/supplier-logos/' . hash('sha256', $contents) . '.png';
    }

    private function limits(): CompanyBackupArchiveLimits
    {
        return new CompanyBackupArchiveLimits(
            maxArchiveBytes: 1_000_000,
            maxEntries: 20,
            maxEntryBytes: 20_000,
            maxExpandedBytes: 80_000,
            maxCompressionRatio: 1_000,
            maxManifestBytes: 4_096,
            maxChecksumsBytes: 4_096,
        );
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'myucto-company-writer-');
        if ($path === false) {
            throw new \RuntimeException('Nelze vytvořit dočasný soubor testu.');
        }
        $this->paths[] = $path;
        if (file_put_contents($path, $contents) !== strlen($contents)) {
            throw new \RuntimeException('Nelze zapsat dočasný soubor testu.');
        }
        return $path;
    }

    private function unusedPath(string $extension): string
    {
        $path = $this->temporaryFile('');
        @unlink($path);
        $path .= '.' . $extension;
        $this->paths[] = $path;
        return $path;
    }
}
