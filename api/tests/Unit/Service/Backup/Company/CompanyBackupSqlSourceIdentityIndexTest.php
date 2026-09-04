<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentity;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceKey;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlSourceIdentityIndex;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSqlSourceIdentityIndexTest extends TestCase
{
    private PDO $database;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné pro izolovaný SQL test.');
        }
        $this->database = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function testStoresSealedIdentityByEveryCanonicalKeyAndCleansUp(): void
    {
        $identity = $this->identity(31, 'EMP-1');
        $index = new CompanyBackupSqlSourceIdentityIndex(
            $this->database,
            $this->limits(),
        );

        $index->add($identity);
        $index->seal();

        $naturalKey = $identity->naturalKey;
        if ($naturalKey === null) {
            throw new \LogicException('Syntetická identita nemá natural key.');
        }
        $stored = $index->find($naturalKey);
        if ($stored === null) {
            throw new \LogicException('Syntetická identita nebyla nalezena.');
        }
        self::assertSame(
            $identity->primaryKey->values,
            $stored->primaryKey->values,
        );
        self::assertSame(1, $index->identityCount());
        self::assertSame(2, $index->entryCount());
        self::assertGreaterThan(0, $index->indexedBytes());

        $index->close();
        self::assertSame(0, $this->temporaryTableCount());
    }

    public function testRejectsAmbiguousNaturalKeyBeforeSealing(): void
    {
        $index = new CompanyBackupSqlSourceIdentityIndex(
            $this->database,
            $this->limits(),
        );
        $index->add($this->identity(31, 'EMP-1'));

        try {
            $index->add($this->identity(32, 'EMP-1'));
            self::fail('Duplicitní natural key musí zdrojový index odmítnout.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('source_key_duplicate', $e->errorCode);
            self::assertSame('table:synthetic_records', $e->registryKey);
            self::assertNull($e->column);
        } finally {
            $index->close();
        }
        self::assertSame(0, $this->temporaryTableCount());
    }

    public function testLookupIsUnavailableUntilFirstPassIsSealed(): void
    {
        $identity = $this->identity(31, 'EMP-1');
        $index = new CompanyBackupSqlSourceIdentityIndex(
            $this->database,
            $this->limits(),
        );
        $index->add($identity);

        try {
            $index->find($identity->primaryKey);
            self::fail('Neúplný index nesmí obsloužit druhý průchod.');
        } catch (\LogicException $e) {
            self::assertSame('Zdrojový index ještě není uzavřený.', $e->getMessage());
        } finally {
            $index->close();
        }
    }

    public function testIdentityLimitStopsIndexBeforeFurtherGrowth(): void
    {
        $limits = new CompanyBackupArchiveLimits(
            maxSourceIdentities: 1,
            maxSourceIndexEntries: 4,
            maxSourceIndexBytes: 32_768,
        );
        $index = new CompanyBackupSqlSourceIdentityIndex($this->database, $limits);
        $index->add($this->identity(31, 'EMP-1'));

        try {
            $index->add($this->identity(32, 'EMP-2'));
            self::fail('Limit identit musí zastavit růst dočasného indexu.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('source_identity_limit_exceeded', $e->errorCode);
        } finally {
            $index->close();
        }
    }

    public function testEntryLimitStopsWholeIdentityBeforeAnyKeyIsStored(): void
    {
        $identity = $this->identity(31, 'EMP-1');
        $index = new CompanyBackupSqlSourceIdentityIndex(
            $this->database,
            new CompanyBackupArchiveLimits(
                maxSourceIdentities: 10,
                maxSourceIndexEntries: 1,
                maxSourceIndexBytes: 65_536,
            ),
        );

        try {
            $index->add($identity);
            self::fail('Neúplná identita nesmí obejít limit položek indexu.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('source_index_entry_limit_exceeded', $e->errorCode);
            self::assertSame(0, $index->identityCount());
            self::assertSame(0, $index->entryCount());
        } finally {
            $index->close();
        }
    }

    public function testByteLimitStopsWholeIdentityBeforeAnyKeyIsStored(): void
    {
        $index = new CompanyBackupSqlSourceIdentityIndex(
            $this->database,
            new CompanyBackupArchiveLimits(
                maxSourceIdentities: 10,
                maxSourceIndexEntries: 20,
                maxSourceIndexBytes: 1,
            ),
        );

        try {
            $index->add($this->identity(31, 'EMP-1'));
            self::fail('Identita nad byte limitem nesmí vstoupit do indexu.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame('source_index_size_exceeded', $e->errorCode);
            self::assertSame(0, $index->indexedBytes());
        } finally {
            $index->close();
        }
    }

    private function identity(int $id, string $code): CompanyBackupSourceIdentity
    {
        return new CompanyBackupSourceIdentity(
            TenantDataPolicy::TenantOwned,
            CompanyBackupSourceKey::fromValues(
                'table:synthetic_records',
                ['id' => $id],
            ),
            null,
            CompanyBackupSourceKey::fromValues(
                'table:synthetic_records',
                ['supplier_id' => 7, 'code' => $code],
            ),
            [],
        );
    }

    private function limits(): CompanyBackupArchiveLimits
    {
        return new CompanyBackupArchiveLimits(
            maxSourceIdentities: 10,
            maxSourceIndexEntries: 20,
            maxSourceIndexBytes: 65_536,
        );
    }

    private function temporaryTableCount(): int
    {
        $statement = $this->database->query(
            "SELECT COUNT(*) FROM sqlite_temp_master"
            . " WHERE type = 'table' AND name LIKE 'company_backup_source_%'",
        );
        if ($statement === false) {
            throw new \RuntimeException('Nelze ověřit dočasné tabulky.');
        }
        $count = $statement->fetchColumn();
        return is_int($count) ? $count : (int) $count;
    }
}
