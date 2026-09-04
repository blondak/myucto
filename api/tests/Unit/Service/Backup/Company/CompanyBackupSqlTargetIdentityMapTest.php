<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupIdentityMapException;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceIdentity;
use MyInvoice\Service\Backup\Company\CompanyBackupSourceKey;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlTargetIdentityMap;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use PDO;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSqlTargetIdentityMapTest extends TestCase
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
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }

    public function testMapsEverySourceAliasToOneTargetPrimaryKey(): void
    {
        $identity = $this->identity(31, 'EMP-1', 'legacy-1');
        $target = $this->target(401);
        $map = new CompanyBackupSqlTargetIdentityMap(
            $this->database,
            $this->limits(),
        );

        $map->add($identity, $target);
        foreach ($identity->keys() as $sourceKey) {
            $mapped = $map->find($sourceKey);
            self::assertNotNull($mapped);
            self::assertTrue($target->equals($mapped));
        }
        self::assertSame(1, $map->identityCount());
        self::assertSame(4, $map->entryCount());
        self::assertGreaterThan(0, $map->indexedBytes());

        $map->seal();
        $mapped = $map->find($identity->primaryKey);
        self::assertNotNull($mapped);
        self::assertTrue($target->equals($mapped));
        $map->close();
        self::assertSame(0, $this->temporaryTableCount());
    }

    public function testRejectsDuplicateSourceAliasAndTargetIdentity(): void
    {
        $map = new CompanyBackupSqlTargetIdentityMap(
            $this->database,
            $this->limits(),
        );
        $map->add($this->identity(31, 'EMP-1', 'legacy-1'), $this->target(401));

        $this->assertMapError(
            'target_identity_source_duplicate',
            fn () => $map->add(
                $this->identity(32, 'EMP-1', 'legacy-2'),
                $this->target(402),
            ),
        );
        $this->assertMapError(
            'target_identity_target_duplicate',
            fn () => $map->add(
                $this->identity(33, 'EMP-3', 'legacy-3'),
                $this->target(401),
            ),
        );
        self::assertSame(1, $map->identityCount());
        self::assertSame(4, $map->entryCount());

        $map->close();
    }

    public function testRejectsTargetFromAnotherObjectOrWithWrongKeyShape(): void
    {
        $map = new CompanyBackupSqlTargetIdentityMap(
            $this->database,
            $this->limits(),
        );
        $identity = $this->identity(31, 'EMP-1', 'legacy-1');

        $this->assertMapError(
            'target_identity_key_invalid',
            fn () => $map->add(
                $identity,
                CompanyBackupSourceKey::fromValues(
                    'table:other_records',
                    ['id' => 401],
                ),
            ),
        );
        $this->assertMapError(
            'target_identity_key_invalid',
            fn () => $map->add(
                $identity,
                CompanyBackupSourceKey::fromValues(
                    'table:synthetic_records',
                    ['code' => 'TARGET'],
                ),
            ),
        );
        self::assertSame(0, $map->identityCount());

        $map->close();

        $limited = new CompanyBackupSqlTargetIdentityMap(
            $this->database,
            new CompanyBackupArchiveLimits(
                maxSourceKeyBytes: 200,
                maxSourceIdentities: 10,
                maxSourceIndexEntries: 80,
                maxSourceIndexBytes: 65_536,
            ),
        );
        $this->assertMapError(
            'target_identity_key_invalid',
            fn () => $limited->add(
                $this->identity(
                    31,
                    str_repeat('X', 256),
                    'legacy-1',
                ),
                $this->target(401),
            ),
        );
        self::assertSame(0, $limited->identityCount());
        $limited->close();
    }

    public function testEnforcesIdentityEntryAndByteLimitsBeforeWriting(): void
    {
        $identityLimited = new CompanyBackupSqlTargetIdentityMap(
            $this->database,
            new CompanyBackupArchiveLimits(
                maxSourceIdentities: 1,
                maxSourceIndexEntries: 8,
                maxSourceIndexBytes: 65_536,
            ),
        );
        $identityLimited->add(
            $this->identity(31, 'EMP-1', 'legacy-1'),
            $this->target(401),
        );
        $this->assertMapError(
            'target_identity_limit_exceeded',
            fn () => $identityLimited->add(
                $this->identity(32, 'EMP-2', 'legacy-2'),
                $this->target(402),
            ),
        );
        $identityLimited->close();

        $entryLimited = new CompanyBackupSqlTargetIdentityMap(
            $this->database,
            new CompanyBackupArchiveLimits(
                maxSourceIdentities: 10,
                maxSourceIndexEntries: 3,
                maxSourceIndexBytes: 65_536,
            ),
        );
        $this->assertMapError(
            'target_identity_entry_limit_exceeded',
            fn () => $entryLimited->add(
                $this->identity(31, 'EMP-1', 'legacy-1'),
                $this->target(401),
            ),
        );
        self::assertSame(0, $entryLimited->identityCount());
        $entryLimited->close();

        $byteLimited = new CompanyBackupSqlTargetIdentityMap(
            $this->database,
            new CompanyBackupArchiveLimits(
                maxSourceIdentities: 10,
                maxSourceIndexEntries: 8,
                maxSourceIndexBytes: 1,
            ),
        );
        $this->assertMapError(
            'target_identity_size_exceeded',
            fn () => $byteLimited->add(
                $this->identity(31, 'EMP-1', 'legacy-1'),
                $this->target(401),
            ),
        );
        self::assertSame(0, $byteLimited->indexedBytes());
        $byteLimited->close();
    }

    public function testSealingAndClosingMakeTheMapImmutable(): void
    {
        $map = new CompanyBackupSqlTargetIdentityMap(
            $this->database,
            $this->limits(),
        );
        $identity = $this->identity(31, 'EMP-1', 'legacy-1');
        $map->add($identity, $this->target(401));
        $map->seal();

        try {
            $map->add(
                $this->identity(32, 'EMP-2', 'legacy-2'),
                $this->target(402),
            );
            self::fail('Uzavřenou mapu nesmí jít rozšířit.');
        } catch (\LogicException $e) {
            self::assertSame('Cílová mapa už je uzavřená.', $e->getMessage());
        }
        try {
            $map->seal();
            self::fail('Mapu nesmí jít uzavřít dvakrát.');
        } catch (\LogicException $e) {
            self::assertSame('Cílová mapa už je uzavřená.', $e->getMessage());
        }

        $map->close();
        try {
            $map->find($identity->primaryKey);
            self::fail('Uklizenou mapu nesmí jít číst.');
        } catch (\LogicException $e) {
            self::assertSame('Cílová mapa už je zavřená.', $e->getMessage());
        }
    }

    private function identity(
        int $id,
        string $code,
        string $legacyReference,
    ): CompanyBackupSourceIdentity {
        return new CompanyBackupSourceIdentity(
            TenantDataPolicy::TenantOwned,
            CompanyBackupSourceKey::fromValues(
                'table:synthetic_records',
                ['id' => $id],
            ),
            CompanyBackupSourceKey::fromValues(
                'table:synthetic_records',
                ['supplier_id' => 7, 'id' => $id],
            ),
            CompanyBackupSourceKey::fromValues(
                'table:synthetic_records',
                ['supplier_id' => 7, 'code' => $code],
            ),
            [CompanyBackupSourceKey::fromValues(
                'table:synthetic_records',
                ['supplier_id' => 7, 'legacy_ref' => $legacyReference],
            )],
        );
    }

    private function target(int $id): CompanyBackupSourceKey
    {
        return CompanyBackupSourceKey::fromValues(
            'table:synthetic_records',
            ['id' => $id],
        );
    }

    private function limits(): CompanyBackupArchiveLimits
    {
        return new CompanyBackupArchiveLimits(
            maxSourceIdentities: 10,
            maxSourceIndexEntries: 80,
            maxSourceIndexBytes: 65_536,
        );
    }

    /** @param callable():mixed $operation */
    private function assertMapError(string $errorCode, callable $operation): void
    {
        try {
            $operation();
            self::fail('Neplatný zápis do cílové mapy musí být odmítnut.');
        } catch (CompanyBackupIdentityMapException $e) {
            self::assertSame($errorCode, $e->errorCode);
            self::assertStringNotContainsString('EMP-', $e->getMessage());
            self::assertStringNotContainsString('legacy-', $e->getMessage());
        }
    }

    private function temporaryTableCount(): int
    {
        $statement = $this->database->query(
            "SELECT COUNT(*) FROM sqlite_temp_master"
            . " WHERE type = 'table' AND name LIKE 'company_backup_target_%'",
        );
        if ($statement === false) {
            throw new \RuntimeException('Nelze ověřit dočasné tabulky.');
        }
        $count = $statement->fetchColumn();
        return is_int($count) ? $count : (int) $count;
    }
}
