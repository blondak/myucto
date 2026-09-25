<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Doplnění čísla dokladu u zápisů vydaných a přijatých faktur, které ho nemají
 * ({@see DocumentEntryNumber}). Díra vznikla u automatického zaúčtování po vystavení
 * a u přeúčtování: číslo dodávalo jen doúčtování dokladů. Běží jako auto-backfill
 * v migrate.php a ručně přes api/bin/document-entry-number-backfill.php.
 *
 * Sahá jen na zápisy v OTEVŘENÉM účetním období, stejně jako přečíslování bankovních
 * dokladů ({@see Bank\BankDocumentNumberBackfill}): uzavřené období zůstává ve stavu, ve
 * kterém bylo uzavřeno. Zámek data (locked_until) doplnění nebrání — číslo dokladu je
 * metadata zápisu, částky podaných přiznání se tím nemění.
 *
 * Idempotentní: mění jen zápisy s prázdným číslem, pro které doklad číslo má.
 */
final class DocumentEntryNumberBackfill
{
    public function __construct(
        private readonly Connection $db,
        private readonly ?ActivityLogger $activity = null,
    ) {}

    /** Počet zápisů, kterým by backfill číslo doplnil (napříč firmami). */
    public function pending(): int
    {
        return $this->run(null, false)['changed'];
    }

    /**
     * @return array{changed:int, changes:list<array{entry_id:int, supplier_id:int, source_type:string, source_id:int, entry_date:string, to:string}>}
     */
    public function run(?int $supplierId, bool $apply, ?int $userId = null): array
    {
        $number = DocumentEntryNumber::sql('je.source_type', 'je.source_id', 'je.supplier_id');
        $sql = "SELECT je.id, je.supplier_id, je.source_type, je.source_id, je.entry_date, {$number} AS target
                  FROM journal_entries je
                  JOIN accounting_periods p ON p.id = je.period_id AND p.supplier_id = je.supplier_id
                 WHERE je.source_type IN ('" . implode("', '", DocumentEntryNumber::SOURCE_TYPES) . "')
                   AND je.source_id IS NOT NULL
                   AND (je.document_no IS NULL OR TRIM(je.document_no) = '')
                   AND p.status = 'open'";
        $params = [];
        if ($supplierId !== null) {
            $sql .= ' AND je.supplier_id = ?';
            $params[] = $supplierId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY je.supplier_id, je.entry_date, je.id');
        $stmt->execute($params);

        $changes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['target'] === null || $r['target'] === '') {
                continue;
            }
            $changes[] = [
                'entry_id'    => (int) $r['id'],
                'supplier_id' => (int) $r['supplier_id'],
                'source_type' => (string) $r['source_type'],
                'source_id'   => (int) $r['source_id'],
                'entry_date'  => (string) $r['entry_date'],
                'to'          => (string) $r['target'],
            ];
        }

        if ($apply && $changes !== []) {
            $pdo = $this->db->pdo();
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) {
                $pdo->beginTransaction();
            }
            try {
                $update = $pdo->prepare(
                    "UPDATE journal_entries SET document_no = ?, row_version = row_version + 1
                      WHERE id = ? AND supplier_id = ? AND (document_no IS NULL OR TRIM(document_no) = '')"
                );
                $bySupplier = [];
                foreach ($changes as $c) {
                    $update->execute([$c['to'], $c['entry_id'], $c['supplier_id']]);
                    $bySupplier[$c['supplier_id']][] = ['id' => $c['entry_id'], 'to' => $c['to']];
                }
                foreach ($bySupplier as $sid => $entries) {
                    $this->activity?->log('accounting.document_entry_no_filled', $userId, 'supplier', $sid, [
                        'count' => count($entries), 'entries' => $entries,
                    ], null, null, $sid);
                }
                if ($ownTx) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        return ['changed' => count($changes), 'changes' => $changes];
    }
}
