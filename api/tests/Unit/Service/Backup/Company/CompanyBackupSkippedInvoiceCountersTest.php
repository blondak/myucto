<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company as Backup;
use MyInvoice\Service\Backup\Registry\CompanyBackupInvoiceCounterDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSkippedInvoiceCountersTest extends TestCase
{
    public function testZeroIsNotAnOrphanAndEitherMissingAxisSkipsTheWholeRow(): void
    {
        $lookup = $this->createStub(Backup\CompanyBackupSourceIdentityLookup::class);
        $lookup->method('find')->willReturnCallback(static function (Backup\CompanyBackupSourceKey $key): ?Backup\CompanyBackupSourceIdentity {
            if ($key->values['id'] === 99) {
                return null;
            }
            return new Backup\CompanyBackupSourceIdentity(TenantDataPolicy::TenantOwned, $key,
                Backup\CompanyBackupSourceKey::fromValues($key->registryKey, ['supplier_id' => 7, ...$key->values]), null, []);
        });
        foreach ([[0, 0, false], [11, 0, false], [0, 12, false], [11, 12, false],
            [99, 0, true], [0, 99, true], [11, 99, true], [99, 12, true]] as [$client, $category, $expected]) {
            self::assertSame($expected, Backup\CompanyBackupSkippedInvoiceCounters::isOrphan(
                ['supplier_id' => 7, 'client_id' => $client, 'revenue_category_id' => $category], $lookup));
        }
    }

    public function testForeignOwnerIsAnErrorEvenWhenTheOtherAxisIsMissing(): void
    {
        $lookup = $this->createStub(Backup\CompanyBackupSourceIdentityLookup::class);
        $lookup->method('find')->willReturnCallback(static function (Backup\CompanyBackupSourceKey $key): ?Backup\CompanyBackupSourceIdentity {
            return $key->registryKey === 'table:clients' ? null : new Backup\CompanyBackupSourceIdentity(
                TenantDataPolicy::TenantOwned, $key,
                Backup\CompanyBackupSourceKey::fromValues($key->registryKey, ['supplier_id' => 8, ...$key->values]), null, []);
        });
        $this->expectException(Backup\CompanyBackupPreflightException::class);
        $this->expectExceptionMessage('counter_owner_mismatch');
        Backup\CompanyBackupSkippedInvoiceCounters::isOrphan(
            ['supplier_id' => 7, 'client_id' => 99, 'revenue_category_id' => 12], $lookup);
    }

    public function testReportIsCanonicalBoundedAndHasNoWarningWhenEmpty(): void
    {
        $a = $this->key(11);
        $b = $this->key(12);
        $first = new Backup\CompanyBackupSkippedInvoiceCounters([$a, $b]);
        $second = new Backup\CompanyBackupSkippedInvoiceCounters([$b, $a]);
        self::assertSame($first->bindingSha256(), $second->bindingSha256());
        self::assertNotSame($first->bindingSha256(), (new Backup\CompanyBackupSkippedInvoiceCounters([$a]))->bindingSha256());
        self::assertSame(2, $first->count());
        self::assertSame(0, $first->count('table:invoices'));
        self::assertSame('orphan_invoice_counters_skipped', $first->warnings()[0]['code']);
        self::assertSame([], (new Backup\CompanyBackupSkippedInvoiceCounters())->warnings());
        $this->expectException(\InvalidArgumentException::class);
        new Backup\CompanyBackupSkippedInvoiceCounters([$a, $a]);
    }

    public function testModifiedProjectionCannotUseSkipException(): void
    {
        $definition = CompanyBackupInvoiceCounterDefinition::definition();
        Backup\CompanyBackupSkippedInvoiceCounters::assertDefinition($definition);
        $details = $definition->details;
        $details['natural_key'] = ['supplier_id', 'period'];
        $this->expectException(Backup\CompanyBackupPreflightException::class);
        Backup\CompanyBackupSkippedInvoiceCounters::assertDefinition(new TenantDataDefinition(
            $definition->key, $definition->kind, $definition->policy, $definition->profiles, $details));
    }

    public function testChangedSkippedKeyChangesPreflightBindingEvenWithSameCount(): void
    {
        $external = (new Backup\CompanyBackupExternalReferenceCollector())->finish();
        $make = static fn (Backup\CompanyBackupSourceKey $key): Backup\CompanyBackupDataPreflightResult =>
            new Backup\CompanyBackupDataPreflightResult($external, 3, 3, 3, 300, 0,
                'sha256:' . str_repeat('a', 64), str_repeat('b', 64), false,
                new Backup\CompanyBackupSkippedInvoiceCounters([$key]));
        $a = $make($this->key(11));
        $b = $make($this->key(12));
        self::assertNotSame($a->bindingSha256, $b->bindingSha256);
        self::assertSame('orphan_invoice_counters_skipped', $a->toArray()['warnings'][0]['code']);
    }

    public function testGeneralCompanyRowCannotBeListedForSkipping(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Backup\CompanyBackupSkippedInvoiceCounters([$this->key(0)]);
    }

    public function testAllSkippedRowsStillConsumeAndValidateTheEntireSource(): void
    {
        $definition = CompanyBackupInvoiceCounterDefinition::definition();
        $profile = \MyInvoice\Service\Backup\Registry\TenantDataRegistry::COMPANY_BACKUP_PROFILE;
        $snapshot = \MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot::fromRegistry(
            new \MyInvoice\Service\Backup\Registry\TenantDataRegistry(1, [$definition], [$profile]), $profile);
        $source = $this->createMock(Backup\CompanyBackupImportSource::class);
        $source->method('targetRegistry')->willReturn($snapshot);
        $row = [...$this->key(11)->values, 'last_number' => 42];
        $source->expects(self::once())->method('consumeRows')->with($definition->key, self::isCallable())
            ->willReturnCallback(static function (string $key, callable $visitor) use ($row): int {
                $visitor($row);
                return 1;
            });
        $skips = new Backup\CompanyBackupSkippedInvoiceCounters([$this->key(11)]);
        self::assertSame(1, $skips->consumeRows($source, $definition->key,
            static fn (array $row) => self::fail('Osiřelý řádek nesmí dojít k SQL writeru.')));
    }

    private function key(int $client): Backup\CompanyBackupSourceKey
    {
        return Backup\CompanyBackupSourceKey::fromValues(Backup\CompanyBackupInvoiceCounterKey::REGISTRY_KEY,
            ['supplier_id' => 7, 'client_id' => $client, 'revenue_category_id' => 0, 'invoice_type' => 'invoice', 'period' => 'ALL']);
    }
}
