<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Úhrady faktur zápočtem proti zvolenému účtu (migrace 1126). Hlavička je jediná
 * tabulka — položky nejsou potřeba, protiúčet je jeden a částka jedna.
 */
final class InvoiceSettlementRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @param array{doc_type:string, doc_id:int, settled_on:string, amount:float, account_id:int, note:?string, created_by:?int} $data */
    public function insert(int $supplierId, array $data): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoice_settlements
                (supplier_id, doc_type, doc_id, settled_on, amount, account_id, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $data['doc_type'],
            $data['doc_id'],
            $data['settled_on'],
            round($data['amount'], 2),
            $data['account_id'],
            $data['note'],
            $data['created_by'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Potvrzené zápočty BEZ účetního zápisu — kandidáti na doúčtování.
     *
     * Vznikají dvěma cestami: zápočet pořízený v daňové evidenci (deník tam není) a zápočet,
     * jehož zápis smazalo hromadné přeúčtování deníku — `journal_entry_id` má
     * `ON DELETE SET NULL`, takže vazba tiše zmizí a zůstane evidovaná úhrada bez zápisu.
     * Číslo dokladu se tahá rovnou, ať se pro popis zápisu nemusí chodit do dvou tabulek.
     *
     * @return list<array<string,mixed>>
     */
    public function unpostedConfirmed(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.*, a.account_code,
                    CASE WHEN s.doc_type = 'invoice'
                         THEN (SELECT COALESCE(NULLIF(i.varsymbol, ''), CONCAT('#', i.id))
                                 FROM invoices i WHERE i.id = s.doc_id AND i.supplier_id = s.supplier_id)
                         ELSE (SELECT COALESCE(NULLIF(p.vendor_invoice_number, ''), NULLIF(p.varsymbol, ''), CONCAT('#', p.id))
                                 FROM purchase_invoices p WHERE p.id = s.doc_id AND p.supplier_id = s.supplier_id)
                    END AS doc_no
               FROM invoice_settlements s
               JOIN chart_of_accounts a ON a.id = s.account_id
              WHERE s.supplier_id = ? AND s.status = 'confirmed' AND s.journal_entry_id IS NULL
              ORDER BY s.settled_on, s.id"
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Jeden potvrzený zápočet bez zápisu — pro doúčtování z detailu dokladu.
     * Vrací týž tvar jako {@see unpostedConfirmed()}, ať se zápis skládá jednou cestou.
     *
     * @return array<string,mixed>|null
     */
    public function findUnpostedConfirmed(int $supplierId, int $settlementId): ?array
    {
        foreach ($this->unpostedConfirmed($supplierId) as $row) {
            if ((int) $row['id'] === $settlementId) {
                return $row;
            }
        }
        return null;
    }

    /** Je vydaný doklad zálohová proforma? (rozhoduje saldokontní účet 324 vs. 311) */
    public function invoiceIsProforma(int $supplierId, int $docId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT invoice_type FROM invoices WHERE id = ? AND supplier_id = ?"
        );
        $stmt->execute([$docId, $supplierId]);
        return (string) ($stmt->fetchColumn() ?: '') === 'proforma';
    }

    /** Je přijatý doklad zálohová faktura? (rozhoduje saldokontní účet 314 vs. 321) */
    public function purchaseIsAdvance(int $supplierId, int $docId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT document_kind FROM purchase_invoices WHERE id = ? AND supplier_id = ?"
        );
        $stmt->execute([$docId, $supplierId]);
        return (string) ($stmt->fetchColumn() ?: '') === 'advance';
    }

    /** Řádkový zámek na dobu transakce — serializuje souběžné create()/cancel(). */
    public function lockSettlement(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM invoice_settlements WHERE id = ? AND supplier_id = ? FOR UPDATE'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*, a.account_code, a.name AS account_name
               FROM invoice_settlements s
               JOIN chart_of_accounts a ON a.id = s.account_id
              WHERE s.id = ? AND s.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> potvrzené i zrušené zápočty dokladu (novější první) */
    public function listForDocument(int $supplierId, string $docType, int $docId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*, a.account_code, a.name AS account_name
               FROM invoice_settlements s
               JOIN chart_of_accounts a ON a.id = s.account_id
              WHERE s.supplier_id = ? AND s.doc_type = ? AND s.doc_id = ?
              ORDER BY s.settled_on DESC, s.id DESC'
        );
        $stmt->execute([$supplierId, $docType, $docId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function setPosted(int $id, ?int $entryId, ?int $paymentId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoice_settlements SET journal_entry_id = ?, invoice_payment_id = ? WHERE id = ?'
        )->execute([$entryId, $paymentId, $id]);
    }

    public function setCancelled(int $id, int $supplierId, ?int $reversalEntryId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE invoice_settlements
                SET status = 'cancelled', reversal_entry_id = ?, invoice_payment_id = NULL
              WHERE id = ? AND supplier_id = ?"
        )->execute([$reversalEntryId, $id, $supplierId]);
    }

    /**
     * Zbytek vydané faktury k úhradě (CZK) s řádkovým zámkem — lock se drží po
     * zbytek transakce, takže souběžný zápočet/platba nemůže fakturu přeplatit.
     * NULL = doklad nenalezen.
     *
     * @return array{remaining:float, status:string, currency:string, number:string, kind:string}|null
     */
    public function lockInvoice(int $supplierId, int $docId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.amount_to_pay, i.paid_total, i.status, i.varsymbol, i.invoice_type, c.code AS currency
               FROM invoices i
               LEFT JOIN currencies c ON c.id = i.currency_id
              WHERE i.id = ? AND i.supplier_id = ?
              FOR UPDATE'
        );
        $stmt->execute([$docId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'remaining' => round((float) $row['amount_to_pay'] - (float) $row['paid_total'], 2),
            'status'    => (string) $row['status'],
            'currency'  => (string) ($row['currency'] ?? 'CZK'),
            'number'    => (string) $row['varsymbol'],
            'kind'      => (string) ($row['invoice_type'] ?? 'invoice'),
        ];
    }

    /**
     * Přijatá faktura pro zápočet, se ZBYTKEM k úhradě.
     *
     * Řádek se zamyká `FOR UPDATE`, takže dvě souběžné žádosti o zápočet téhož dokladu
     * se serializují a druhá uvidí zbytek už snížený tou první — bez toho by šlo započíst
     * dvakrát celou fakturu. Zbytek počítá sdílený {@see PurchaseSettledExpr}: přijatá
     * faktura nemá `paid_total`, úhrada k ní visí ve třech tabulkách podle kanálu.
     *
     * @return array{total:float, remaining:float, status:string, currency:string, number:string, kind:string}|null
     */
    public function lockPurchase(int $supplierId, int $docId): ?array
    {
        $remaining = PurchaseSettledExpr::remaining('p');
        $settled = PurchaseSettledExpr::settled('p');
        $stmt = $this->db->pdo()->prepare(
            "SELECT p.total_with_vat, p.status, p.document_kind, p.vendor_invoice_number, p.varsymbol,
                    p.exchange_rate, c.code AS currency,
                    ({$remaining}) AS remaining,
                    ({$settled}) AS settled
               FROM purchase_invoices p
               LEFT JOIN currencies c ON c.id = p.currency_id
              WHERE p.id = ? AND p.supplier_id = ?
              FOR UPDATE"
        );
        $stmt->execute([$docId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $number = (string) ($row['vendor_invoice_number'] ?? '');
        return [
            'total'         => round((float) $row['total_with_vat'], 2),
            'remaining'     => round((float) $row['remaining'], 2),
            'settled'       => round((float) $row['settled'], 2),
            'status'        => (string) $row['status'],
            'currency'      => (string) ($row['currency'] ?? 'CZK'),
            'exchange_rate' => $row['exchange_rate'] !== null ? (float) $row['exchange_rate'] : null,
            'number'        => $number !== '' ? $number : (string) ($row['varsymbol'] ?? ''),
            'kind'          => (string) ($row['document_kind'] ?? 'invoice'),
        ];
    }

    /**
     * Kurz, kterým je přijatá faktura předepsaná na saldokontě — z cizoměnové stopy
     * zaúčtovaného předpisu, s fallbackem na kurz dokladu. Zrcadlí
     * `BankPostingService::predpisFxRate()`: závazek se musí odúčtovat týmž kurzem, jakým
     * vznikl, jinak na 321 zůstane kurzový drobek.
     */
    public function purchasePredpisRate(int $supplierId, int $docId): ?float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.fx_rate
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
              WHERE e.supplier_id = ? AND e.source_type = 'purchase_invoice' AND e.source_id = ?
                AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                AND l.currency_code IS NOT NULL AND l.fx_rate > 0
              ORDER BY e.id DESC, l.line_no, l.id
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $docId]);
        $rate = $stmt->fetchColumn();
        if ($rate !== false && (float) $rate > 0) {
            return (float) $rate;
        }
        $doc = $this->db->pdo()->prepare('SELECT exchange_rate FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $doc->execute([$docId, $supplierId]);
        $fallback = $doc->fetchColumn();
        return $fallback !== false && (float) $fallback > 0 ? (float) $fallback : null;
    }

    /** Měna přijaté faktury (kód), nebo null, když doklad neexistuje. */
    public function purchaseCurrency(int $supplierId, int $docId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.code FROM purchase_invoices p JOIN currencies c ON c.id = p.currency_id
              WHERE p.id = ? AND p.supplier_id = ?'
        );
        $stmt->execute([$docId, $supplierId]);
        $code = $stmt->fetchColumn();
        return $code === false ? null : strtoupper((string) $code);
    }
}
