<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupApprovalReceiptCollisionLookup;
use MyInvoice\Service\Backup\Company\CompanyBackupApprovalReceiptPreflightInventoryCollector;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;

final class CompanyBackupApprovalReceiptPreflightInventoryCollectorTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef';

    private PDO $database;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite není dostupné.');
        }
        $this->database = new Sqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->createFunction('SHA2', static function (?string $value, int $bits): ?string {
            return $value === null ? null : hash('sha256', $value);
        }, 2);
        $this->database->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER, approval_status TEXT, approval_token TEXT, approval_token_expires_at TEXT, approval_receipt_hash TEXT)');
    }

    public function testFindsStoredReceiptAndActiveTokenAcrossSuppliersAndStatuses(): void
    {
        $hash = hash('sha256', self::TOKEN);
        $clean = $this->collect([self::row(1, $hash)]);
        self::assertSame(0, $clean->collisionCount());

        $this->database->prepare('INSERT INTO invoices VALUES (9, 99, ?, NULL, ?, ?)')
            ->execute(['rejected', '2020-01-01', $hash]);
        $stored = $this->collect([self::row(1, $hash)]);
        self::assertSame(1, $stored->collisionCount());
        self::assertSame(true, $stored->entry(1)['collision'] ?? null);

        $this->database->exec('DELETE FROM invoices');
        $this->database->prepare('INSERT INTO invoices VALUES (9, 99, ?, ?, ?, NULL)')
            ->execute(['requested', self::TOKEN, '2020-01-01']);
        $active = $this->collect([self::row(1, $hash)]);
        self::assertSame(1, $active->collisionCount());
        self::assertNotSame($clean->sha256(), $active->sha256());
        self::assertStringNotContainsString($hash, CanonicalJson::encode($active->toArray()));
        self::assertStringNotContainsString(self::TOKEN, CanonicalJson::encode($active->toArray()));
    }

    public function testNullHashNeedsNoQueryOrReceiptCapacityAndRetainedIdsAreUnique(): void
    {
        $this->database->exec('DROP TABLE invoices');
        $collector = new CompanyBackupApprovalReceiptPreflightInventoryCollector(
            new CompanyBackupArchiveLimits(maxReferenceRequirements: 1, maxSourceIdentities: 1),
        );
        $collector->acceptRow(self::row(1, null));
        $collector->acceptRow(self::row(2, null));
        self::assertSame(0, $collector->finish($this->database)->count());

        $duplicate = new CompanyBackupApprovalReceiptPreflightInventoryCollector();
        $duplicate->acceptRow(self::row(1, str_repeat('a', 64)));
        $this->assertSafeError('approval_receipt_source_duplicate', static fn () => $duplicate->acceptRow(self::row(1, str_repeat('b', 64))));
    }

    public function testSourceDuplicateHashesFlagAllEntriesAndNonmatchesStayClean(): void
    {
        $hash = str_repeat('a', 64);
        $inventory = $this->collect([self::row(3, $hash), self::row(1, null), self::row(2, $hash), self::row(4, str_repeat('b', 64))]);
        self::assertSame([2, 3, 4], array_column($inventory->entries(), 'source_invoice_id'));
        self::assertSame(2, $inventory->collisionCount());
        self::assertFalse($inventory->entry(4)['collision'] ?? true);
        self::assertNull($inventory->entry(1));
    }

    public function testRejectsMalformedRowsAndBoundsRetainedHashes(): void
    {
        foreach ([
            [self::row(0, null), 'approval_receipt_inventory_invalid'],
            [['id' => 1], 'approval_receipt_inventory_invalid'],
            [self::row(1, str_repeat('A', 64)), 'approval_receipt_hash_invalid'],
            [self::row(1, 'bad'), 'approval_receipt_hash_invalid'],
        ] as [$row, $code]) {
            $this->assertSafeError($code, static fn () => (new CompanyBackupApprovalReceiptPreflightInventoryCollector())->acceptRow($row));
        }

        $collector = new CompanyBackupApprovalReceiptPreflightInventoryCollector(
            new CompanyBackupArchiveLimits(maxReferenceRequirements: 1),
        );
        $collector->acceptRow(self::row(1, str_repeat('a', 64)));
        $collector->acceptRow(self::row(2, null));
        $this->assertSafeError('approval_receipt_inventory_limit_exceeded', static fn () => $collector->acceptRow(self::row(3, str_repeat('b', 64))));

    }

    public function testQueryFailureIsSanitizedAndCollectorCannotBeReused(): void
    {
        $hash = hash('sha256', self::TOKEN);
        $collector = new CompanyBackupApprovalReceiptPreflightInventoryCollector();
        $collector->acceptRow(self::row(1, $hash));
        $this->database->exec('DROP TABLE invoices');
        $this->assertSafeError('approval_receipt_collision_lookup_failed', fn () => $collector->finish($this->database));
        $this->assertSafeError('approval_receipt_inventory_already_finished', static fn () => $collector->acceptRow(self::row(2, $hash)));
        $this->assertSafeError('approval_receipt_inventory_already_finished', fn () => $collector->finish($this->database));
    }

    public function testSilentPdoFailureIsSanitized(): void
    {
        $this->database->exec('DROP TABLE invoices');
        $this->database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $this->assertSafeError(
            'approval_receipt_collision_lookup_failed',
            fn () => CompanyBackupApprovalReceiptCollisionLookup::hasCollision($this->database, str_repeat('a', 64)),
        );
    }

    public function testLookupRejectsInvalidHashBeforeSql(): void
    {
        $this->database->exec('DROP TABLE invoices');
        self::assertFalse(CompanyBackupApprovalReceiptCollisionLookup::hasCollision($this->database, null));
        $this->assertSafeError('approval_receipt_hash_invalid', fn () => CompanyBackupApprovalReceiptCollisionLookup::hasCollision($this->database, str_repeat('F', 64)));
    }

    /** @param list<array<string,mixed>> $rows */
    private function collect(array $rows): \MyInvoice\Service\Backup\Company\CompanyBackupApprovalReceiptInventory
    {
        $collector = new CompanyBackupApprovalReceiptPreflightInventoryCollector();
        foreach ($rows as $row) {
            $collector->acceptRow($row);
        }
        return $collector->finish($this->database);
    }

    /** @return array<string,mixed> */
    private static function row(int $id, ?string $hash): array
    {
        return ['id' => $id, 'approval_receipt_hash' => $hash];
    }

    private function assertSafeError(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Neplatná kolize musí skončit chybou.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame($code . ': table:invoices.approval_receipt_hash', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }
}
