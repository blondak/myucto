<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataRowSource;
use MyInvoice\Service\Backup\Company\CompanyBackupMachineSnapshotExporter;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupMachineSnapshotExporterTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                if (is_file($path) || is_link($path)) {
                    @unlink($path);
                }
            }
            @rmdir($directory);
        }
    }

    public function testExportsEveryPayloadDefinitionInsideOneSnapshot(): void
    {
        $pdo = $this->transactionalPdo();
        $source = new class implements CompanyBackupDataRowSource {
            /** @var list<string> */
            public array $calls = [];

            public function rows(
                PDO $snapshot,
                int $supplierId,
                TenantDataDefinition $definition,
            ): iterable {
                $this->calls[] = $definition->key . '@' . $supplierId;
                return match ($definition->key) {
                    'table:invoices' => [
                        ['supplier_id' => $supplierId, 'id' => 20],
                        ['id' => 21, 'supplier_id' => $supplierId],
                    ],
                    'table:supplier' => [
                        ['name' => 'Synthetic s.r.o.', 'id' => $supplierId],
                    ],
                    default => throw new \LogicException('Neočekávaný objekt.'),
                };
            }
        };
        $directory = $this->workDirectory();

        $snapshot = (new CompanyBackupMachineSnapshotExporter())->export(
            $pdo,
            $this->registrySnapshot(),
            7,
            $directory,
            $source,
        );

        self::assertSame(['table:invoices@7', 'table:supplier@7'], $source->calls);
        self::assertSame(
            ['table:invoices', 'table:supplier'],
            array_map(
                static fn ($object): string => $object->registryKey,
                $snapshot->inventory->objects,
            ),
        );
        self::assertSame(
            ['data/table-invoices.jsonl', 'data/table-supplier.jsonl'],
            array_keys($snapshot->sourceFiles),
        );
        self::assertSame(
            "{\"id\":20,\"supplier_id\":7}\n{\"id\":21,\"supplier_id\":7}\n",
            file_get_contents($snapshot->sourceFiles['data/table-invoices.jsonl']),
        );
        self::assertSame(
            "{\"id\":7,\"name\":\"Synthetic s.r.o.\"}\n",
            file_get_contents($snapshot->sourceFiles['data/table-supplier.jsonl']),
        );
        foreach ($snapshot->sourceFiles as $sourcePath) {
            self::assertSame($directory, dirname($sourcePath));
            self::assertMatchesRegularExpression(
                '/^company-data-[0-9a-f]{32}\.jsonl$/D',
                basename($sourcePath),
            );
        }
    }

    public function testLaterSourceFailureRollsBackAndRemovesCompletedPlaintextFiles(): void
    {
        $pdo = $this->transactionalPdo(commit: false);
        $source = new class implements CompanyBackupDataRowSource {
            public function rows(
                PDO $snapshot,
                int $supplierId,
                TenantDataDefinition $definition,
            ): iterable {
                if ($definition->key === 'table:supplier') {
                    throw new \DomainException('synthetic supplier source failure');
                }
                return [['id' => 20, 'supplier_id' => $supplierId]];
            }
        };
        $directory = $this->workDirectory();

        try {
            (new CompanyBackupMachineSnapshotExporter())->export(
                $pdo,
                $this->registrySnapshot(),
                7,
                $directory,
                $source,
            );
            self::fail('Neúplný snapshot se nesmí vrátit volajícímu.');
        } catch (\DomainException $e) {
            self::assertSame('synthetic supplier source failure', $e->getMessage());
        }

        self::assertSame([], glob($directory . DIRECTORY_SEPARATOR . '*') ?: []);
    }

    private function transactionalPdo(bool $commit = true): PDO
    {
        $pdo = $this->createMock(PDO::class);
        if ($commit) {
            $pdo->expects(self::exactly(3))
                ->method('inTransaction')
                ->willReturnOnConsecutiveCalls(false, true, true);
            $pdo->expects(self::once())->method('commit')->willReturn(true);
            $pdo->expects(self::never())->method('rollBack');
        } else {
            $pdo->expects(self::exactly(3))
                ->method('inTransaction')
                ->willReturnOnConsecutiveCalls(false, true, true);
            $pdo->expects(self::never())->method('commit');
            $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        }
        $pdo->expects(self::exactly(2))->method('exec')->willReturn(0);
        return $pdo;
    }

    private function registrySnapshot(): TenantDataRegistrySnapshot
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [
                new TenantDataDefinition(
                    'table:supplier',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::TenantRoot,
                    [$profile],
                    ['ownership' => ['strategy' => 'selected_supplier', 'column' => 'id']],
                ),
                new TenantDataDefinition(
                    'table:invoices',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    ['ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id']],
                ),
                new TenantDataDefinition(
                    'table:derived_cache',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::RuntimeDerived,
                    [$profile],
                    ['ownership' => ['strategy' => 'supplier_id', 'column' => 'supplier_id']],
                ),
                new TenantDataDefinition(
                    'file-area:invoice-pdf',
                    TenantDataObjectKind::FileArea,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    ['ownership' => ['strategy' => 'invoice_reference']],
                ),
            ],
            [$profile],
        ), $profile);
    }

    private function workDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'myucto-machine-snapshot-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Nelze vytvořit pracovní adresář testu.');
        }
        $this->directories[] = $directory;
        return $directory;
    }
}
