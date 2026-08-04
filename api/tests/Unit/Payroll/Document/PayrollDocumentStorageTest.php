<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\PayrollDocumentStorage;
use PHPUnit\Framework\TestCase;

final class PayrollDocumentStorageTest extends TestCase
{
    private string|false $previousDataDir;
    private string $dataDir;

    protected function setUp(): void
    {
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $this->dataDir = sys_get_temp_dir() . '/myucto-payroll-doc-' . bin2hex(random_bytes(6));
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dataDir);
        $this->previousDataDir === false
            ? putenv('MYINVOICE_DATA_DIR')
            : putenv('MYINVOICE_DATA_DIR=' . $this->previousDataDir);
    }

    public function testStoresContentAddressedAndReadsOnlyVerifiedBytes(): void
    {
        $storage = new PayrollDocumentStorage();
        $bytes = '%PDF-1.4 synthetic';

        $first = $storage->store(17, $bytes);
        $second = $storage->store(17, $bytes);

        self::assertSame(hash('sha256', $bytes), $first['storage_key']);
        self::assertSame($first, $second);
        self::assertSame($bytes, $storage->readVerified(17, $first['storage_key']));
        self::assertStringNotContainsString('synthetic', basename($first['path']));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
