<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Service\Document\DocumentIngestService;
use PDO;

/**
 * Přesun originálů příchozích dokladů, které skončily v kořeni Dokumentů (před zavedením
 * {@see SubmissionFolder}), do „Příchozí doklady / rok / měsíc" podle data převzetí.
 *
 * Bere jen dokumenty podání, které jsou pořád v kořeni. Co uživatel mezitím přesunul
 * do vlastní složky, zůstává, kde je. Běžné dokumenty nahrané do Dokumentů zasáhnout
 * nemůže: podání vzniká jen v {@see PurchaseInvoiceSubmissionUploadService}, které pro
 * něj vždy založí nový dokument (ani stejný obsah se nesdílí s existujícím dokumentem),
 * takže `purchase_invoice_submissions.document_id` ukazuje výhradně na originály fronty. Idempotentní: přesunutý dokument už v kořeni není,
 * takže se auto-backfill v migrate.php nespouští dokola.
 */
final class SubmissionFolderBackfill
{
    private const PENDING_SQL = 'FROM purchase_invoice_submissions s
        JOIN documents d ON d.id = s.document_id AND d.supplier_id = s.supplier_id
       WHERE d.folder_id IS NULL AND d.deleted_at IS NULL';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?DocumentIngestService $ingest = null,
    ) {}

    public function pending(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) ' . self::PENDING_SQL)->fetchColumn();
    }

    /**
     * @param (callable(int, int, string): void)|null $onRow dokument, firma, cílová cesta
     * @return array{found:int, moved:int}
     */
    public function run(bool $apply, ?callable $onRow = null): array
    {
        if ($apply && $this->ingest === null) {
            throw new \LogicException('Přesun potřebuje DocumentIngestService.');
        }
        $rows = $this->pdo->query(
            'SELECT d.id, s.supplier_id, s.created_at ' . self::PENDING_SQL . ' ORDER BY s.supplier_id, s.created_at, d.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $move = $this->pdo->prepare('UPDATE documents SET folder_id = ? WHERE id = ? AND supplier_id = ? AND folder_id IS NULL');
        $moved = 0;
        foreach ($rows as $row) {
            $segments = SubmissionFolder::segments(new \DateTimeImmutable((string) $row['created_at']));
            if ($onRow !== null) {
                $onRow((int) $row['id'], (int) $row['supplier_id'], implode(' / ', $segments));
            }
            if (!$apply) {
                continue;
            }
            $folderId = $this->ingest->ensureFolderPath((int) $row['supplier_id'], null, $segments, null);
            $move->execute([$folderId, (int) $row['id'], (int) $row['supplier_id']]);
            $moved += $move->rowCount();
        }
        return ['found' => count($rows), 'moved' => $moved];
    }
}
