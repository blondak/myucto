<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupIdentityMapException;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceRequirement;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
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

    public function testMapsEverySourceAliasToCorrespondingTargetAlias(): void
    {
        $identity = $this->identity(31, 'EMP-1', 'legacy-1');
        $target = $this->identity(
            401,
            'TARGET-EMP-1',
            'target-legacy-1',
            71,
        );
        $map = new CompanyBackupSqlTargetIdentityMap(
            $this->database,
            $this->limits(),
        );

        $map->add($identity, $target);
        foreach ($identity->keys() as $index => $sourceKey) {
            $mapped = $map->find($sourceKey);
            self::assertNotNull($mapped);
            self::assertTrue($target->keys()[$index]->equals($mapped));
        }
        self::assertSame(1, $map->identityCount());
        self::assertSame(4, $map->entryCount());
        self::assertGreaterThan(0, $map->indexedBytes());

        $map->seal();
        $mapped = $map->find($identity->primaryKey);
        self::assertNotNull($mapped);
        self::assertTrue($target->primaryKey->equals($mapped));
        $map->close();
        self::assertSame(0, $this->temporaryTableCount());
    }

    public function testRejectsDuplicateSourceAliasAndTargetIdentity(): void
    {
        $map = new CompanyBackupSqlTargetIdentityMap(
            $this->database,
            $this->limits(),
        );
        $map->add(
            $this->identity(31, 'EMP-1', 'legacy-1'),
            $this->identity(401, 'TARGET-EMP-1', 'target-legacy-1', 71),
        );

        $this->assertMapError(
            'target_identity_source_duplicate',
            fn () => $map->add(
                $this->identity(32, 'EMP-1', 'legacy-2'),
                $this->identity(402, 'TARGET-EMP-2', 'target-legacy-2', 71),
            ),
        );
        $this->assertMapError(
            'target_identity_target_duplicate',
            fn () => $map->add(
                $this->identity(33, 'EMP-3', 'legacy-3'),
                $this->identity(401, 'TARGET-EMP-3', 'target-legacy-3', 71),
            ),
        );
        self::assertSame(1, $map->identityCount());
        self::assertSame(4, $map->entryCount());

        $map->close();
    }

    public function testBindsGlobalAliasesToRequirementAndTargetPrimaryKey(): void
    {
        $source = $this->globalIdentity(1, 'CZ');
        $target = $this->globalIdentity(10, 'CZ');
        $map = new CompanyBackupSqlTargetIdentityMap(
            $this->database,
            $this->limits(),
        );
        $map->add($source, $target);

        $match = $map->findMatch($source->primaryKey);
        self::assertNotNull($match);
        self::assertTrue($target->primaryKey->equals($match->mappedKey));
        self::assertTrue($target->primaryKey->equals($match->targetPrimaryKey));
        self::assertSame(
            CompanyBackupExternalReferenceRequirement::idFor(
                CompanyBackupReferenceMapping::GlobalNaturalKey,
                'table:countries',
                ['iso2' => 'CZ'],
            ),
            $match->externalRequirementId,
        );
        $sourceNatural = $source->naturalKey;
        $targetNatural = $target->naturalKey;
        self::assertNotNull($sourceNatural);
        self::assertNotNull($targetNatural);
        $mappedNatural = $map->find($sourceNatural);
        self::assertNotNull($mappedNatural);
        self::assertTrue($targetNatural->equals($mappedNatural));

        $this->assertMapError(
            'target_identity_key_invalid',
            fn () => $map->add(
                $this->globalIdentity(2, 'SK'),
                $this->globalIdentity(11, 'DE'),
            ),
        );
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
                new CompanyBackupSourceIdentity(
                    TenantDataPolicy::TenantOwned,
                    CompanyBackupSourceKey::fromValues(
                        'table:other_records',
                        ['id' => 401],
                    ),
                    null,
                    null,
                    [],
                ),
            ),
        );
        $this->assertMapError(
            'target_identity_key_invalid',
            fn () => $map->add(
                $identity,
                new CompanyBackupSourceIdentity(
                    TenantDataPolicy::TenantOwned,
                    CompanyBackupSourceKey::fromValues(
                        'table:synthetic_records',
                        ['code' => 'TARGET'],
                    ),
                    null,
                    null,
                    [],
                ),
            ),
        );
        $this->assertMapError(
            'target_identity_key_invalid',
            fn () => $map->add(
                $identity,
                new CompanyBackupSourceIdentity(
                    TenantDataPolicy::TenantOwned,
                    CompanyBackupSourceKey::fromValues(
                        'table:synthetic_records',
                        ['id' => 401],
                    ),
                    CompanyBackupSourceKey::fromValues(
                        'table:synthetic_records',
                        ['supplier_id' => 71, 'id' => 401],
                    ),
                    null,
                    [],
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
                $this->identity(
                    401,
                    'TARGET-EMP-1',
                    'target-legacy-1',
                    71,
                ),
            ),
        );
        self::assertSame(0, $limited->identityCount());
        $limited->close();
    }

    public function testRejectsTargetAliasWithDifferentShape(): void
    {
        $map = new CompanyBackupSqlTargetIdentityMap(
            $this->database,
            $this->limits(),
        );
        $source = $this->identity(31, 'EMP-1', 'legacy-1');
        $target = new CompanyBackupSourceIdentity(
            TenantDataPolicy::TenantOwned,
            CompanyBackupSourceKey::fromValues(
                'table:synthetic_records',
                ['id' => 401],
            ),
            CompanyBackupSourceKey::fromValues(
                'table:synthetic_records',
                ['supplier_id' => 71, 'id' => 401],
            ),
            CompanyBackupSourceKey::fromValues(
                'table:synthetic_records',
                ['supplier_id' => 71, 'different_code' => 'TARGET'],
            ),
            [CompanyBackupSourceKey::fromValues(
                'table:synthetic_records',
                ['supplier_id' => 71, 'legacy_ref' => 'target-legacy-1'],
            )],
        );

        $this->assertMapError(
            'target_identity_key_invalid',
            fn () => $map->add($source, $target),
        );
        self::assertSame(0, $map->identityCount());
        $map->close();
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
            $this->identity(401, 'TARGET-EMP-1', 'target-legacy-1', 71),
        );
        $this->assertMapError(
            'target_identity_limit_exceeded',
            fn () => $identityLimited->add(
                $this->identity(32, 'EMP-2', 'legacy-2'),
                $this->identity(402, 'TARGET-EMP-2', 'target-legacy-2', 71),
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
                $this->identity(401, 'TARGET-EMP-1', 'target-legacy-1', 71),
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
                $this->identity(401, 'TARGET-EMP-1', 'target-legacy-1', 71),
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
        $map->add(
            $identity,
            $this->identity(401, 'TARGET-EMP-1', 'target-legacy-1', 71),
        );
        $map->seal();

        try {
            $map->add(
                $this->identity(32, 'EMP-2', 'legacy-2'),
                $this->identity(402, 'TARGET-EMP-2', 'target-legacy-2', 71),
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
        int $supplierId = 7,
    ): CompanyBackupSourceIdentity {
        return new CompanyBackupSourceIdentity(
            TenantDataPolicy::TenantOwned,
            CompanyBackupSourceKey::fromValues(
                'table:synthetic_records',
                ['id' => $id],
            ),
            CompanyBackupSourceKey::fromValues(
                'table:synthetic_records',
                ['supplier_id' => $supplierId, 'id' => $id],
            ),
            CompanyBackupSourceKey::fromValues(
                'table:synthetic_records',
                ['supplier_id' => $supplierId, 'code' => $code],
            ),
            [CompanyBackupSourceKey::fromValues(
                'table:synthetic_records',
                [
                    'supplier_id' => $supplierId,
                    'legacy_ref' => $legacyReference,
                ],
            )],
        );
    }

    private function globalIdentity(
        int $id,
        string $iso2,
    ): CompanyBackupSourceIdentity {
        return new CompanyBackupSourceIdentity(
            TenantDataPolicy::GlobalReference,
            CompanyBackupSourceKey::fromValues(
                'table:countries',
                ['id' => $id],
            ),
            null,
            CompanyBackupSourceKey::fromValues(
                'table:countries',
                ['iso2' => $iso2],
            ),
            [],
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
