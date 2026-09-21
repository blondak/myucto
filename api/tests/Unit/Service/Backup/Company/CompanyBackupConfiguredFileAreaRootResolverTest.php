<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use InvalidArgumentException;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Backup\Company\CompanyBackupConfiguredFileAreaRootResolver;
use PHPUnit\Framework\TestCase;

final class CompanyBackupConfiguredFileAreaRootResolverTest extends TestCase
{
    private string|false $originalDataDir;

    protected function setUp(): void
    {
        $this->originalDataDir = getenv('MYINVOICE_DATA_DIR');
    }

    protected function tearDown(): void
    {
        putenv($this->originalDataDir === false ? 'MYINVOICE_DATA_DIR'
            : 'MYINVOICE_DATA_DIR=' . $this->originalDataDir);
    }

    public function testExplicitArchivePathsTakePrecedenceOverUploads(): void
    {
        $resolver = new CompanyBackupConfiguredFileAreaRootResolver(new Config([
            'invoice' => ['import_archive_storage' => 'relative/issued'],
            'purchase_invoice' => ['archive_storage' => '/custom/purchased'],
            'storage' => ['uploads_dir' => '/data/storage/uploads'],
        ]));

        self::assertSame('relative/issued', $resolver->resolve('invoices-imported'));
        self::assertSame('/custom/purchased', $resolver->resolve('purchase-invoices'));
    }

    public function testUploadsParentIsFallbackForBothArchiveAreas(): void
    {
        $resolver = new CompanyBackupConfiguredFileAreaRootResolver(new Config([
            'storage' => ['uploads_dir' => '/custom/uploads'],
        ]));

        self::assertSame('/custom/invoices-imported', $resolver->resolve('invoices-imported'));
        self::assertSame('/custom/purchase-invoices', $resolver->resolve('purchase-invoices'));
    }

    public function testRuntimeFallbackAndUnrelatedAreasRespectDataDir(): void
    {
        putenv('MYINVOICE_DATA_DIR=/synthetic/data');
        $resolver = new CompanyBackupConfiguredFileAreaRootResolver(new Config([
            'storage' => ['uploads_dir' => '/custom/uploads'],
        ]));

        self::assertSame('/synthetic/data/storage/invoices', $resolver->resolve('invoices'));
        self::assertSame('/synthetic/data/storage/invoices/child', $resolver->resolve('invoices/child'));

        $fallback = new CompanyBackupConfiguredFileAreaRootResolver(new Config([]));
        self::assertSame('/synthetic/data/storage/invoices-imported', $fallback->resolve('invoices-imported'));
        self::assertSame('/synthetic/data/storage/purchase-invoices', $fallback->resolve('purchase-invoices'));
    }

    public function testNulPathIsRejected(): void
    {
        $resolver = new CompanyBackupConfiguredFileAreaRootResolver(new Config([
            'invoice' => ['import_archive_storage' => "bad\0path"],
        ]));

        $this->expectException(InvalidArgumentException::class);
        $resolver->resolve('invoices-imported');
    }
}
