<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFileRestoreException;
use MyInvoice\Service\Backup\Company\CompanyBackupFileRestoreStager;
use MyInvoice\Service\Backup\Company\CompanyBackupFileStagingRootResolver;
use MyInvoice\Service\Backup\Company\CompanyBackupImportFileSource;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PHPUnit\Framework\TestCase;

final class CompanyBackupFileRestoreStagerTest extends TestCase
{
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->removeTestRoot($root);
        }
    }

    public function testStagesRegisteredFilesAndOwnsIdempotentCleanup(): void
    {
        $content = "synthetic-logo\0bytes";
        [$source, $archivePath] = $this->source($content, $content);
        $root = $this->root();
        $staged = (new CompanyBackupFileRestoreStager(
            new SyntheticCompanyBackupFileStagingRootResolver($root),
        ))->stage($source, self::BACKUP_ID);

        self::assertSame(1, $staged->count());
        self::assertSame([$archivePath], $staged->archivePaths());
        $path = $staged->pathFor($archivePath);
        self::assertFileExists($path);
        self::assertSame($content, file_get_contents($path));
        self::assertSame(1, $source->reads);
        self::assertStringStartsWith(
            strtolower(str_replace('\\', '/', $root)) . '/',
            strtolower(str_replace('\\', '/', $path)),
        );
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, fileperms($path) & 0777);
            self::assertSame(0700, fileperms($staged->directory()) & 0777);
        }

        $directory = $staged->directory();
        $staged->close();
        $staged->close();
        self::assertDirectoryDoesNotExist($directory);
        self::assertSame([], $this->entries($root));
    }

    public function testRejectsChangedContentAndCleansPartialStaging(): void
    {
        [$source] = $this->source('expected', 'changed');
        $root = $this->root();

        try {
            (new CompanyBackupFileRestoreStager(
                new SyntheticCompanyBackupFileStagingRootResolver($root),
            ))->stage($source, self::BACKUP_ID);
            self::fail('Staging nesmí přijmout obsah odlišný od inventáře.');
        } catch (CompanyBackupFileRestoreException $e) {
            self::assertSame('file_staging_content_mismatch', $e->errorCode);
            self::assertStringNotContainsString('expected', $e->getMessage());
            self::assertStringNotContainsString('changed', $e->getMessage());
        }

        self::assertSame([], $this->entries($root));
    }

    public function testSourceFailureWinsAndCleansPartialStaging(): void
    {
        [$source] = $this->source('expected', 'expected');
        $source->failure = new \RuntimeException('synthetic_file_read_failure');
        $root = $this->root();

        try {
            (new CompanyBackupFileRestoreStager(
                new SyntheticCompanyBackupFileStagingRootResolver($root),
            ))->stage($source, self::BACKUP_ID);
            self::fail('Chyba zdroje musí staging zastavit.');
        } catch (\RuntimeException $e) {
            self::assertSame('synthetic_file_read_failure', $e->getMessage());
        }

        self::assertSame([], $this->entries($root));
    }

    /**
     * @return array{SyntheticCompanyBackupImportFileSource,string}
     */
    private function source(
        string $expectedContent,
        string $actualContent,
    ): array {
        $snapshot = $this->registry();
        $sha256 = hash('sha256', $expectedContent);
        $archivePath = 'files/supplier-logos/' . $sha256 . '.png';
        $inventory = CompanyBackupFileInventory::fromArray([
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => [[
                'registry_key' => 'file-area:supplier-logos',
                'order' => 1,
                'entries' => [[
                    'source_path' => 'sup-7-brand-11-abcdef123456.png',
                    'archive_path' => null,
                    'state' => 'missing',
                    'bytes' => null,
                    'sha256' => null,
                    'owners' => [[
                        'registry_key' => 'table:supplier',
                        'primary_key' => ['id' => 7],
                        'column' => 'logo_path',
                        'path' => [],
                    ]],
                ], [
                    'source_path' => 'sup-7.png',
                    'archive_path' => $archivePath,
                    'state' => 'present',
                    'bytes' => strlen($expectedContent),
                    'sha256' => $sha256,
                    'owners' => [[
                        'registry_key' => 'table:supplier',
                        'primary_key' => ['id' => 7],
                        'column' => 'logo_path',
                        'path' => [],
                    ]],
                ]],
            ]],
        ], $snapshot);
        return [new SyntheticCompanyBackupImportFileSource(
            $inventory,
            [$archivePath => $actualContent],
        ), $archivePath];
    }

    private function registry(): TenantDataRegistrySnapshot
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
                    [
                        'primary_key' => ['id'],
                        'ownership' => [
                            'strategy' => 'selected_supplier',
                            'column' => 'id',
                        ],
                    ],
                ),
                new TenantDataDefinition(
                    'file-area:supplier-logos',
                    TenantDataObjectKind::FileArea,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    [
                        'file_policy' => 'historical_optional',
                        'path_policy' => 'supplier_logo',
                        'file_owners' => [[
                            'registry_key' => 'table:supplier',
                            'column' => 'logo_path',
                            'path' => [],
                            'stored_prefix' => 'storage/supplier-logos/',
                        ]],
                        'ownership' => ['strategy' => 'database_references'],
                        'storage_subdirectory' => 'supplier-logos',
                    ],
                ),
            ],
            [$profile],
        ), $profile);
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'myucto-file-stage-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $this->roots[] = $root;
        return $root;
    }

    /** @return list<string> */
    private function entries(string $directory): array
    {
        $entries = scandir($directory);
        self::assertIsArray($entries);
        return array_values(array_diff($entries, ['.', '..']));
    }

    private function removeTestRoot(string $root): void
    {
        if (!is_dir($root) || is_link($root)) {
            return;
        }
        $entries = scandir($root);
        if (!is_array($entries)) {
            return;
        }
        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = $root . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $file) {
                    @unlink($path . DIRECTORY_SEPARATOR . $file);
                }
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($root);
    }
}

/** @internal Testovací stream registrovaných souborů. */
final class SyntheticCompanyBackupImportFileSource implements CompanyBackupImportFileSource
{
    public int $reads = 0;

    public ?\Throwable $failure = null;

    /** @param array<string,string> $contents */
    public function __construct(
        private readonly CompanyBackupFileInventory $inventory,
        private readonly array $contents,
    ) {}

    public function fileInventory(): CompanyBackupFileInventory
    {
        return $this->inventory;
    }

    public function consumeFile(
        string $archivePath,
        callable $chunkVisitor,
    ): int {
        $this->reads++;
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }
        $content = $this->contents[$archivePath] ?? null;
        if (!is_string($content)) {
            throw new \RuntimeException('synthetic_file_missing');
        }
        $middle = intdiv(strlen($content), 2);
        foreach ([substr($content, 0, $middle), substr($content, $middle)] as $chunk) {
            if ($chunk !== '') {
                $chunkVisitor($chunk);
            }
        }
        return strlen($content);
    }
}

/** @internal Testovací runtime kořen file stagingu. */
final readonly class SyntheticCompanyBackupFileStagingRootResolver implements
    CompanyBackupFileStagingRootResolver
{
    public function __construct(private string $root) {}

    public function root(): string
    {
        return $this->root;
    }
}
