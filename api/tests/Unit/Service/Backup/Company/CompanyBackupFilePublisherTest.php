<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupFileAreaRootResolver;
use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupFilePublicationPlan;
use MyInvoice\Service\Backup\Company\CompanyBackupFilePublisher;
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

final class CompanyBackupFilePublisherTest extends TestCase
{
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->removeTree($root);
        }
    }

    public function testPublishesOneStagedBlobToEveryTargetAndRollsItBack(): void
    {
        [$snapshot, $inventory, $contents] = $this->context();
        $plan = CompanyBackupFilePublicationPlan::fromInventory(
            $inventory,
            $snapshot,
            7,
            41,
        );

        self::assertSame(2, $plan->presentEntryCount());
        self::assertSame(1, $plan->missingEntryCount());
        self::assertCount(1, $plan->archivePaths());
        self::assertSame([
            'sup-41-brand-11-aaaaaaaaaaaa.png',
            'sup-41-brand-12-bbbbbbbbbbbb.png',
            'sup-41.png',
        ], array_map(
            static fn ($entry): string => $entry->targetPath,
            $plan->entries,
        ));

        $staged = $this->stage($inventory, $contents);
        $live = $this->root('live');
        $published = (new CompanyBackupFilePublisher(
            new PublisherFileAreaRootResolver($live),
        ))->publish($plan, $staged);

        $logoRoot = $live . DIRECTORY_SEPARATOR . 'supplier-logos';
        $brand = $logoRoot . DIRECTORY_SEPARATOR
            . 'sup-41-brand-11-aaaaaaaaaaaa.png';
        $supplier = $logoRoot . DIRECTORY_SEPARATOR . 'sup-41.png';
        self::assertSame(2, $published->count());
        self::assertSame('synthetic-logo', file_get_contents($brand));
        self::assertSame('synthetic-logo', file_get_contents($supplier));
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0640, fileperms($brand) & 0777);
            self::assertSame(0750, fileperms($logoRoot) & 0777);
        }
        self::assertFileDoesNotExist(
            $logoRoot . DIRECTORY_SEPARATOR
                . 'sup-41-brand-12-bbbbbbbbbbbb.png',
        );
        $published->verify();

        $published->rollback();
        $published->rollback();
        self::assertFileDoesNotExist($brand);
        self::assertFileDoesNotExist($supplier);
        self::assertDirectoryDoesNotExist($logoRoot);
        $staged->close();
    }

    public function testExistingTargetStopsPublicationAndPreservesForeignFile(): void
    {
        [$snapshot, $inventory, $contents] = $this->context();
        $plan = CompanyBackupFilePublicationPlan::fromInventory(
            $inventory,
            $snapshot,
            7,
            41,
        );
        $staged = $this->stage($inventory, $contents);
        $live = $this->root('collision');
        $logoRoot = $live . DIRECTORY_SEPARATOR . 'supplier-logos';
        self::assertTrue(mkdir($logoRoot, 0750));
        $foreign = $logoRoot . DIRECTORY_SEPARATOR . 'sup-41.png';
        self::assertSame(7, file_put_contents($foreign, 'foreign'));

        try {
            (new CompanyBackupFilePublisher(
                new PublisherFileAreaRootResolver($live),
            ))->publish($plan, $staged);
            self::fail('Existující cílový soubor se nesmí přepsat.');
        } catch (CompanyBackupFileRestoreException $e) {
            self::assertSame('file_restore_destination_exists', $e->errorCode);
            self::assertSame('file-area:supplier-logos', $e->registryKey);
            self::assertStringNotContainsString('sup-41.png', $e->getMessage());
        }

        self::assertSame('foreign', file_get_contents($foreign));
        self::assertFileDoesNotExist(
            $logoRoot . DIRECTORY_SEPARATOR
                . 'sup-41-brand-11-aaaaaaaaaaaa.png',
        );
        $staged->close();
    }

    public function testRollbackRefusesToDeletePublishedFileChangedByAnotherActor(): void
    {
        [$snapshot, $inventory, $contents] = $this->context();
        $plan = CompanyBackupFilePublicationPlan::fromInventory(
            $inventory,
            $snapshot,
            7,
            41,
        );
        $staged = $this->stage($inventory, $contents);
        $live = $this->root('changed');
        $published = (new CompanyBackupFilePublisher(
            new PublisherFileAreaRootResolver($live),
        ))->publish($plan, $staged);
        $changed = $live . DIRECTORY_SEPARATOR . 'supplier-logos'
            . DIRECTORY_SEPARATOR . 'sup-41-brand-11-aaaaaaaaaaaa.png';
        self::assertSame(7, file_put_contents($changed, 'changed'));

        try {
            $published->rollback();
            self::fail('Rollback nesmí odstranit mezitím změněný cíl.');
        } catch (CompanyBackupFileRestoreException $e) {
            self::assertSame('file_restore_rollback_unsafe', $e->errorCode);
        }

        self::assertSame('changed', file_get_contents($changed));
        self::assertFileDoesNotExist(
            $live . DIRECTORY_SEPARATOR . 'supplier-logos'
                . DIRECTORY_SEPARATOR . 'sup-41.png',
        );
        $staged->close();
    }

    public function testReleasedPublicationKeepsFiles(): void
    {
        [$snapshot, $inventory, $contents] = $this->context();
        $plan = CompanyBackupFilePublicationPlan::fromInventory(
            $inventory,
            $snapshot,
            7,
            41,
        );
        $staged = $this->stage($inventory, $contents);
        $live = $this->root('released');
        $published = (new CompanyBackupFilePublisher(
            new PublisherFileAreaRootResolver($live),
        ))->publish($plan, $staged);
        $target = $live . DIRECTORY_SEPARATOR . 'supplier-logos'
            . DIRECTORY_SEPARATOR . 'sup-41.png';

        $published->verify();
        $published->release();
        $published->rollback();
        unset($published);

        self::assertSame('synthetic-logo', file_get_contents($target));
        $staged->close();
    }

    public function testRejectsStagingScopeBeforeTouchingLiveRoots(): void
    {
        [$snapshot, $inventory, $contents] = $this->context();
        $missingInventory = $this->withoutPresentFiles($inventory, $snapshot);
        $plan = CompanyBackupFilePublicationPlan::fromInventory(
            $missingInventory,
            $snapshot,
            7,
            41,
        );
        $staged = $this->stage($inventory, $contents);
        $live = $this->root('scope');

        try {
            (new CompanyBackupFilePublisher(
                new PublisherFileAreaRootResolver($live),
            ))->publish($plan, $staged);
            self::fail('Jiný staged inventář nesmí vstoupit do publikace.');
        } catch (CompanyBackupFileRestoreException $e) {
            self::assertSame('file_restore_staging_scope_mismatch', $e->errorCode);
        }

        self::assertSame([], $this->entries($live));
        $staged->close();
    }

    public function testMissingOnlyPlanDoesNotCreateLiveRoot(): void
    {
        [$snapshot, $inventory] = $this->context();
        $missingInventory = $this->withoutPresentFiles($inventory, $snapshot);
        $plan = CompanyBackupFilePublicationPlan::fromInventory(
            $missingInventory,
            $snapshot,
            7,
            41,
        );
        $staged = $this->stage($missingInventory, []);
        $live = $this->root('missing');
        $published = (new CompanyBackupFilePublisher(
            new PublisherFileAreaRootResolver($live),
        ))->publish($plan, $staged);

        self::assertSame(0, $published->count());
        $published->verify();
        $published->release();
        self::assertSame([], $this->entries($live));
        $staged->close();
    }

    public function testPublishesEmptyFileWithoutSyntheticChunk(): void
    {
        [$snapshot, $inventory, $contents] = $this->context('');
        $plan = CompanyBackupFilePublicationPlan::fromInventory(
            $inventory,
            $snapshot,
            7,
            41,
        );
        $staged = $this->stage($inventory, $contents);
        $live = $this->root('empty');
        $published = (new CompanyBackupFilePublisher(
            new PublisherFileAreaRootResolver($live),
        ))->publish($plan, $staged);
        $target = $live . DIRECTORY_SEPARATOR . 'supplier-logos'
            . DIRECTORY_SEPARATOR . 'sup-41.png';

        self::assertSame(2, $published->count());
        self::assertSame('', file_get_contents($target));
        $published->rollback();
        $staged->close();
    }

    public function testRejectsSymlinkedDestinationRoot(): void
    {
        [$snapshot, $inventory, $contents] = $this->context();
        $plan = CompanyBackupFilePublicationPlan::fromInventory(
            $inventory,
            $snapshot,
            7,
            41,
        );
        $staged = $this->stage($inventory, $contents);
        $live = $this->root('symlink');
        $outside = $this->root('outside');
        $link = $live . DIRECTORY_SEPARATOR . 'supplier-logos';
        if (!@symlink($outside, $link)) {
            $staged->close();
            self::markTestSkipped(
                'Platforma nepovoluje vytvoření testovacího symlinku.',
            );
        }

        try {
            (new CompanyBackupFilePublisher(
                new PublisherFileAreaRootResolver($live),
            ))->publish($plan, $staged);
            self::fail('Publisher nesmí následovat symlink cílového kořene.');
        } catch (CompanyBackupFileRestoreException $e) {
            self::assertSame('file_restore_destination_root_unsafe', $e->errorCode);
        }

        self::assertSame([], $this->entries($outside));
        $staged->close();
    }

    public function testPlanRejectsCaseInsensitiveTargetCollision(): void
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $snapshot = TenantDataRegistrySnapshot::fromRegistry(
            new TenantDataRegistry(1, [
                $this->supplierDefinition($profile, ['a_path', 'z_path']),
                new TenantDataDefinition(
                    'file-area:documents',
                    TenantDataObjectKind::FileArea,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    [
                        'file_policy' => 'historical_optional',
                        'path_policy' => 'relative',
                        'file_owners' => [
                            $this->owner('a_path'),
                            $this->owner('z_path'),
                        ],
                        'ownership' => ['strategy' => 'database_references'],
                        'storage_subdirectory' => 'documents',
                    ],
                ),
            ], [$profile]),
            $profile,
        );
        $hash = hash('sha256', 'same');
        $archivePath = 'files/documents/' . $hash . '.txt';
        $inventory = CompanyBackupFileInventory::fromArray([
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => [[
                'registry_key' => 'file-area:documents',
                'order' => 1,
                'entries' => [
                    $this->entry('A.txt', $archivePath, $hash, 'a_path', 4),
                    $this->entry('a.txt', $archivePath, $hash, 'z_path', 4),
                ],
            ]],
        ], $snapshot);

        try {
            CompanyBackupFilePublicationPlan::fromInventory(
                $inventory,
                $snapshot,
                7,
                41,
            );
            self::fail('Windows kolize cílových cest musí plán zastavit.');
        } catch (CompanyBackupFileRestoreException $e) {
            self::assertSame('file_restore_target_path_duplicate', $e->errorCode);
            self::assertSame('file-area:documents', $e->registryKey);
        }
    }

    /**
     * @return array{
     *   TenantDataRegistrySnapshot,
     *   CompanyBackupFileInventory,
     *   array<string,string>
     * }
     */
    private function context(string $content = 'synthetic-logo'): array
    {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $snapshot = TenantDataRegistrySnapshot::fromRegistry(
            new TenantDataRegistry(1, [
                $this->supplierDefinition(
                    $profile,
                    ['brand_logo_path', 'logo_path', 'missing_logo_path'],
                ),
                new TenantDataDefinition(
                    'file-area:supplier-logos',
                    TenantDataObjectKind::FileArea,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    [
                        'file_policy' => 'historical_optional',
                        'path_policy' => 'supplier_logo',
                        'file_owners' => [
                            $this->owner('brand_logo_path'),
                            $this->owner('logo_path'),
                            $this->owner('missing_logo_path'),
                        ],
                        'ownership' => ['strategy' => 'database_references'],
                        'storage_subdirectory' => 'supplier-logos',
                    ],
                ),
            ], [$profile]),
            $profile,
        );
        $hash = hash('sha256', $content);
        $archivePath = 'files/supplier-logos/' . $hash . '.png';
        $inventory = CompanyBackupFileInventory::fromArray([
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => [[
                'registry_key' => 'file-area:supplier-logos',
                'order' => 1,
                'entries' => [
                    $this->entry(
                        'sup-7-brand-11-aaaaaaaaaaaa.png',
                        $archivePath,
                        $hash,
                        'brand_logo_path',
                        strlen($content),
                    ),
                    $this->entry(
                        'sup-7-brand-12-bbbbbbbbbbbb.png',
                        null,
                        null,
                        'missing_logo_path',
                    ),
                    $this->entry(
                        'sup-7.png',
                        $archivePath,
                        $hash,
                        'logo_path',
                        strlen($content),
                    ),
                ],
            ]],
        ], $snapshot);
        return [$snapshot, $inventory, [$archivePath => $content]];
    }

    /** @param list<string> $columns */
    private function supplierDefinition(
        string $profile,
        array $columns,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
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
                'secrets' => [],
                'company_backup' => [
                    'data_columns' => ['id', ...$columns],
                    'embedded_references' => [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' => [],
                    'restore_overrides' => [],
                ],
            ],
        );
    }

    /** @return array<string,mixed> */
    private function owner(string $column): array
    {
        return [
            'registry_key' => 'table:supplier',
            'column' => $column,
            'path' => [],
            'stored_prefix' => 'storage/supplier-logos/',
        ];
    }

    /** @return array<string,mixed> */
    private function entry(
        string $sourcePath,
        ?string $archivePath,
        ?string $sha256,
        string $column,
        ?int $bytes = null,
    ): array {
        return [
            'source_path' => $sourcePath,
            'archive_path' => $archivePath,
            'state' => $archivePath === null ? 'missing' : 'present',
            'bytes' => $bytes,
            'sha256' => $sha256,
            'owners' => [[
                'registry_key' => 'table:supplier',
                'primary_key' => ['id' => 7],
                'column' => $column,
                'path' => [],
            ]],
        ];
    }

    /** @param array<string,string> $contents */
    private function stage(
        CompanyBackupFileInventory $inventory,
        array $contents,
    ): \MyInvoice\Service\Backup\Company\CompanyBackupStagedFileSet {
        $root = $this->root('staging');
        return (new CompanyBackupFileRestoreStager(
            new PublisherFileStagingRootResolver($root),
        ))->stage(
            new PublisherImportFileSource($inventory, $contents),
            self::BACKUP_ID,
        );
    }

    private function withoutPresentFiles(
        CompanyBackupFileInventory $inventory,
        TenantDataRegistrySnapshot $snapshot,
    ): CompanyBackupFileInventory {
        $value = $inventory->toArray();
        foreach ($value['areas'] as &$area) {
            foreach ($area['entries'] as &$entry) {
                $entry['archive_path'] = null;
                $entry['state'] = 'missing';
                $entry['bytes'] = null;
                $entry['sha256'] = null;
            }
            unset($entry);
        }
        unset($area);
        return CompanyBackupFileInventory::fromArray($value, $snapshot);
    }

    private function root(string $label): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'myucto-file-publish-' . $label . '-'
            . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $this->roots[] = $root;
        return $root;
    }

    /** @return list<string> */
    private function entries(string $root): array
    {
        $entries = scandir($root);
        self::assertIsArray($entries);
        return array_values(array_diff($entries, ['.', '..']));
    }

    private function removeTree(string $root): void
    {
        if (!is_dir($root) || is_link($root)) {
            @unlink($root);
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($root);
    }
}

/** @internal */
final readonly class PublisherFileAreaRootResolver implements
    CompanyBackupFileAreaRootResolver
{
    public function __construct(private string $root) {}

    public function resolve(string $storageSubdirectory): string
    {
        return $this->root . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $storageSubdirectory);
    }
}

/** @internal */
final readonly class PublisherFileStagingRootResolver implements
    CompanyBackupFileStagingRootResolver
{
    public function __construct(private string $root) {}

    public function root(): string
    {
        return $this->root;
    }
}

/** @internal */
final class PublisherImportFileSource implements CompanyBackupImportFileSource
{
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
        $content = $this->contents[$archivePath] ?? null;
        if (!is_string($content)) {
            throw new \RuntimeException('synthetic_file_missing');
        }
        if ($content !== '') {
            $chunkVisitor($content);
        }
        return strlen($content);
    }
}
