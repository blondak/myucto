<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveException;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveWriter;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveWriteException;
use MyInvoice\Service\Backup\Company\CompanyBackupFormat;
use MyInvoice\Service\Backup\Company\CompanyBackupManifest;
use MyInvoice\Service\Backup\Company\Upcast\BackupUpcasterRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
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
        $source = $this->temporaryFile("{\"id\":2}\n");
        $format = new CompanyBackupFormat();
        $writer = new CompanyBackupArchiveWriter(
            $archive,
            self::PASSWORD,
            $format,
            $this->limits(),
        );
        $writer->addString('data/table-invoices.jsonl', "{\"id\":1}\n");
        $writer->addFile('files/invoice-pdf/00000001.pdf', $source);

        self::assertFileDoesNotExist($archive);
        $result = $writer->finish($this->manifest($format), "Syntetická záloha.\n");

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
                'CTI-MNE.txt',
                'data/table-invoices.jsonl',
                'files/invoice-pdf/00000001.pdf',
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
        $writer->addString('data/table.jsonl', "{\"id\":1}\n");
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

    private function manifest(CompanyBackupFormat $format): CompanyBackupManifest
    {
        $registry = new TenantDataRegistry(
            1,
            [new TenantDataDefinition(
                'table:supplier',
                TenantDataObjectKind::Table,
                TenantDataPolicy::TenantRoot,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
                ['ownership' => ['strategy' => 'selected_supplier', 'column' => 'id']],
            )],
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
        );
        return $format->parseManifest($format->encodeManifest([
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
        ]));
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
