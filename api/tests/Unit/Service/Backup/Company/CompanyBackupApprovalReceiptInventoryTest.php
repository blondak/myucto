<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupApprovalReceiptInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use PHPUnit\Framework\TestCase;

final class CompanyBackupApprovalReceiptInventoryTest extends TestCase
{
    public function testEmptyAndCanonicalInventoryNeverExposeRawHash(): void
    {
        self::assertSame([], (new CompanyBackupApprovalReceiptInventory())->entries());
        $hashA = str_repeat('a', 64);
        $hashB = str_repeat('b', 64);
        $rows = [
            ['id' => 8, 'approval_receipt_hash' => $hashB, 'target_collision' => false],
            ['id' => 3, 'approval_receipt_hash' => null, 'target_collision' => false],
            ['id' => 2, 'approval_receipt_hash' => $hashA, 'target_collision' => true],
        ];
        $inventory = CompanyBackupApprovalReceiptInventory::fromRows($rows);
        self::assertSame(2, $inventory->count());
        self::assertSame(1, $inventory->collisionCount());
        self::assertNull($inventory->entry(3));
        self::assertSame([
            ['source_invoice_id' => 2, 'receipt_fingerprint' => hash('sha256', $hashA), 'collision' => true],
            ['source_invoice_id' => 8, 'receipt_fingerprint' => hash('sha256', $hashB), 'collision' => false],
        ], $inventory->entries());
        self::assertSame($inventory->sha256(), CompanyBackupApprovalReceiptInventory::fromRows(array_reverse($rows))->sha256());
        $serialized = json_encode($inventory->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($hashA, $serialized);
        self::assertStringNotContainsString($hashB, $serialized);
        self::assertStringNotContainsString('target_owner', $serialized);
    }

    public function testAllDuplicateArchiveHashesAreCollisions(): void
    {
        $hash = str_repeat('c', 64);
        $inventory = CompanyBackupApprovalReceiptInventory::fromRows([
            ['id' => 7, 'approval_receipt_hash' => $hash, 'target_collision' => false],
            ['id' => 4, 'approval_receipt_hash' => $hash, 'target_collision' => false],
        ]);
        self::assertSame(2, $inventory->collisionCount());
        self::assertSame(true, $inventory->entry(4)['collision'] ?? null);
        self::assertSame(true, $inventory->entry(7)['collision'] ?? null);
    }

    public function testRejectsDuplicateIdIncludingNullRowsAndLimit(): void
    {
        $this->assertSafeError('approval_receipt_source_duplicate', static fn () => CompanyBackupApprovalReceiptInventory::fromRows([
            ['id' => 1, 'approval_receipt_hash' => null, 'target_collision' => false],
            ['id' => 1, 'approval_receipt_hash' => str_repeat('d', 64), 'target_collision' => false],
        ]));
        $this->assertSafeError('approval_receipt_inventory_limit_exceeded', static fn () => CompanyBackupApprovalReceiptInventory::fromRows([
            ['id' => 1, 'approval_receipt_hash' => str_repeat('a', 64), 'target_collision' => false],
            ['id' => 2, 'approval_receipt_hash' => str_repeat('b', 64), 'target_collision' => false],
        ], new CompanyBackupArchiveLimits(maxReferenceRequirements: 1)));
    }

    public function testRejectsMalformedRowsAndNullCollisionWithoutLeaking(): void
    {
        $this->assertSafeError('approval_receipt_inventory_invalid', static fn () => CompanyBackupApprovalReceiptInventory::fromRows([
            ['id' => 0, 'approval_receipt_hash' => str_repeat('e', 64), 'target_collision' => false],
        ]));
        $this->assertSafeError('approval_receipt_collision_context_invalid', static fn () => CompanyBackupApprovalReceiptInventory::fromRows([
            ['id' => 1, 'approval_receipt_hash' => null, 'target_collision' => true],
        ]));
        $this->assertSafeError('approval_receipt_hash_invalid', static fn () => CompanyBackupApprovalReceiptInventory::fromRows([
            ['id' => 1, 'approval_receipt_hash' => str_repeat('E', 64), 'target_collision' => false],
        ]));
    }

    private function assertSafeError(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Neplatný inventář nesmí projít.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertStringNotContainsString(str_repeat('E', 64), $e->getMessage());
        }
    }
}
