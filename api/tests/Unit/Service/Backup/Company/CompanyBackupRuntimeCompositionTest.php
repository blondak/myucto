<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLayout;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceMetadata;
use MyInvoice\Service\Backup\Company\CompanyBackupWorkDirectory;
use PHPUnit\Framework\TestCase;

final class CompanyBackupRuntimeCompositionTest extends TestCase
{
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';

    private string $runtimeRoot;
    private string|false $previousDataDir;

    protected function setUp(): void
    {
        $this->runtimeRoot = sys_get_temp_dir() . '/company-backup-runtime-'
            . bin2hex(random_bytes(8));
        if (!mkdir($this->runtimeRoot, 0700)) {
            self::fail('Nepodařilo se vytvořit syntetický runtime root.');
        }
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        putenv('MYINVOICE_DATA_DIR=' . $this->runtimeRoot);
    }

    protected function tearDown(): void
    {
        $this->previousDataDir === false
            ? putenv('MYINVOICE_DATA_DIR')
            : putenv('MYINVOICE_DATA_DIR=' . $this->previousDataDir);
        $this->removeDirectory($this->runtimeRoot);
    }

    public function testWorkDirectoryLivesUnderRuntimeStorageAndCleansPlaintext(): void
    {
        $directories = new CompanyBackupWorkDirectory();
        $directory = $directories->create(self::BACKUP_ID);
        $plaintext = $directory . '/synthetic.jsonl';
        file_put_contents($plaintext, "{\"synthetic\":true}\n");

        self::assertStringStartsWith(
            $this->runtimeRoot . '/storage/tmp/company-backups/',
            str_replace('\\', '/', $directory),
        );
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0700, fileperms($directory) & 0777);
        }

        $directories->cleanup($directory);

        self::assertFileDoesNotExist($plaintext);
        self::assertDirectoryDoesNotExist($directory);
    }

    public function testHumanEntryIsReadmeTxtAndNeverUsesLegacyName(): void
    {
        $metadata = new CompanyBackupSourceMetadata();
        $version = $metadata->version();
        $readme = $metadata->readme(self::BACKUP_ID, $version);

        self::assertSame('CTI-MNE.txt', CompanyBackupArchiveLayout::README);
        self::assertStringContainsString(self::BACKUP_ID, $readme);
        self::assertStringContainsString($version, $readme);
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
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
