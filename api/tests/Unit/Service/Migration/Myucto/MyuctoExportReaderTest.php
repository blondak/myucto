<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Myucto;

use MyInvoice\Service\Migration\Myucto\MyuctoExportReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class MyuctoExportReaderTest extends TestCase
{
    private array $paths = [];
    protected function tearDown(): void { foreach ($this->paths as $path) @unlink($path); }

    public function testExistingSingleCompanyFormatAndEncryptedArchive(): void
    {
        foreach (['', 'synthetic-export-password'] as $password) {
            $path = $this->archive([], [], $password);
            $result = (new MyuctoExportReader())->read($path, $password);
            self::assertSame('Syntetická firma', $result['tables']['supplier'][71]['company_name']);
            self::assertSame(['runtime_jobs' => 1], $result['skipped']);
        }
    }

    public function testWrongPasswordIsRejected(): void
    {
        $path = $this->archive([], [], 'synthetic-export-password');
        $this->expectException(RuntimeException::class);
        (new MyuctoExportReader())->read($path, 'different-synthetic-password');
    }

    public function testCorruptPayloadIsRejectedBeforeRowsAreReturned(): void
    {
        $path = $this->archive(); $zip = new ZipArchive(); $zip->open($path);
        $zip->addFromString('data/supplier.jsonl', '{"id":71,"company_name":"changed"}' . "\n"); $zip->close();
        $this->expectException(RuntimeException::class);
        (new MyuctoExportReader())->read($path);
    }

    public function testTraversalIsRejectedWithoutExtraction(): void
    {
        $path = $this->archive(); $zip = new ZipArchive(); $zip->open($path);
        $zip->addFromString('../outside.txt', 'synthetic'); $zip->close();
        $this->expectException(RuntimeException::class);
        (new MyuctoExportReader())->read($path);
    }

    public function testFutureFormatIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new MyuctoExportReader())->read($this->archive(['version' => 999]));
    }

    public function testWrongRowCountIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new MyuctoExportReader())->read($this->archive([], ['supplier' => ['rows' => 2, 'entry' => 'data/supplier.jsonl']]));
    }

    public function testDuplicateSourceIdsAreRejected(): void
    {
        $path = $this->archive([], [], '', ['supplier' => [['id' => 71], ['id' => 71]]]);
        $this->expectException(RuntimeException::class);
        (new MyuctoExportReader())->read($path);
    }

    public function testMultipleSourceCompaniesAreRejected(): void
    {
        $path = $this->archive([], [], '', ['supplier' => [['id' => 71], ['id' => 72]]]);
        $this->expectException(RuntimeException::class);
        (new MyuctoExportReader())->read($path);
    }

    public function testLimitedDateRangeIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new MyuctoExportReader())->read($this->archive(['range' => ['from' => '2090-01-01', 'to' => null]]));
    }

    public function testOriginalDocumentBytesAndBinaryStatementAreRead(): void
    {
        $path = $this->archive([], [], '', ['purchase_invoices' => [['id' => 81, 'pdf_path' => 'sup-71/original.pdf']]]);
        $zip = new ZipArchive(); $zip->open($path);
        $manifest = json_decode($zip->getFromName('manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        $bytes = "%PDF-synthetic\0";
        $zip->addFromString('doklady/original.pdf', $bytes);
        $manifest['checksums']['doklady/original.pdf'] = ['size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        $manifest['restore']['documents'] = [['entry' => 'doklady/original.pdf', 'storage_path' => 'purchase-invoices/sup-71/original.pdf']];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR)); $zip->close();
        $result = (new MyuctoExportReader())->read($path);
        self::assertSame($bytes, $result['assets'][0]['content']);
        self::assertSame('purchase_invoices', $result['assets'][0]['table']);
    }

    private function archive(array $overrides = [], array $tableOverrides = [], string $password = '', array $rows = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'myucto-export-test-'); $this->paths[] = $path;
        $zip = new ZipArchive(); self::assertTrue($zip->open($path, ZipArchive::OVERWRITE));
        $rows += ['supplier' => [['id' => 71, 'company_name' => 'Syntetická firma']], 'runtime_jobs' => [['id' => 501]]];
        $tables = []; $checksums = [];
        foreach ($rows as $table => $records) {
            $entry = 'data/' . $table . '.jsonl';
            $data = implode("\n", array_map(static fn ($r): string => json_encode($r, JSON_THROW_ON_ERROR), $records)) . "\n";
            $zip->addFromString($entry, $data);
            if ($password !== '') $zip->setEncryptionName($entry, ZipArchive::EM_AES_256, $password);
            $checksums[$entry] = ['sha256' => hash('sha256', $data), 'size' => strlen($data)];
            $tables[$table] = ['entry' => $entry, 'rows' => count($records)];
        }
        $manifest = $overrides + ['format' => 'myucto-instance-export', 'version' => 6, 'supplier' => ['id' => 71],
            'restore' => ['available' => true], 'sections' => ['data' => ['tables' => $tableOverrides + $tables]], 'checksums' => $checksums];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        if ($password !== '') $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256, $password);
        self::assertTrue($zip->close()); return $path;
    }
}
