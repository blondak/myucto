<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupDataWriteException;
use MyInvoice\Service\Backup\Company\CompanyBackupJsonlWriter;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PHPUnit\Framework\TestCase;

final class CompanyBackupJsonlWriterTest extends TestCase
{
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

    public function testStreamsCanonicalRowsAndReturnsManifestMetadata(): void
    {
        $first = $this->unusedPath();
        $second = $this->unusedPath();
        $writer = new CompanyBackupJsonlWriter();
        $object = $writer->write(
            $this->definition(),
            3,
            (static function (): \Generator {
                yield ['name' => 'Synthetic s.r.o.', 'id' => 2, 'active' => true];
                yield ['note' => null, 'id' => 10];
            })(),
            $first,
        );
        $sameObject = $writer->write(
            $this->definition(),
            3,
            [
                ['active' => true, 'id' => 2, 'name' => 'Synthetic s.r.o.'],
                ['id' => 10, 'note' => null],
            ],
            $second,
        );

        $expected = "{\"active\":true,\"id\":2,\"name\":\"Synthetic s.r.o.\"}\n"
            . "{\"id\":10,\"note\":null}\n";
        self::assertSame($expected, file_get_contents($first));
        self::assertSame($expected, file_get_contents($second));
        self::assertSame('table:supplier', $object->registryKey);
        self::assertSame('data/table-supplier.jsonl', $object->path);
        self::assertSame(3, $object->order);
        self::assertSame(2, $object->rows);
        self::assertSame(strlen($expected), $object->bytes);
        self::assertSame(hash('sha256', $expected), $object->sha256);
        self::assertSame($object->toArray(), $sameObject->toArray());
        if (DIRECTORY_SEPARATOR !== '\\') {
            self::assertSame(0600, fileperms($first) & 0777);
        }
    }

    public function testEmptyStreamStillCreatesInventoriedEmptyJsonl(): void
    {
        $path = $this->unusedPath();

        $object = (new CompanyBackupJsonlWriter())->write(
            $this->definition(),
            1,
            [],
            $path,
        );

        self::assertSame('', file_get_contents($path));
        self::assertSame(0, $object->rows);
        self::assertSame(0, $object->bytes);
        self::assertSame(hash('sha256', ''), $object->sha256);
    }

    public function testInvalidRowRemovesPartialPlaintextFile(): void
    {
        $path = $this->unusedPath();

        try {
            (new CompanyBackupJsonlWriter())->write(
                $this->definition(),
                1,
                [
                    ['id' => 1],
                    ['invalid-list-row'],
                ],
                $path,
            );
            self::fail('Neplatný řádek nesmí zanechat částečný plaintext export.');
        } catch (CompanyBackupDataWriteException $e) {
            self::assertSame('data_row_invalid', $e->errorCode);
            self::assertSame('table:supplier', $e->registryKey);
            self::assertSame(2, $e->rowNumber);
        }

        self::assertFileDoesNotExist($path);
    }

    public function testInvalidUtf8IsRejectedWithoutLossySubstitution(): void
    {
        $path = $this->unusedPath();

        try {
            (new CompanyBackupJsonlWriter())->write(
                $this->definition(),
                1,
                [['id' => 1, 'binary' => "\xB1\x31"]],
                $path,
            );
            self::fail('Binární hodnota bez explicitního kodeku nesmí změnit bajty substitucí.');
        } catch (CompanyBackupDataWriteException $e) {
            self::assertSame('data_row_invalid', $e->errorCode);
            self::assertSame(1, $e->rowNumber);
        }

        self::assertFileDoesNotExist($path);
    }

    public function testEntryLimitStopsAndRemovesPartialFile(): void
    {
        $path = $this->unusedPath();
        $writer = new CompanyBackupJsonlWriter(new CompanyBackupArchiveLimits(
            maxArchiveBytes: 100,
            maxEntries: 10,
            maxEntryBytes: 20,
            maxExpandedBytes: 100,
            maxCompressionRatio: 100,
            maxManifestBytes: 20,
            maxChecksumsBytes: 20,
        ));

        try {
            $writer->write(
                $this->definition(),
                1,
                [['id' => 1], ['description' => str_repeat('x', 20)]],
                $path,
            );
            self::fail('JSONL nesmí překročit limit jedné položky archivu.');
        } catch (CompanyBackupDataWriteException $e) {
            self::assertSame('data_entry_size_exceeded', $e->errorCode);
            self::assertSame(2, $e->rowNumber);
        }

        self::assertFileDoesNotExist($path);
    }

    public function testDedicatedRowLimitStopsAndRemovesPartialFile(): void
    {
        $path = $this->unusedPath();
        $writer = new CompanyBackupJsonlWriter(new CompanyBackupArchiveLimits(
            maxArchiveBytes: 1_000,
            maxEntries: 10,
            maxEntryBytes: 500,
            maxExpandedBytes: 1_000,
            maxCompressionRatio: 100,
            maxManifestBytes: 200,
            maxChecksumsBytes: 200,
            maxDataRowBytes: 40,
        ));

        try {
            $writer->write(
                $this->definition(),
                1,
                [['description' => str_repeat('x', 40)]],
                $path,
            );
            self::fail('Jediný JSONL řádek nesmí obejít vlastní paměťový limit.');
        } catch (CompanyBackupDataWriteException $e) {
            self::assertSame('data_row_size_exceeded', $e->errorCode);
            self::assertSame(1, $e->rowNumber);
        }

        self::assertFileDoesNotExist($path);
    }

    public function testSourceFailureRemovesPartialPlaintextFile(): void
    {
        $path = $this->unusedPath();
        $rows = (static function (): \Generator {
            yield ['id' => 1];
            throw new \RuntimeException('synthetic source failure');
        })();

        try {
            (new CompanyBackupJsonlWriter())->write(
                $this->definition(),
                1,
                $rows,
                $path,
            );
            self::fail('Selhání zdroje nesmí zanechat částečný plaintext export.');
        } catch (CompanyBackupDataWriteException $e) {
            self::assertSame('data_source_failed', $e->errorCode);
            self::assertSame(2, $e->rowNumber);
        }

        self::assertFileDoesNotExist($path);
    }

    public function testExistingDestinationIsNeverOverwritten(): void
    {
        $path = $this->temporaryFile('existing-user-data');

        try {
            (new CompanyBackupJsonlWriter())->write(
                $this->definition(),
                1,
                [['id' => 1]],
                $path,
            );
            self::fail('Existující cíl JSONL nesmí být přepsaný.');
        } catch (CompanyBackupDataWriteException $e) {
            self::assertSame('data_destination_exists', $e->errorCode);
            self::assertNull($e->rowNumber);
        }

        self::assertSame('existing-user-data', file_get_contents($path));
    }

    public function testNonPayloadRegistryObjectFailsBeforeCreatingFile(): void
    {
        $path = $this->unusedPath();
        $definition = new TenantDataDefinition(
            'table:derived_cache',
            TenantDataObjectKind::Table,
            TenantDataPolicy::RuntimeDerived,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            ['ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id']],
        );

        $this->expectException(\InvalidArgumentException::class);
        try {
            (new CompanyBackupJsonlWriter())->write($definition, 1, [], $path);
        } finally {
            self::assertFileDoesNotExist($path);
        }
    }

    private function definition(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:supplier',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantRoot,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => ['strategy' => 'selected_supplier', 'column' => 'id'],
            ],
        );
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'myucto-company-jsonl-');
        if ($path === false) {
            throw new \RuntimeException('Nelze vytvořit dočasný soubor testu.');
        }
        $this->paths[] = $path;
        if (file_put_contents($path, $contents) !== strlen($contents)) {
            throw new \RuntimeException('Nelze zapsat dočasný soubor testu.');
        }
        return $path;
    }

    private function unusedPath(): string
    {
        $path = $this->temporaryFile('');
        @unlink($path);
        $path .= '.jsonl';
        $this->paths[] = $path;
        return $path;
    }
}
