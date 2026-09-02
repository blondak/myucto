<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveWriteResult;
use MyInvoice\Service\Backup\Company\CompanyBackupArtifactRootResolver;
use MyInvoice\Service\Backup\Company\CompanyBackupArtifactStorage;
use MyInvoice\Service\Backup\Company\CompanyBackupJobException;
use PHPUnit\Framework\TestCase;

final class CompanyBackupArtifactStorageTest extends TestCase
{
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';

    private string $temporaryRoot;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/company-backup-artifact-'
            . bin2hex(random_bytes(8));
        if (!mkdir($this->temporaryRoot, 0750)) {
            self::fail('Nepodařilo se vytvořit testovací kořen úložiště.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testPreparesCapturesAndResolvesOnlyCanonicalTenantArtifact(): void
    {
        $storage = $this->storage();
        $destination = $storage->prepareDestination(42, self::BACKUP_ID);
        $contents = "PK\x03\x04synthetic-company-backup";
        file_put_contents($destination, $contents);

        $artifact = $storage->capture(
            42,
            self::BACKUP_ID,
            new CompanyBackupArchiveWriteResult(
                $destination,
                hash('sha256', $contents),
                strlen($contents),
                7,
            ),
        );

        self::assertSame(42, $artifact->supplierId);
        self::assertSame(self::BACKUP_ID, $artifact->backupId);
        self::assertSame(
            'sup-42/' . self::BACKUP_ID . '.zip',
            $artifact->relativePath,
        );
        self::assertSame(
            'myucto-company-backup-' . self::BACKUP_ID . '.zip',
            $artifact->downloadName,
        );
        self::assertSame($destination, $storage->resolve($artifact));
    }

    public function testExistingDestinationIsNeverReused(): void
    {
        $storage = $this->storage();
        $destination = $storage->prepareDestination(42, self::BACKUP_ID);
        file_put_contents($destination, 'existing');

        try {
            $storage->prepareDestination(42, self::BACKUP_ID);
            self::fail('Existující archiv se nesmí přepsat ani znovu použít.');
        } catch (CompanyBackupJobException $e) {
            self::assertSame('artifact_destination_exists', $e->errorCode);
        }

        self::assertSame('existing', file_get_contents($destination));
    }

    public function testCaptureRejectsPathFromAnotherTenant(): void
    {
        $storage = $this->storage();
        $storage->prepareDestination(42, self::BACKUP_ID);
        $foreignPath = $storage->prepareDestination(43, self::BACKUP_ID);
        $contents = 'foreign-synthetic-archive';
        file_put_contents($foreignPath, $contents);

        try {
            $storage->capture(
                42,
                self::BACKUP_ID,
                new CompanyBackupArchiveWriteResult(
                    $foreignPath,
                    hash('sha256', $contents),
                    strlen($contents),
                    3,
                ),
            );
            self::fail('Výsledek z adresáře jiné firmy nesmí být přijat.');
        } catch (CompanyBackupJobException $e) {
            self::assertSame('artifact_path_invalid', $e->errorCode);
        }
    }

    public function testResolveFailsClosedAfterSizeDrift(): void
    {
        $storage = $this->storage();
        $destination = $storage->prepareDestination(42, self::BACKUP_ID);
        $contents = 'immutable-synthetic-archive';
        file_put_contents($destination, $contents);
        $artifact = $storage->capture(
            42,
            self::BACKUP_ID,
            new CompanyBackupArchiveWriteResult(
                $destination,
                hash('sha256', $contents),
                strlen($contents),
                3,
            ),
        );
        chmod($destination, 0640);
        file_put_contents($destination, $contents . '-changed');

        try {
            $storage->resolve($artifact);
            self::fail('Změněný hotový archiv nesmí být nabídnut ke stažení.');
        } catch (CompanyBackupJobException $e) {
            self::assertSame('artifact_unavailable', $e->errorCode);
        }
    }

    public function testSymlinkDestinationIsRejected(): void
    {
        $storage = $this->storage();
        $destination = $storage->prepareDestination(42, self::BACKUP_ID);
        $outside = $this->temporaryRoot . '/outside.zip';
        file_put_contents($outside, 'outside');
        if (!symlink($outside, $destination)) {
            self::markTestSkipped('Platforma testu nedovoluje vytvořit symlink.');
        }

        try {
            $storage->capture(
                42,
                self::BACKUP_ID,
                new CompanyBackupArchiveWriteResult(
                    $destination,
                    hash('sha256', 'outside'),
                    strlen('outside'),
                    3,
                ),
            );
            self::fail('Symlink nesmí být vydáván za hotový archiv.');
        } catch (CompanyBackupJobException $e) {
            self::assertSame('artifact_path_invalid', $e->errorCode);
        }
    }

    private function storage(): CompanyBackupArtifactStorage
    {
        $root = $this->temporaryRoot . '/company-backups';
        return new CompanyBackupArtifactStorage(
            new class ($root) implements CompanyBackupArtifactRootResolver {
                public function __construct(private readonly string $root) {}

                public function root(): string
                {
                    return $this->root;
                }
            },
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @chmod($path, 0640);
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
