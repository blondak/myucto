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
        self::assertArrayHasKey('README.txt', $inspection->entryHashes);
        self::assertArrayHasKey(
            CompanyBackupSecretEnvelopeDescriptor::PATH,
            $inspection->entryHashes,
        );
    }

    public function testEnvelopeForDifferentBackupIdIsNeverPublished(): void
    {
        $snapshot = $this->snapshot(
            self::BACKUP_ID,
            self::OTHER_BACKUP_ID,
        );
        $archive = $this->unusedPath('zip');

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
    }

    private function snapshot(
        string $envelopeBackupId,
        string $snapshotBackupId = self::BACKUP_ID,
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
        $registry = TenantDataRegistrySnapshot::fromRegistry(
            new TenantDataRegistry(1, [$definition], [$profile]),
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
        $fileInventory = CompanyBackupFileInventory::fromArray([
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => [],
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
