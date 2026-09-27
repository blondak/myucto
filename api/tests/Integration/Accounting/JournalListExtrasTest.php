<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Service\Accounting\JournalListExtras;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sloupce Poznámka a Dokumenty v seznamu deníku: připnutá poznámka má přednost před
 * novější, dokumenty jsou přílohy zápisu plus dokumenty z úložiště navázané na zápis.
 */
#[Group('integration')]
final class JournalListExtrasTest extends BankPostingTestCase
{
    public function testNotePreviewPrefersPinnedAndCountsDocuments(): void
    {
        $withExtras = $this->postPredpis('manual', 1, '518', '321', 100.00);
        $plain = $this->postPredpis('manual', 2, '518', '321', 200.00);
        $pdo = $this->db->pdo();

        $note = $pdo->prepare('INSERT INTO journal_entry_notes (supplier_id, entry_id, body, pinned, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $note->execute([$this->supplierId, $withExtras, "Připnutá\n  poznámka", 1, $this->userId, '2026-01-01 10:00:00']);
        $note->execute([$this->supplierId, $withExtras, 'Novější nepřipnutá', 0, $this->userId, '2026-02-01 10:00:00']);
        $note->execute([$this->supplierId, $withExtras, 'Smazaná', 1, $this->userId, '2026-03-01 10:00:00']);
        $pdo->prepare('UPDATE journal_entry_notes SET deleted_at = NOW() WHERE body = ? AND entry_id = ?')->execute(['Smazaná', $withExtras]);

        $pdo->prepare(
            "INSERT INTO journal_entry_attachments (entry_id, supplier_id, sha256, filename, original_name, mime_type, size_bytes, uploaded_by)
             VALUES (?, ?, ?, 'priloha.pdf', 'priloha.pdf', 'application/pdf', 100, ?)"
        )->execute([$withExtras, $this->supplierId, hash('sha256', 'priloha-' . bin2hex(random_bytes(6))), $this->userId]);
        $pdo->prepare(
            "INSERT INTO documents (supplier_id, title, original_name, filename, sha256, mime_type, size_bytes, doc_type)
             VALUES (?, 'Syntetický sken', 'sken.pdf', 'sken.pdf', ?, 'application/pdf', 100, 'pdf')"
        )->execute([$this->supplierId, hash('sha256', 'sken-' . bin2hex(random_bytes(6)))]);
        $pdo->prepare("INSERT INTO document_links (document_id, supplier_id, entity_type, entity_id) VALUES (?, ?, 'journal_entry', ?)")
            ->execute([(int) $pdo->lastInsertId(), $this->supplierId, $withExtras]);

        $extras = $this->container->get(JournalListExtras::class);
        $notes = $extras->notes($this->supplierId, [$withExtras, $plain]);
        $documents = $extras->documentCounts($this->supplierId, [$withExtras, $plain]);

        self::assertSame(['preview' => 'Připnutá poznámka', 'count' => 2], $notes[$withExtras]);
        self::assertArrayNotHasKey($plain, $notes);
        self::assertSame(2, $documents[$withExtras]);
        self::assertArrayNotHasKey($plain, $documents);
        self::assertSame([], $extras->notes($this->supplierId, []));
    }
}
