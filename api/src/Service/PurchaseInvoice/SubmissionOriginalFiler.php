<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Service\Document\DocumentIngestService;
use PDO;

/**
 * Kde v Dokumentech leží originál příchozího dokladu podle stavu podání.
 *
 * Čekající originál je v „Příchozí doklady / rok / měsíc" ({@see SubmissionFolder::segments()}).
 * Zpracovaný se přesune do „Příchozí doklady / Archiv / rok / měsíc", aby ve složce zůstalo
 * jen to, co na zpracování čeká. Originál se nemaže: je auditní stopou toho, co klient
 * předal ({@see \MyInvoice\Repository\Deletion\DocumentDeletionGuard}). Když se výsledná
 * faktura smaže a podání se vrátí do fronty, vrátí se originál zpátky.
 *
 * Přesouvá se jen dokument, který leží tam, kam ho fronta sama dala. Co uživatel
 * přesunul do vlastní složky, zůstává, kde je.
 */
final class SubmissionOriginalFiler
{
    public function __construct(
        private readonly Connection $db,
        private readonly DocumentFolderRepository $folders,
        private readonly DocumentIngestService $ingest,
    ) {}

    /** @param array<string,mixed> $submission řádek `purchase_invoice_submissions` */
    public function archive(int $supplierId, array $submission): void
    {
        $receivedAt = new \DateTimeImmutable((string) $submission['created_at']);
        $this->move(
            $supplierId,
            (int) $submission['document_id'],
            SubmissionFolder::segments($receivedAt),
            SubmissionFolder::archiveSegments($receivedAt),
        );
    }

    /** @param list<int> $submissionIds podání vrácená do fronty */
    public function restore(int $supplierId, array $submissionIds): void
    {
        if ($submissionIds === []) return;
        $in = implode(',', array_fill(0, count($submissionIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT document_id, created_at FROM purchase_invoice_submissions WHERE supplier_id = ? AND id IN ($in)"
        );
        $stmt->execute(array_merge([$supplierId], $submissionIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $receivedAt = new \DateTimeImmutable((string) $row['created_at']);
            $this->move(
                $supplierId,
                (int) $row['document_id'],
                SubmissionFolder::archiveSegments($receivedAt),
                SubmissionFolder::segments($receivedAt),
            );
        }
    }

    /**
     * @param list<string> $from
     * @param list<string> $to
     */
    private function move(int $supplierId, int $documentId, array $from, array $to): void
    {
        $fromId = $this->findPath($supplierId, $from);
        if ($fromId === null) return;
        $current = $this->db->pdo()->prepare(
            'SELECT folder_id FROM documents WHERE id = ? AND supplier_id = ? AND deleted_at IS NULL'
        );
        $current->execute([$documentId, $supplierId]);
        if ((int) $current->fetchColumn() !== $fromId) return;

        $toId = $this->ingest->ensureFolderPath($supplierId, null, $to, null);
        $this->db->pdo()->prepare(
            'UPDATE documents SET folder_id = ? WHERE id = ? AND supplier_id = ? AND folder_id = ?'
        )->execute([$toId, $documentId, $supplierId, $fromId]);
    }

    /** @param list<string> $segments */
    private function findPath(int $supplierId, array $segments): ?int
    {
        $current = null;
        foreach ($segments as $segment) {
            $current = $this->folders->findChildIdByName($supplierId, $current, $segment);
            if ($current === null) return null;
        }
        return $current;
    }
}
