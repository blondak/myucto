<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveException;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveInspector;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveWriteException;
use MyInvoice\Service\Backup\Company\CompanyBackupDataInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupDataObject;
use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFormat;
use MyInvoice\Service\Backup\Company\CompanyBackupMachineArchiveWriter;
use MyInvoice\Service\Backup\Company\CompanyBackupMachineSnapshot;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeCipher;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeDescriptor;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretPayload;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretScope;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretValue;
use MyInvoice\Service\Backup\Company\Upcast\BackupUpcasterRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use MyInvoice\Service\Backup\Registry\TenantSecretPolicy;
use PHPUnit\Framework\TestCase;

final class CompanyBackupMachineArchiveWriterTest extends TestCase
{
    private const PASSWORD = 'synthetic-backup-password-42';
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';
    private const OTHER_BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a2';

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

    public function testWritesClosedSnapshotAsSelfCheckedArchive(): void
    {
        $snapshot = $this->snapshot(self::BACKUP_ID);
        $archive = $this->unusedPath('zip');
        $plaintext = $snapshot->sourceFiles['data/table-supplier.jsonl'];
        self::assertFileExists($plaintext);

        $result = (new CompanyBackupMachineArchiveWriter(
            $this->limits(),
        ))->write(
            $snapshot,
            $archive,
            self::PASSWORD,
            '5.28.1',
            "Syntetická přenositelná záloha.\n",
        );

        self::assertFileExists($archive);
        self::assertSame($archive, $result->archivePath);
        $inspection = (new CompanyBackupArchiveInspector(
            $this->format(),
            BackupUpcasterRegistry::empty(),
            $this->limits(),
        ))->inspect(
            $archive,
            self::PASSWORD,
            '5.28.1',
            CompanyBackupFormat::CURRENT_SCHEMA_REVISION,
        );
        self::assertSame(self::BACKUP_ID, $inspection->manifest->backupId);
        self::assertSame(
            $snapshot->registry->fingerprint,
            $inspection->sourceRegistry->fingerprint,
        );
        self::assertSame(
            $snapshot->inventory->toArray(),
            $inspection->dataInventory->toArray(),
        );
        self::assertSame(
            $snapshot->secretInventory->toArray(),
            $inspection->secretInventory->toArray(),
        );
        self::assertArrayHasKey('CTI-MNE.txt', $inspection->entryHashes);
        self::assertArrayHasKey(
            CompanyBackupSecretEnvelopeDescriptor::PATH,
            $inspection->entryHashes,
        );
        self::assertFileDoesNotExist($plaintext);
    }

    public function testEnvelopeForDifferentBackupIdIsNeverPublished(): void
    {
        $snapshot = $this->snapshot(
            self::BACKUP_ID,
            self::OTHER_BACKUP_ID,
        );
        $archive = $this->unusedPath('zip');
        $plaintext = $snapshot->sourceFiles['data/table-supplier.jsonl'];

        try {
            (new CompanyBackupMachineArchiveWriter(
                $this->limits(),
            ))->write(
                $snapshot,
                $archive,
                self::PASSWORD,
                '5.28.1',
                "Syntetická přenositelná záloha.\n",
            );
            self::fail('Envelope svázaná s jiným backup_id nesmí být publikována.');
        } catch (CompanyBackupArchiveWriteException $e) {
            self::assertSame('archive_self_check_failed', $e->errorCode);
            self::assertInstanceOf(
                CompanyBackupArchiveException::class,
                $e->getPrevious(),
            );
            self::assertSame(
                'secret_envelope_unlock_failed',
                $e->getPrevious()->errorCode,
            );
        }

        self::assertFileDoesNotExist($archive);
        self::assertSame([], glob($archive . '.part-*') ?: []);
        self::assertFileDoesNotExist($plaintext);
    }

    public function testKeepsBorrowedRegisteredFileAfterPlaintextCleanup(): void
    {
        $snapshot = $this->snapshot(
            self::BACKUP_ID,
            self::BACKUP_ID,
            withRegisteredFile: true,
        );
        $archive = $this->unusedPath('zip');
        $plaintext = $snapshot->temporarySourceFiles[
            'data/table-supplier.jsonl'
        ];
        $borrowed = array_values(array_diff_key(
            $snapshot->sourceFiles,
            $snapshot->temporarySourceFiles,
        ));
        self::assertCount(1, $borrowed);
        $borrowedPath = $borrowed[0];
        $borrowedContents = file_get_contents($borrowedPath);

        (new CompanyBackupMachineArchiveWriter($this->limits()))->write(
            $snapshot,
            $archive,
            self::PASSWORD,
            '5.28.1',
            "Syntetická přenositelná záloha.\n",
        );

        self::assertFileDoesNotExist($plaintext);
        self::assertFileExists($borrowedPath);
        self::assertSame($borrowedContents, file_get_contents($borrowedPath));
    }

    private function snapshot(
        string $envelopeBackupId,
        string $snapshotBackupId = self::BACKUP_ID,
        bool $withRegisteredFile = false,
    ): CompanyBackupMachineSnapshot {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $definition = new TenantDataDefinition(
            'table:supplier',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantRoot,
            [$profile],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'selected_supplier',
                    'column' => 'id',
                ],
                'secrets' => [
                    'domain_salt' => [
                        'policy' =>
                            TenantSecretPolicy::ProtectedDomainSecret->value,
                        'storage' => 'raw',
                    ],
                ],
            ],
        );
        $definitions = [$definition];
        if ($withRegisteredFile) {
            $definitions[] = new TenantDataDefinition(
                'file-area:supplier-logos',
                TenantDataObjectKind::FileArea,
                TenantDataPolicy::TenantOwned,
                [$profile],
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
                    'storage_subdirectory' => 'supplier-logos',
                ],
            );
        }
        $registry = TenantDataRegistrySnapshot::fromRegistry(
            new TenantDataRegistry(1, $definitions, [$profile]),
            $profile,
        );
        $jsonl = "{\"id\":7,\"name\":\"Synthetic s.r.o.\"}\n";
        $sourcePath = $this->temporaryFile($jsonl);
        $inventory = CompanyBackupDataInventory::fromObjects([
            CompanyBackupDataObject::fromWrittenPayload(
                $definition,
                1,
                1,
                strlen($jsonl),
                hash('sha256', $jsonl),
            ),
        ], $registry);
        $sourceFiles = ['data/table-supplier.jsonl' => $sourcePath];
        $areas = [];
        if ($withRegisteredFile) {
            $fileContents = "synthetic-borrowed-logo\n";
            $borrowedPath = $this->temporaryFile($fileContents);
            $sha256 = hash('sha256', $fileContents);
            $archivePath = 'files/supplier-logos/' . $sha256 . '.png';
            $sourceFiles[$archivePath] = $borrowedPath;
            $areas[] = [
                'registry_key' => 'file-area:supplier-logos',
                'order' => 1,
                'entries' => [[
                    'source_path' => '00000007.png',
                    'archive_path' => $archivePath,
                    'state' => 'present',
                    'bytes' => strlen($fileContents),
                    'sha256' => $sha256,
                    'owners' => [[
                        'registry_key' => 'table:supplier',
                        'primary_key' => ['id' => 7],
                        'column' => 'logo_path',
                        'path' => [],
                    ]],
                ]],
            ];
        }
        $fileInventory = CompanyBackupFileInventory::fromArray([
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => $areas,
        ], $registry);
        $payload = CompanyBackupSecretPayload::fromValues([
            CompanyBackupSecretValue::fromPlaintext(
                'table:supplier',
                CompanyBackupSecretScope::Column,
                'domain_salt',
                ['id' => 7],
                'synthetic-domain-salt',
            ),
        ], $registry);
        $envelope = (new CompanyBackupSecretEnvelopeCipher())->seal(
            $payload->toJson(),
            self::PASSWORD,
            $envelopeBackupId,
            $registry->fingerprint,
        );
        $secretInventory = CompanyBackupSecretInventory::fromCounts([], $registry)
            ->withEnvelope($envelope->descriptor);

        return new CompanyBackupMachineSnapshot(
            7,
            $snapshotBackupId,
            $registry,
            $inventory,
            $fileInventory,
            $secretInventory,
            $envelope,
            $sourceFiles,
            ['data/table-supplier.jsonl' => $sourcePath],
        );
    }

    private function format(): CompanyBackupFormat
    {
        return new CompanyBackupFormat([
            CompanyBackupSecretEnvelopeDescriptor::CAPABILITY,
        ]);
    }

    private function limits(): CompanyBackupArchiveLimits
    {
        return new CompanyBackupArchiveLimits(
            maxArchiveBytes: 1_000_000,
            maxEntries: 20,
            maxEntryBytes: 100_000,
            maxExpandedBytes: 500_000,
            maxCompressionRatio: 1_000,
            maxManifestBytes: 100_000,
            maxChecksumsBytes: 10_000,
        );
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'myucto-machine-archive-');
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
