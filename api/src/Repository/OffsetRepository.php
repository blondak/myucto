<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Repository vzájemných zápočtů (Fáze F). Otevřené pohledávky (vydané faktury na
 * 311) a závazky (přijaté faktury na 321) jednoho partnera + evidence dohod o
 * zápočtu (offset_agreements / offset_agreement_items).
 *
 * V1 pracuje jen s CZK doklady (booking = měna dokladu → částky jednoznačné bez
 * kurzového přepočtu). Zbývající hodnota:
 *   - vydaná faktura: amount_to_pay − paid_total (invoice_payments, vč. dřívějších
 *     zápočtů, které se evidují jako platba),
 *   - přijatá faktura: amount_to_pay − Σ úhrad všemi kanály ({@see PurchaseSettledExpr})
 *     (u PF není paid_total; potvrzené zápočty se odečtou explicitně, ať se doklad
 *     nezapočte dvakrát).
 */
final class OffsetRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Otevřené pohledávky partnera (vydané faktury, CZK).
     *
     * @return list<array{doc_type:string, doc_id:int, doc_no:string, issue_date:string,
     *                    due_date:string, total:float, paid:float, remaining:float}>
     */
    public function openReceivables(int $supplierId, int $partnerId): array
    {
        $sql =
            "SELECT d.id AS doc_id,
                    COALESCE(NULLIF(d.varsymbol, ''), CONCAT('#', d.id)) AS doc_no,
                    d.issue_date, d.due_date,
                    d.amount_to_pay AS total, d.paid_total AS paid,
                    (d.amount_to_pay - d.paid_total) AS remaining
               FROM invoices d
               JOIN currencies cur ON cur.id = d.currency_id
              WHERE d.supplier_id = ? AND d.client_id = ?
                AND d.invoice_type = 'invoice'
                AND d.status IN ('issued','sent','reminded')
                AND cur.code = 'CZK'
                AND (d.amount_to_pay - d.paid_total) > 0.005
              ORDER BY d.due_date, d.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $partnerId]);
        return array_map(static fn (array $r): array => [
            'doc_type'   => 'invoice',
            'doc_id'     => (int) $r['doc_id'],
            'doc_no'     => (string) $r['doc_no'],
            'issue_date' => (string) $r['issue_date'],
            'due_date'   => (string) $r['due_date'],
            'total'      => round((float) $r['total'], 2),
            'paid'       => round((float) $r['paid'], 2),
            'remaining'  => round((float) $r['remaining'], 2),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Otevřené závazky partnera (přijaté faktury, CZK).
     *
     * @return list<array{doc_type:string, doc_id:int, doc_no:string, issue_date:string,
     *                    due_date:string, total:float, paid:float, remaining:float}>
     */
    public function openPayables(int $supplierId, int $partnerId): array
    {
        // Kolik je na PF uhrazeno — sdílený SSOT přes všechny kanály úhrady
        // (banka, vzájemný zápočet, zápočet proti účtu). Viz PurchaseSettledExpr.
        $settled = PurchaseSettledExpr::settled('d');
        $sql =
            "SELECT t.* FROM (
                SELECT d.id AS doc_id,
                       COALESCE(NULLIF(d.varsymbol, ''), CONCAT('#', d.id)) AS doc_no,
                       d.issue_date, d.due_date,
                       d.amount_to_pay AS total,
                       ({$settled}) AS paid,
                       (d.amount_to_pay - ({$settled})) AS remaining
                  FROM purchase_invoices d
                  JOIN currencies cur ON cur.id = d.currency_id
                 WHERE d.supplier_id = ? AND d.vendor_id = ?
                   AND d.document_kind = 'invoice'
                   AND d.status IN ('received','booked')
                   AND cur.code = 'CZK'
              ) t
             WHERE t.remaining > 0.005
             ORDER BY t.due_date, t.doc_id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $partnerId]);
        return array_map(static fn (array $r): array => [
            'doc_type'   => 'purchase_invoice',
            'doc_id'     => (int) $r['doc_id'],
            'doc_no'     => (string) $r['doc_no'],
            'issue_date' => (string) $r['issue_date'],
            'due_date'   => (string) $r['due_date'],
            'total'      => round((float) $r['total'], 2),
            'paid'       => round((float) $r['paid'], 2),
            'remaining'  => round((float) $r['remaining'], 2),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Partneři firmy, kteří mají zároveň otevřenou pohledávku i závazek (kandidáti
     * na zápočet) — pro výběrový seznam.
     *
     * @return list<array{partner_id:int, partner_name:string}>
     */
    public function partnersWithOpenBoth(int $supplierId): array
    {
        // Viz PurchaseSettledExpr — tentýž SSOT jako u seznamu kandidátů.
        $settled = PurchaseSettledExpr::settled('d');
        $sql =
            "SELECT cl.id AS partner_id, cl.company_name AS partner_name
               FROM clients cl
              WHERE EXISTS (
                        SELECT 1 FROM invoices d JOIN currencies c ON c.id = d.currency_id
                         WHERE d.supplier_id = ? AND d.client_id = cl.id AND d.invoice_type = 'invoice'
                           AND d.status IN ('issued','sent','reminded') AND c.code = 'CZK'
                           AND (d.amount_to_pay - d.paid_total) > 0.005)
                AND EXISTS (
                        SELECT 1 FROM purchase_invoices d JOIN currencies c ON c.id = d.currency_id
                         WHERE d.supplier_id = ? AND d.vendor_id = cl.id AND d.document_kind = 'invoice'
                           AND d.status IN ('received','booked') AND c.code = 'CZK'
                           AND (d.amount_to_pay - ({$settled})) > 0.005)
              ORDER BY cl.company_name";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $supplierId]);
        return array_map(static fn (array $r): array => [
            'partner_id'   => (int) $r['partner_id'],
            'partner_name' => (string) $r['partner_name'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function partnerName(int $supplierId, int $partnerId): ?string
    {
        // Partner musí mít u firmy aspoň jeden doklad (tenant scope) — jinak NULL.
        $stmt = $this->db->pdo()->prepare(
            "SELECT cl.company_name
               FROM clients cl
              WHERE cl.id = ?
                AND (EXISTS (SELECT 1 FROM invoices i WHERE i.client_id = cl.id AND i.supplier_id = ?)
                  OR EXISTS (SELECT 1 FROM purchase_invoices p WHERE p.vendor_id = cl.id AND p.supplier_id = ?))
              LIMIT 1"
        );
        $stmt->execute([$partnerId, $supplierId, $supplierId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function insertAgreement(int $supplierId, int $partnerId, string $date, string $documentNo, float $total, ?string $note, ?int $createdBy): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO offset_agreements
                (supplier_id, partner_id, agreement_date, document_no, total_amount, status, note, created_by)
             VALUES (?, ?, ?, ?, ?, "draft", ?, ?)'
        )->execute([$supplierId, $partnerId, $date, $documentNo, $total, $note, $createdBy]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function insertItem(int $agreementId, int $supplierId, string $docType, int $docId, float $amount): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO offset_agreement_items (agreement_id, supplier_id, doc_type, doc_id, amount)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$agreementId, $supplierId, $docType, $docId, $amount]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function setItemPaymentId(int $itemId, int $paymentId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE offset_agreement_items SET invoice_payment_id = ? WHERE id = ?'
        )->execute([$paymentId, $itemId]);
    }

    public function setConfirmed(int $agreementId, int $supplierId, int $journalEntryId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE offset_agreements SET status = "confirmed", journal_entry_id = ?
              WHERE id = ? AND supplier_id = ?'
        )->execute([$journalEntryId, $agreementId, $supplierId]);
    }

    public function setCancelled(int $agreementId, int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE offset_agreements SET status = "cancelled" WHERE id = ? AND supplier_id = ?'
        )->execute([$agreementId, $supplierId]);
    }

    /** @return array<string,mixed>|null */
    public function findAgreement(int $supplierId, int $agreementId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.*, cl.company_name AS partner_name
               FROM offset_agreements a
               JOIN clients cl ON cl.id = a.partner_id
              WHERE a.id = ? AND a.supplier_id = ?'
        );
        $stmt->execute([$agreementId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->castAgreement($row);
    }

    /**
     * Zamkne řádek dohody FOR UPDATE (musí běžet uvnitř transakce volajícího) —
     * serializuje souběžná confirm()/cancel() volání téže dohody (adversariální
     * review 2026-07: gate `if status==='draft'` sám o sobě dvě souběžná volání
     * nechrání, obě přečtou 'draft' dřív, než první stihne zapsat 'confirmed').
     *
     * @return array<string,mixed>|null
     */
    public function lockAgreement(int $supplierId, int $agreementId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.*, cl.company_name AS partner_name
               FROM offset_agreements a
               JOIN clients cl ON cl.id = a.partner_id
              WHERE a.id = ? AND a.supplier_id = ?
              FOR UPDATE'
        );
        $stmt->execute([$agreementId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->castAgreement($row);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function itemsFor(int $agreementId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT oi.id, oi.doc_type, oi.doc_id, oi.amount, oi.invoice_payment_id,
                    CASE WHEN oi.doc_type = 'invoice'
                         THEN (SELECT COALESCE(NULLIF(i.varsymbol,''), CONCAT('#', i.id)) FROM invoices i WHERE i.id = oi.doc_id)
                         ELSE (SELECT COALESCE(NULLIF(p.varsymbol,''), CONCAT('#', p.id)) FROM purchase_invoices p WHERE p.id = oi.doc_id)
                    END AS doc_no
               FROM offset_agreement_items oi
              WHERE oi.agreement_id = ?
              ORDER BY oi.doc_type DESC, oi.id"
        );
        $stmt->execute([$agreementId]);
        return array_map(static fn (array $r): array => [
            'id'                 => (int) $r['id'],
            'doc_type'           => (string) $r['doc_type'],
            'doc_id'             => (int) $r['doc_id'],
            'doc_no'             => (string) $r['doc_no'],
            'amount'             => round((float) $r['amount'], 2),
            'invoice_payment_id' => $r['invoice_payment_id'] !== null ? (int) $r['invoice_payment_id'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array{status?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function listAgreements(int $supplierId, array $filters = []): array
    {
        $sql =
            'SELECT a.*, cl.company_name AS partner_name
               FROM offset_agreements a
               JOIN clients cl ON cl.id = a.partner_id
              WHERE a.supplier_id = ?';
        $params = [$supplierId];
        if (!empty($filters['status']) && in_array($filters['status'], ['draft', 'confirmed', 'cancelled'], true)) {
            $sql .= ' AND a.status = ?';
            $params[] = $filters['status'];
        }
        $sql .= ' ORDER BY a.agreement_date DESC, a.id DESC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'castAgreement'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Zbývající hodnota přijaté faktury (CZK) k datu potvrzení — pro rozhodnutí,
     * zda ji zápočet plně vyrovná (→ status paid). Vylučuje TENTO zápočet.
     */
    public function purchaseRemaining(int $supplierId, int $docId, int $excludeAgreementId): float
    {
        $remaining = PurchaseSettledExpr::remaining('d', $excludeAgreementId);
        $stmt = $this->db->pdo()->prepare(
            "SELECT ({$remaining}) AS remaining
               FROM purchase_invoices d
              WHERE d.id = ? AND d.supplier_id = ?"
        );
        $stmt->execute([$docId, $supplierId]);
        $v = $stmt->fetchColumn();
        return $v === false ? 0.0 : round((float) $v, 2);
    }

    /**
     * Aktuální zbytek vydané faktury (CZK) k okamžiku POTVRZENÍ, zamčený FOR UPDATE
     * (adversariální review 2026-07, KRITICKÝ nález): draft dohody sestavuje zbytek
     * jen jednorázově; mezi sestavením a potvrzením mohla přijít jiná úhrada (banka,
     * jiný souběžný zápočet) — proto se tu zbytek NAČÍTÁ ZNOVU, s řádkovým zámkem,
     * který zároveň serializuje dvě souběžné confirm() na TÝŽ doklad (druhá počká
     * na commit první a uvidí už sníženou hodnotu). NULL = doklad nenalezen.
     */
    public function lockInvoiceRemaining(int $supplierId, int $docId): ?float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT amount_to_pay, paid_total FROM invoices WHERE id = ? AND supplier_id = ? FOR UPDATE'
        );
        $stmt->execute([$docId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return round((float) $row['amount_to_pay'] - (float) $row['paid_total'], 2);
    }

    /**
     * Aktuální zbytek přijaté faktury (CZK) k okamžiku POTVRZENÍ, s řádkovým zámkem
     * (stejný důvod jako {@see lockInvoiceRemaining}) — lock se drží po zbytek
     * transakce, takže i následný SUM() ze subselectů (payment_matches, potvrzené
     * zápočty) v {@see purchaseRemaining} je vůči souběžné confirm() serializovaný.
     * NULL = doklad nenalezen.
     */
    public function lockPurchaseRemaining(int $supplierId, int $docId, int $excludeAgreementId): ?float
    {
        $lock = $this->db->pdo()->prepare(
            'SELECT id FROM purchase_invoices WHERE id = ? AND supplier_id = ? FOR UPDATE'
        );
        $lock->execute([$docId, $supplierId]);
        if ($lock->fetch(PDO::FETCH_ASSOC) === false) {
            return null;
        }
        return $this->purchaseRemaining($supplierId, $docId, $excludeAgreementId);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function castAgreement(array $row): array
    {
        $row['id']               = (int) $row['id'];
        $row['supplier_id']      = (int) $row['supplier_id'];
        $row['partner_id']       = (int) $row['partner_id'];
        $row['total_amount']     = round((float) $row['total_amount'], 2);
        $row['journal_entry_id'] = $row['journal_entry_id'] !== null ? (int) $row['journal_entry_id'] : null;
        $row['created_by']       = $row['created_by'] !== null ? (int) $row['created_by'] : null;
        return $row;
    }
}
