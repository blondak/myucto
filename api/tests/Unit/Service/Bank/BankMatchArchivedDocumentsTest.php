<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\Match\BankMatchArchivedDocuments as Archive;
use MyInvoice\Service\Bank\Match\MatchSuggestionException;
use PHPUnit\Framework\TestCase;

final class BankMatchArchivedDocumentsTest extends TestCase
{
    private const BACKUP = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';

    public function testMissingAuditDocumentIsDetachedButLiveDocumentStaysRemappable(): void
    {
        $row = ['supplier_id' => 7, 'invoice_ids' => '[11,12]', 'purchase_invoice_id' => 13,
            Archive::COLUMN => null, 'score' => '0.900'];
        $restored = Archive::detachMissing('bank_match_audit', $row, self::BACKUP,
            static fn (string $table, int $id): ?int => $id === 12 ? 7 : null);
        self::assertSame('[null,12]', $restored['invoice_ids']);
        self::assertNull($restored['purchase_invoice_id']);
        self::assertSame('0.900', $restored['score']);
        $documents = json_decode($restored[Archive::COLUMN], true, 512, JSON_THROW_ON_ERROR)['documents'];
        self::assertSame([11, 13], array_column($documents, 'id'));
        self::assertSame(['invoices', 'purchase_invoices'], array_column($documents, 'table'));
        self::assertSame([self::BACKUP, self::BACKUP], array_column($documents, 'backup_id'));
        self::assertSame('[11,12]', $row['invoice_ids']);
        $restored['supplier_id'] = 99;
        self::assertSame($restored, Archive::detachMissing('bank_match_audit', $restored,
            '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a2', static fn (): int => 99));
    }

    public function testMissingSplitCandidateCannotRemainPendingOrBeAccepted(): void
    {
        $row = ['supplier_id' => 7, 'status' => 'pending', Archive::COLUMN => null,
            'candidates_json' => '[{"type":"split","invoice_ids":[11,12],"display":{"amount":123.45}}]'];
        $result = Archive::detachMissing('bank_match_suggestions', $row, self::BACKUP,
            static fn (string $table, int $id): ?int => $id === 11 ? 7 : null);
        self::assertSame('superseded', $result['status']);
        self::assertSame('pending', json_decode($result[Archive::COLUMN], true, 512, JSON_THROW_ON_ERROR)['original_status']);
        $candidate = json_decode($result['candidates_json'], true, 512, JSON_THROW_ON_ERROR)[0];
        self::assertSame([11, null], $candidate['invoice_ids']);
        self::assertSame(123.45, $candidate['display']['amount']);
        $this->expectException(MatchSuggestionException::class);
        Archive::assertAcceptable($result);
    }

    public function testForeignTenantIsRejectedInsteadOfHiddenAsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('jiné firmě');
        Archive::detachMissing('bank_match_audit', [
            'supplier_id' => 7, 'invoice_ids' => '[11]', 'purchase_invoice_id' => null,
        ], self::BACKUP, static fn (): int => 8);
    }

    public function testNoChangesWhenEveryDocumentStillExists(): void
    {
        $row = ['supplier_id' => 7, 'invoice_ids' => '[11]', 'purchase_invoice_id' => null];
        self::assertSame($row, Archive::detachMissing('bank_match_audit', $row, self::BACKUP,
            static fn (): int => 7));
    }

    public function testArchiveIdentityCannotCoexistWithLiveId(): void
    {
        $row = Archive::detachMissing('bank_match_audit', [
            'supplier_id' => 7, 'invoice_ids' => '[11]', 'purchase_invoice_id' => null,
        ], self::BACKUP, static fn (): ?int => null);
        $row['invoice_ids'] = '[99]';
        $this->expectException(\InvalidArgumentException::class);
        Archive::assertRow('bank_match_audit', $row);
    }

    public function testExceptionDoesNotApplyToLiveAccountingLinks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Archive::detachMissing('invoice_payments', ['supplier_id' => 7], self::BACKUP,
            static fn (): ?int => null);
    }

    public function testRemovedProvenanceOfMissingSplitMemberIsRejected(): void
    {
        $row = Archive::detachMissing('bank_match_suggestions', [
            'supplier_id' => 7, 'status' => 'pending',
            'candidates_json' => '[{"type":"split","invoice_ids":[11,12]}]',
        ], self::BACKUP, static fn (): ?int => null);
        $row[Archive::COLUMN] = null;
        $this->expectException(\InvalidArgumentException::class);
        Archive::assertRow('bank_match_suggestions', $row);
    }

    public function testUnknownCandidateIdentifiersAreNotSilentlyPreserved(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Archive::detachMissing('bank_match_suggestions', [
            'supplier_id' => 7, 'status' => 'pending',
            'candidates_json' => '[{"type":"invoice","invoice_id":11,"other_invoice_id":12}]',
        ], self::BACKUP, static fn (): int => 7);
    }
}
