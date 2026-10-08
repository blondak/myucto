<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Myucto;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Migration\Myucto\MyuctoImportFiles;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MyuctoImportFilesTest extends TestCase
{
    private string $root;
    protected function setUp(): void { $this->root = sys_get_temp_dir() . '/myucto-import-files-test-' . bin2hex(random_bytes(6)); mkdir($this->root, 0700); }
    protected function tearDown(): void { $this->remove($this->root); }

    public function testRollbackRemovesOnlyNewFilesAndCommitReleasesOwnership(): void
    {
        [$files, $writes] = $this->fixture(); $path = array_key_first($writes);
        $files->publish($writes); self::assertSame('synthetic-pdf', file_get_contents($path));
        $files->rollback(); self::assertFileDoesNotExist($path);
        $files->publish($writes); $files->commit(); $files->rollback();
        self::assertSame('synthetic-pdf', file_get_contents($path));
    }

    public function testPreExistingFileIsNeverOverwrittenOrRemoved(): void
    {
        [$files, $writes] = $this->fixture(); $path = array_key_first($writes);
        mkdir(dirname($path), 0700, true); file_put_contents($path, 'pre-existing-synthetic-document');
        try { $files->publish($writes); self::fail('Přepsání existujícího souboru nesmí projít.'); }
        catch (RuntimeException $e) { self::assertStringContainsString('výhradně', $e->getMessage()); }
        $files->rollback(); self::assertSame('pre-existing-synthetic-document', file_get_contents($path));
    }

    private function fixture(): array
    {
        $files = new MyuctoImportFiles(new Config(['purchase_invoice' => ['archive_storage' => $this->root . '/purchase']]));
        $tables = ['purchase_invoices' => [91 => ['id' => 91]]];
        $assets = [['table' => 'purchase_invoices', 'id' => 91, 'column' => 'pdf_path', 'area' => 'purchase-invoices',
            'storage_path' => 'purchase-invoices/sup-7/original.pdf', 'sha256' => hash('sha256', 'synthetic-pdf'), 'content' => 'synthetic-pdf']];
        $writes = $files->prepare($tables, $assets, 12, 'synthetic-run');
        self::assertStringStartsWith('sup-12/', $tables['purchase_invoices'][91]['pdf_path']);
        return [$files, $writes];
    }

    private function remove(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') {
            $child = $path . '/' . $entry; if (is_dir($child)) $this->remove($child); else @unlink($child);
        }
        @rmdir($path);
    }
}
