<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Přečíslování existujících bankovních zápisů na čísla v řadě účtu
 * ({@see BankDocumentNumber}). Běží po zavedení řad (auto-backfill v migrate.php),
 * po změně řady účtu v nastavení a ručně přes api/bin/bank-document-series-backfill.php.
 *
 * Sahá jen na zápisy v OTEVŘENÉM účetním období. Uzavřené a schválené období (včetně
 * archivního balíčku závěrky) zůstává s čísly, se kterými bylo uzavřeno. Zámek data
 * (locked_until) přečíslování nebrání: chrání částky podaných přiznání a číslo dokladu
 * je metadata zápisu, stejně jako popis, který jde v otevřeném období upravit taky.
 *
 * Zápis se dohledá k pohybu přes source_id. Zápis odpojený stornem (source_id NULL)
 * přes technické číslo BANK-<id>, návrh zaúčtování, který na něj ukazuje, nebo přes
 * shodné ID pohybu z banky a datum. Storno přebírá výsledné číslo stornovaného zápisu
 * s předponou STORNO, stejně jako {@see \MyInvoice\Service\Accounting\PostingService::reverse()}.
 * Zápis, ke kterému pohyb ani vlastní účet dohledat nejde (převzatá historie z jiného
 * programu, výpis cizího účtu), zůstává beze změny.
 *
 * Idempotentní: mění jen zápisy, jejichž číslo se liší od spočteného.
 */
final class BankDocumentNumberBackfill
{
    private const STORNO_PREFIX = 'STORNO ';

    public function __construct(
        private readonly Connection $db,
        private readonly ?ActivityLogger $activity = null,
    ) {}

    /** Počet zápisů, které by přečíslování změnilo (napříč firmami). */
    public function pending(): int
    {
        return $this->run(null, null, false)['changed'];
    }

    /**
     * @return array{checked:int, changed:int, unresolved:int, changes:list<array{entry_id:int, supplier_id:int, entry_date:string, from:?string, to:string}>}
     */
    public function run(?int $supplierId, ?string $fromDate, bool $apply, ?int $userId = null): array
    {
        $numbers = new BankDocumentNumber($this->db);
        $result = ['checked' => 0, 'changed' => 0, 'unresolved' => 0, 'changes' => []];
        foreach ($this->supplierIds($supplierId, $fromDate) as $sid) {
            $pdo = $this->db->pdo();
            $ownTx = $apply && !$pdo->inTransaction();
            if ($ownTx) {
                $pdo->beginTransaction();
            }
            try {
                if ($apply) {
                    $numbers->ensureAllForSupplier($sid);
                }
                $part = $this->runSupplier($numbers, $sid, $fromDate, $apply);
                if ($apply && $part['changes'] !== []) {
                    $this->activity?->log('accounting.bank_document_no_renumbered', $userId, 'supplier', $sid, [
                        'count'   => count($part['changes']),
                        'entries' => array_map(
                            static fn (array $c): array => ['id' => $c['entry_id'], 'from' => $c['from'], 'to' => $c['to']],
                            $part['changes'],
                        ),
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
            $result['checked'] += $part['checked'];
            $result['unresolved'] += $part['unresolved'];
            $result['changed'] += count($part['changes']);
            array_push($result['changes'], ...$part['changes']);
        }
        return $result;
    }

    /**
     * @return array{checked:int, unresolved:int, changes:list<array{entry_id:int, supplier_id:int, entry_date:string, from:?string, to:string}>}
     */
    private function runSupplier(BankDocumentNumber $numbers, int $supplierId, ?string $fromDate, bool $apply): array
    {
        $entries = $this->openBankEntries($supplierId, $fromDate);
        $reversalOf = [];
        foreach ($entries as $entry) {
            if ($entry['reversed_by'] !== null) {
                $reversalOf[(int) $entry['reversed_by']] = (int) $entry['id'];
            }
        }

        $update = $this->db->pdo()->prepare(
            'UPDATE journal_entries SET document_no = ?, row_version = row_version + 1
              WHERE id = ? AND supplier_id = ? AND document_no <=> ?'
        );
        $checked = 0;
        $unresolved = 0;
        $changes = [];
        $finalNo = [];

        // Nejdřív zápisy pohybů, pak storna — storno přebírá VÝSLEDNÉ číslo originálu.
        $originals = array_filter($entries, fn (array $e): bool => !$this->isReversal($e, $reversalOf));
        $reversals = array_filter($entries, fn (array $e): bool => $this->isReversal($e, $reversalOf));

        foreach ($originals as $entry) {
            $checked++;
            $id = (int) $entry['id'];
            $txId = $this->transactionFor($supplierId, $entry);
            $target = $txId === null ? null : $numbers->seriesNumber($supplierId, $txId, (string) $entry['entry_date'], $apply);
            if ($target === null) {
                $unresolved++;
                $finalNo[$id] = $entry['document_no'];
                continue;
            }
            $finalNo[$id] = $target;
            if ($target !== $entry['document_no']) {
                $changes[] = $this->change($supplierId, $entry, $target);
                if ($apply) {
                    $update->execute([$target, $id, $supplierId, $entry['document_no']]);
                }
            }
        }

        foreach ($reversals as $entry) {
            $checked++;
            $originalId = $this->reversedEntryId($supplierId, (int) $entry['id'], $reversalOf);
            $originalNo = $originalId === null
                ? null
                : (array_key_exists($originalId, $finalNo) ? $finalNo[$originalId] : $this->documentNoOf($supplierId, $originalId));
            if ($originalNo === null) {
                $unresolved++;
                continue;
            }
            $target = mb_substr(self::STORNO_PREFIX . $originalNo, 0, 50);
            if ($target !== $entry['document_no']) {
                $changes[] = $this->change($supplierId, $entry, $target);
                if ($apply) {
                    $update->execute([$target, (int) $entry['id'], $supplierId, $entry['document_no']]);
                }
            }
        }

        return ['checked' => $checked, 'unresolved' => $unresolved, 'changes' => $changes];
    }

    /**
     * Je zápis storno jiného bankového zápisu? Storno nese source_id NULL a vede na něj
     * reversed_by stornovaného zápisu.
     *
     * @param array<string,mixed> $entry
     * @param array<int,int> $reversalOf id storna → id stornovaného zápisu (v otevřeném období)
     */
    private function isReversal(array $entry, array $reversalOf): bool
    {
        if ($entry['source_id'] !== null) {
            return false;
        }
        return isset($reversalOf[(int) $entry['id']]) || $this->reversedEntryId((int) $entry['supplier_id'], (int) $entry['id'], $reversalOf) !== null;
    }

    /** @param array<int,int> $reversalOf */
    private function reversedEntryId(int $supplierId, int $reversalId, array $reversalOf): ?int
    {
        if (isset($reversalOf[$reversalId])) {
            return $reversalOf[$reversalId];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM journal_entries WHERE supplier_id = ? AND reversed_by = ? AND source_type = 'bank' LIMIT 1"
        );
        $stmt->execute([$supplierId, $reversalId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function documentNoOf(int $supplierId, int $entryId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT document_no FROM journal_entries WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$entryId, $supplierId]);
        $no = $stmt->fetchColumn();
        return $no === false || $no === null ? null : (string) $no;
    }

    /**
     * Pohyb, ke kterému zápis patří. Odpojený zápis (po stornu) už source_id nemá.
     *
     * @param array<string,mixed> $entry
     */
    private function transactionFor(int $supplierId, array $entry): ?int
    {
        if ($entry['source_id'] !== null) {
            return (int) $entry['source_id'];
        }
        $pdo = $this->db->pdo();
        $legacyId = BankDocumentNumber::legacyTxId($entry['document_no']);
        if ($legacyId !== null && $this->txExists($legacyId)) {
            return $legacyId;
        }
        $stmt = $pdo->prepare(
            'SELECT bank_transaction_id FROM bank_posting_suggestions
              WHERE supplier_id = ? AND journal_entry_id = ? AND bank_transaction_id IS NOT NULL
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, (int) $entry['id']]);
        $txId = $stmt->fetchColumn();
        if ($txId !== false && $this->txExists((int) $txId)) {
            return (int) $txId;
        }
        if ($entry['document_no'] === null || trim((string) $entry['document_no']) === '') {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT bt.id FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.bank_ref = ? AND DATE(bt.posted_at) = ? AND ' . BankStatementOwnershipResolver::sql('bs') . '
              LIMIT 2'
        );
        $stmt->execute([
            (string) $entry['document_no'],
            (string) $entry['entry_date'],
            ...BankStatementOwnershipResolver::params($supplierId),
        ]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    private function txExists(int $txId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM bank_transactions WHERE id = ?');
        $stmt->execute([$txId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    private function openBankEntries(int $supplierId, ?string $fromDate): array
    {
        $sql = "SELECT je.id, je.supplier_id, je.entry_date, je.document_no, je.source_id, je.reversed_by
                  FROM journal_entries je
                  JOIN accounting_periods p ON p.id = je.period_id AND p.supplier_id = je.supplier_id
                 WHERE je.supplier_id = ? AND je.source_type = 'bank' AND p.status = 'open'";
        $params = [$supplierId];
        if ($fromDate !== null) {
            $sql .= ' AND je.entry_date >= ?';
            $params[] = $fromDate;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY je.entry_date, je.id');
        $stmt->execute($params);
        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['supplier_id'] = (int) $r['supplier_id'];
            $r['source_id'] = $r['source_id'] === null ? null : (int) $r['source_id'];
            $r['reversed_by'] = $r['reversed_by'] === null ? null : (int) $r['reversed_by'];
            $r['document_no'] = $r['document_no'] === null ? null : (string) $r['document_no'];
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<int> */
    private function supplierIds(?int $supplierId, ?string $fromDate): array
    {
        if ($supplierId !== null) {
            return [$supplierId];
        }
        $sql = "SELECT DISTINCT je.supplier_id
                  FROM journal_entries je
                  JOIN accounting_periods p ON p.id = je.period_id AND p.supplier_id = je.supplier_id
                 WHERE je.source_type = 'bank' AND p.status = 'open'";
        $params = [];
        if ($fromDate !== null) {
            $sql .= ' AND je.entry_date >= ?';
            $params[] = $fromDate;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY je.supplier_id');
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param array<string,mixed> $entry
     * @return array{entry_id:int, supplier_id:int, entry_date:string, from:?string, to:string}
     */
    private function change(int $supplierId, array $entry, string $target): array
    {
        return [
            'entry_id'    => (int) $entry['id'],
            'supplier_id' => $supplierId,
            'entry_date'  => (string) $entry['entry_date'],
            'from'        => $entry['document_no'],
            'to'          => $target,
        ];
    }
}
