<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Registry;

use MyInvoice\Service\Backup\Registry\IncompleteTenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TenantDataRegistrySnapshotTest extends TestCase
{
    public function testRoundTripsCompleteProfileWithVerifiedFingerprint(): void
    {
        $registry = self::registry();

        $snapshot = TenantDataRegistrySnapshot::fromRegistry(
            $registry,
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
        );
        $parsed = TenantDataRegistrySnapshot::fromArray($snapshot->toArray());

        self::assertSame(TenantDataRegistry::FORMAT, $parsed->format);
        self::assertSame(7, $parsed->registry->version);
        self::assertSame(TenantDataRegistry::COMPANY_BACKUP_PROFILE, $parsed->profile);
        self::assertSame(
            $registry->fingerprintFor(TenantDataRegistry::COMPANY_BACKUP_PROFILE),
            $parsed->fingerprint,
        );
        self::assertSame($snapshot->toArray(), $parsed->toArray());
        self::assertSame(
            ['table:invoices', 'table:supplier'],
            array_column($parsed->toArray()['definitions'], 'key'),
        );
    }

    public function testDraftProfileCannotClaimRestorableSnapshot(): void
    {
        $registry = new TenantDataRegistry(1, [
            self::definition('supplier', TenantDataPolicy::TenantRoot),
        ]);

        $this->expectException(IncompleteTenantDataRegistry::class);

        TenantDataRegistrySnapshot::fromRegistry(
            $registry,
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
        );
    }

    public function testUnsupportedDefinitionCannotClaimRestorableSnapshot(): void
    {
        $registry = new TenantDataRegistry(
            1,
            [new TenantDataDefinition(
                'table:opaque_data',
                TenantDataObjectKind::Table,
                TenantDataPolicy::Unsupported,
                [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
                ['reason' => 'ownership_unknown'],
            )],
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
        );

        $this->expectException(IncompleteTenantDataRegistry::class);

        TenantDataRegistrySnapshot::fromRegistry(
            $registry,
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
        );
    }

    public function testFingerprintMustMatchEmbeddedDefinitions(): void
    {
        $array = TenantDataRegistrySnapshot::fromRegistry(
            self::registry(),
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
        )->toArray();
        $array['definitions'][0]['policy'] = TenantDataPolicy::RuntimeDerived->value;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fingerprint');

        TenantDataRegistrySnapshot::fromArray($array);
    }

    public function testDefinitionsMustUseStableKeyOrder(): void
    {
        $array = TenantDataRegistrySnapshot::fromRegistry(
            self::registry(),
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
        )->toArray();
        $array['definitions'] = array_reverse($array['definitions']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pořadí');

        TenantDataRegistrySnapshot::fromArray($array);
    }

    public function testSnapshotContainsOnlyMetadataOfSelectedProfile(): void
    {
        $definition = new TenantDataDefinition(
            'table:supplier',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantRoot,
            [
                TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
                TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            ],
            [
                'primary_key' => ['id'],
                'accounting_archive' => ['marker' => 'archive-only'],
                'company_backup' => ['marker' => 'company-only'],
            ],
        );
        $registry = new TenantDataRegistry(
            1,
            [$definition],
            [
                TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
                TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            ],
        );

        $snapshot = TenantDataRegistrySnapshot::fromRegistry(
            $registry,
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
        )->toArray();

        self::assertArrayHasKey('company_backup', $snapshot['definitions'][0]['details']);
        self::assertArrayNotHasKey('accounting_archive', $snapshot['definitions'][0]['details']);

        $snapshot['definitions'][0]['details']['accounting_archive'] = ['marker' => 'unbound'];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jiného profilu');
        TenantDataRegistrySnapshot::fromArray($snapshot);
    }

    /** @param array<mixed> $snapshot */
    #[DataProvider('malformedSnapshots')]
    public function testRejectsMalformedOrAmbiguousSnapshot(array $snapshot): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TenantDataRegistrySnapshot::fromArray($snapshot);
    }

    /** @return iterable<string,array{array<mixed>}> */
    public static function malformedSnapshots(): iterable
    {
        $base = self::snapshotArray();

        $unknownField = $base;
        $unknownField['opaque'] = true;
        yield 'unknown field' => [$unknownField];

        $wrongFormat = $base;
        $wrongFormat['format'] = 'other-registry';
        yield 'wrong format' => [$wrongFormat];

        $wrongProfile = $base;
        $wrongProfile['profile'] = 'unknown profile';
        yield 'unsafe profile' => [$wrongProfile];

        $unknownPolicy = $base;
        $unknownPolicy['definitions'][0]['policy'] = 'copy_everything';
        yield 'unknown policy' => [$unknownPolicy];

        $outsideProfile = $base;
        $outsideProfile['definitions'][0]['profiles'] = [
            TenantDataRegistry::ACCOUNTING_ARCHIVE_PROFILE,
        ];
        yield 'definition outside profile' => [$outsideProfile];
    }

    /** @return array<string,mixed> */
    private static function snapshotArray(): array
    {
        return TenantDataRegistrySnapshot::fromRegistry(
            self::registry(),
            TenantDataRegistry::COMPANY_BACKUP_PROFILE,
        )->toArray();
    }

    private static function registry(): TenantDataRegistry
    {
        return new TenantDataRegistry(
            7,
            [
                self::definition('supplier', TenantDataPolicy::TenantRoot),
                self::definition('invoices', TenantDataPolicy::TenantOwned),
            ],
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
        );
    }

    private static function definition(string $table, TenantDataPolicy $policy): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            $policy,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => $table === 'supplier'
                    ? ['strategy' => 'selected_supplier', 'column' => 'id']
                    : ['strategy' => 'supplier_id', 'column' => 'supplier_id'],
            ],
        );
    }
}
