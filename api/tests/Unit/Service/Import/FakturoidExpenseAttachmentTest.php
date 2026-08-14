<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\FakturoidExpenseAttachment;
use PHPUnit\Framework\TestCase;

/**
 * Issue #261 — import přijatých faktur z Fakturoidu četl skalární pole
 * `attachment`, které API v3 nevrací (přílohy jsou v `attachments[]`), takže se
 * originální doklady dodavatelů nikdy nestáhly.
 */
final class FakturoidExpenseAttachmentTest extends TestCase
{
    public function testResolvesUrlAndFilenameFromAttachments(): void
    {
        $att = FakturoidExpenseAttachment::resolve([
            'number'      => 'N2026-001',
            'attachments' => [
                ['download_url' => 'https://app.fakturoid.cz/download/1', 'filename' => 'doklad.pdf'],
            ],
        ]);
        self::assertSame(
            ['download_url' => 'https://app.fakturoid.cz/download/1', 'filename' => 'doklad.pdf'],
            $att,
        );
    }

    /** Bere první přílohu — výdaj má typicky jeden originální doklad. */
    public function testTakesFirstAttachment(): void
    {
        $att = FakturoidExpenseAttachment::resolve([
            'attachments' => [
                ['download_url' => 'https://app.fakturoid.cz/download/first', 'filename' => 'a.pdf'],
                ['download_url' => 'https://app.fakturoid.cz/download/second', 'filename' => 'b.pdf'],
            ],
        ]);
        self::assertSame('https://app.fakturoid.cz/download/first', $att['download_url']);
        self::assertSame('a.pdf', $att['filename']);
    }

    /** Chybí-li `filename`, název se odvodí z čísla dokladu. */
    public function testFilenameFallsBackToExpenseNumber(): void
    {
        $att = FakturoidExpenseAttachment::resolve([
            'number'      => 'N2026-007',
            'attachments' => [['download_url' => 'https://app.fakturoid.cz/download/7']],
        ]);
        self::assertSame('N2026-007.pdf', $att['filename']);
    }

    /** Bez čísla i názvu padá na neutrální `expense.pdf`. */
    public function testFilenameFallsBackToExpensePdf(): void
    {
        $att = FakturoidExpenseAttachment::resolve([
            'attachments' => [['download_url' => 'https://app.fakturoid.cz/download/x']],
        ]);
        self::assertSame('expense.pdf', $att['filename']);
    }

    /** Výdaj bez příloh → null (žádné PDF ke stažení). */
    public function testNoAttachmentsReturnsNull(): void
    {
        self::assertNull(FakturoidExpenseAttachment::resolve([]));
        self::assertNull(FakturoidExpenseAttachment::resolve(['attachments' => []]));
    }

    /** Prázdná/chybějící `download_url` → null. */
    public function testMissingDownloadUrlReturnsNull(): void
    {
        self::assertNull(FakturoidExpenseAttachment::resolve([
            'attachments' => [['filename' => 'doklad.pdf']],
        ]));
        self::assertNull(FakturoidExpenseAttachment::resolve([
            'attachments' => [['download_url' => '', 'filename' => 'doklad.pdf']],
        ]));
    }

    /** Regrese #261: skalární `attachment` (které Fakturoid neposílá) se ignoruje. */
    public function testScalarAttachmentFieldIsIgnored(): void
    {
        self::assertNull(FakturoidExpenseAttachment::resolve([
            'attachment' => 'https://app.fakturoid.cz/download/legacy',
        ]));
    }
}
