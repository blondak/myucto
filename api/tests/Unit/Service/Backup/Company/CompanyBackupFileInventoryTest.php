<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveException;
use MyInvoice\Service\Backup\Company\CompanyBackupFileInventory;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PHPUnit\Framework\TestCase;

final class CompanyBackupFileInventoryTest extends TestCase
{
    public function testParsesPresentAndMissingFilesBoundToRegistryOwners(): void
    {
        $inventory = CompanyBackupFileInventory::fromArray(
            $this->inventory(),
            $this->snapshot(),
        );

        self::assertCount(1, $inventory->areas);
        self::assertSame('file-area:supplier-logos', $inventory->areas[0]->registryKey);
        self::assertCount(2, $inventory->areas[0]->entries);
        self::assertSame('missing', $inventory->areas[0]->entries[0]->state->value);
        self::assertNull($inventory->areas[0]->entries[0]->archivePath);
        self::assertSame('present', $inventory->areas[0]->entries[1]->state->value);
        self::assertSame(
            'table:branding_profiles',
            $inventory->areas[0]->entries[1]->owners[0]['registry_key'],
        );
        self::assertSame($this->inventory(), $inventory->toArray());
    }

    public function testRequiresEveryPayloadFileAreaEvenWhenItHasNoFiles(): void
    {
        $value = $this->inventory();
        $value['areas'] = [];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('file-area:supplier-logos');

        CompanyBackupFileInventory::fromArray($value, $this->snapshot());
    }

    public function testRequiredAreaRejectsMissingSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('required');

        CompanyBackupFileInventory::fromArray(
            $this->inventory(),
            $this->snapshot('required'),
        );
    }

    public function testRejectsArchivePathThatDoesNotCarryContentHash(): void
    {
        $value = $this->inventory();
        $value['areas'][0]['entries'][1]['archive_path'] =
            'files/supplier-logos/' . str_repeat('f', 64) . '.png';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('archive_path');

        CompanyBackupFileInventory::fromArray($value, $this->snapshot());
    }

    public function testRejectsWindowsAbsoluteSourcePath(): void
    {
        $value = $this->inventory();
        $value['areas'][0]['entries'][1]['source_path'] = 'z:/private/logo.png';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('source_path');

        CompanyBackupFileInventory::fromArray($value, $this->snapshot());
    }

    public function testArchiveCoverageIncludesOnlyPresentFiles(): void
    {
        $inventory = CompanyBackupFileInventory::fromArray(
            $this->inventory(),
            $this->snapshot(),
        );
        $entry = $inventory->areas[0]->entries[1];
        self::assertNotNull($entry->archivePath);
        self::assertNotNull($entry->sha256);
        self::assertNotNull($entry->bytes);

        $inventory->assertArchiveEntries(
            [$entry->archivePath => $entry->sha256],
            [$entry->archivePath => $entry->bytes],
        );

        try {
            $inventory->assertArchiveEntries(
                [
                    $entry->archivePath => $entry->sha256,
                    'files/supplier-logos/' . str_repeat('e', 64) . '.png' =>
                        str_repeat('e', 64),
                ],
                [
                    $entry->archivePath => $entry->bytes,
                    'files/supplier-logos/' . str_repeat('e', 64) . '.png' => 1,
                ],
            );
            self::fail('Neinventarizovaný soubor nesmí zůstat v archivu.');
        } catch (CompanyBackupArchiveException $e) {
            self::assertSame('file_inventory_scope_mismatch', $e->errorCode);
        }
    }

    /** @return array<string,mixed> */
    private function inventory(): array
    {
        $bytes = "\x89PNG\r\n";
        $sha256 = hash('sha256', $bytes);
        return [
            'format' => CompanyBackupFileInventory::FORMAT,
            'version' => CompanyBackupFileInventory::VERSION,
            'areas' => [[
                'registry_key' => 'file-area:supplier-logos',
                'order' => 1,
                'entries' => [
                    [
                        'source_path' => 'missing.png',
                        'archive_path' => null,
                        'state' => 'missing',
                        'bytes' => null,
                        'sha256' => null,
                        'owners' => [[
                            'registry_key' => 'table:branding_profiles',
                            'primary_key' => ['id' => 12],
                            'column' => 'logo_path',
                        ]],
                    ],
                    [
                        'source_path' => 'present.png',
                        'archive_path' =>
                            'files/supplier-logos/' . $sha256 . '.png',
                        'state' => 'present',
                        'bytes' => strlen($bytes),
                        'sha256' => $sha256,
                        'owners' => [[
                            'registry_key' => 'table:branding_profiles',
                            'primary_key' => ['id' => 11],
                            'column' => 'logo_path',
                        ]],
                    ],
                ],
            ]],
        ];
    }

    private function snapshot(
        string $filePolicy = 'historical_optional',
    ): TenantDataRegistrySnapshot {
        $profile = TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        return TenantDataRegistrySnapshot::fromRegistry(new TenantDataRegistry(
            1,
            [
                new TenantDataDefinition(
                    'table:branding_profiles',
                    TenantDataObjectKind::Table,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    ['primary_key' => ['id']],
                ),
                new TenantDataDefinition(
                    'file-area:supplier-logos',
                    TenantDataObjectKind::FileArea,
                    TenantDataPolicy::TenantOwned,
                    [$profile],
                    [
                        'file_policy' => $filePolicy,
                        'ownership' => ['strategy' => 'database_references'],
                    ],
                ),
            ],
            [$profile],
        ), $profile);
    }
}
