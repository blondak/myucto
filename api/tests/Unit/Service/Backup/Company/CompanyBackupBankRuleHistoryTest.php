<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupBankRuleHistory as History;
use PHPUnit\Framework\TestCase;

final class CompanyBackupBankRuleHistoryTest extends TestCase
{
    private const BACKUP = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';

    public function testKeepsProvenanceAcrossRestoreAndLaterRejections(): void
    {
        $source = ['supplier_id' => 7, 'last_rejected_tx_id' => 99, 'rejected_streak' => 2];
        $archived = History::detachMissing($source, self::BACKUP, static fn (): ?int => null);
        self::assertNull($archived['last_rejected_tx_id']);
        self::assertSame(2, $archived['rejected_streak']);
        self::assertSame(99, $source['last_rejected_tx_id']);
        $archived['supplier_id'] = 71;
        $lookup = static function (): never { throw new \LogicException('Archivní ID se nesmí hledat.'); };
        self::assertSame($archived, History::detachMissing($archived, self::BACKUP, $lookup));
        // Nové odmítnutí po ručním zapnutí pravidla nesmí vymazat starší historii.
        $archived['last_rejected_tx_id'] = 99;
        self::assertSame($archived, History::detachMissing($archived, self::BACKUP, static fn (): int => 71));
        $next = History::detachMissing($archived, self::BACKUP, static fn (): ?int => null);
        $history = json_decode($next[History::COLUMN], true, 16, JSON_THROW_ON_ERROR)['transactions'];
        self::assertSame([
            ['id' => 99, 'supplier_id' => 7, 'backup_id' => self::BACKUP],
            ['id' => 99, 'supplier_id' => 71, 'backup_id' => self::BACKUP],
        ], $history);
    }

    public function testRejectsMalformedOrDuplicateProvenance(): void
    {
        $entry = ['id' => 99, 'supplier_id' => 7, 'backup_id' => self::BACKUP];
        foreach ([[], [$entry, $entry], [[...$entry, 'id' => 0]],
            [[...$entry, 'backup_id' => 'invalid']], [[...$entry, 'live' => true]]] as $entries) {
            try {
                History::assertRow(['last_rejected_tx_id' => null,
                    History::COLUMN => json_encode(['version' => 1, 'transactions' => $entries], JSON_THROW_ON_ERROR)]);
                self::fail('Poškozená identita nesmí projít.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
