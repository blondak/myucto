<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupWorkReportLinkInventory;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use PHPUnit\Framework\TestCase;

final class CompanyBackupWorkReportLinkInventoryTest extends TestCase
{
    public function testEmptyAndCanonicalInventoryNeverExposeRawToken(): void
    {
        self::assertSame([], (new CompanyBackupWorkReportLinkInventory())->entries());
        $tokenA = str_repeat('a', 48);
        $tokenB = str_repeat('b', 48);
        $rows = [
            ['id' => 8, 'token' => $tokenB, 'target_collision' => false],
            ['id' => 2, 'token' => $tokenA, 'target_collision' => true],
        ];
        $inventory = CompanyBackupWorkReportLinkInventory::fromRows($rows);
        self::assertSame(2, $inventory->count());
        self::assertSame(1, $inventory->collisionCount());
        self::assertNull($inventory->entry(3));
        self::assertSame([
            ['source_link_id' => 2, 'token_fingerprint' => hash('sha256', $tokenA), 'collision' => true],
            ['source_link_id' => 8, 'token_fingerprint' => hash('sha256', $tokenB), 'collision' => false],
        ], $inventory->entries());
        self::assertSame($inventory->sha256(), CompanyBackupWorkReportLinkInventory::fromRows(array_reverse($rows))->sha256());
        $serialized = json_encode($inventory->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($tokenA, $serialized);
        self::assertStringNotContainsString($tokenB, $serialized);
        self::assertStringNotContainsString('target_owner', $serialized);
    }

    public function testAllDuplicateArchiveTokensAreCollisions(): void
    {
        $token = str_repeat('c', 48);
        $inventory = CompanyBackupWorkReportLinkInventory::fromRows([
            ['id' => 7, 'token' => $token, 'target_collision' => false],
            ['id' => 4, 'token' => $token, 'target_collision' => false],
        ]);
        self::assertSame(2, $inventory->collisionCount());
        self::assertSame(true, $inventory->entry(4)['collision'] ?? null);
        self::assertSame(true, $inventory->entry(7)['collision'] ?? null);
    }

    public function testRejectsDuplicateIdAndLimit(): void
    {
        $this->assertSafeError('work_report_link_source_duplicate', static fn () => CompanyBackupWorkReportLinkInventory::fromRows([
            ['id' => 1, 'token' => str_repeat('c', 48), 'target_collision' => false],
            ['id' => 1, 'token' => str_repeat('d', 48), 'target_collision' => false],
        ]));
        $this->assertSafeError('work_report_link_inventory_limit_exceeded', static fn () => CompanyBackupWorkReportLinkInventory::fromRows([
            ['id' => 1, 'token' => str_repeat('a', 48), 'target_collision' => false],
            ['id' => 2, 'token' => str_repeat('b', 48), 'target_collision' => false],
        ], new CompanyBackupArchiveLimits(maxReferenceRequirements: 1)));
    }

    public function testRejectsMalformedRowsAndInvalidTokenWithoutLeaking(): void
    {
        $this->assertSafeError('work_report_link_inventory_invalid', static fn () => CompanyBackupWorkReportLinkInventory::fromRows([
            ['id' => 0, 'token' => str_repeat('e', 48), 'target_collision' => false],
        ]));
        $this->assertSafeError('work_report_link_token_invalid', static fn () => CompanyBackupWorkReportLinkInventory::fromRows([
            ['id' => 1, 'token' => null, 'target_collision' => true],
        ]));
        $this->assertSafeError('work_report_link_token_invalid', static fn () => CompanyBackupWorkReportLinkInventory::fromRows([
            ['id' => 1, 'token' => str_repeat('E', 48), 'target_collision' => false],
        ]));
        $this->assertSafeError('work_report_link_inventory_invalid', static fn () => CompanyBackupWorkReportLinkInventory::fromRows([
            ['id' => 1, 'token' => str_repeat('e', 48), 'target_collision' => false, 'target_owner' => 'secret'],
        ]));
        $this->assertSafeError('work_report_link_inventory_invalid', static fn () => CompanyBackupWorkReportLinkInventory::fromRows([
            ['id' => 1, 'token' => str_repeat('e', 48), 'target_collision' => 0],
        ]));
    }

    private function assertSafeError(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Neplatný inventář nesmí projít.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertStringNotContainsString(str_repeat('E', 48), $e->getMessage());
        }
    }
}
