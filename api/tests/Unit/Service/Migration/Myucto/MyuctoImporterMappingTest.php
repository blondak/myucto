<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Myucto;

use MyInvoice\Service\Migration\Myucto\MyuctoImporter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MyuctoImporterMappingTest extends TestCase
{
    public function testOwnerWithoutPhysicalForeignKeyIsMapped(): void
    {
        $reflection = new ReflectionClass(MyuctoImporter::class);
        $importer = $reflection->newInstanceWithoutConstructor();
        $schema = ['columns' => ['bank_statements' => ['supplier_id' => []]], 'foreignKeyRows' => []];
        $row = $reflection->getMethod('remap')->invoke($importer, 'bank_statements',
            ['id' => 11, 'supplier_id' => 1], ['bank_statements' => [11 => 31], 'supplier' => [1 => 4]], 1, $schema);
        self::assertSame(4, $row['supplier_id']);
    }

    public function testBankFingerprintMovesToTargetTenantAndPreservesPortableIdentity(): void
    {
        $reflection = new ReflectionClass(MyuctoImporter::class);
        $importer = $reflection->newInstanceWithoutConstructor();
        $portable = hash('sha256', 'synthetic-bank-transaction');
        $schema = ['columns' => ['bank_transactions' => []], 'foreignKeyRows' => []];
        $row = $reflection->getMethod('remap')->invoke($importer, 'bank_transactions',
            ['id' => 12, 'import_fingerprint' => hash('sha256', 'supplier:1:' . $portable), 'portable_fingerprint' => $portable],
            ['bank_transactions' => [12 => 32], 'supplier' => [1 => 4]], 1, $schema);
        self::assertSame(hash('sha256', 'supplier:4:' . $portable), $row['import_fingerprint']);
        self::assertSame($portable, $row['portable_fingerprint']);
    }
}
