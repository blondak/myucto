<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Měkká vazba účetního zápisu na existující doklad (`journal_entry_document_links`).
 *
 * ČISTĚ INFORMATIVNÍ protějšek k `journal_entries.source_type/source_id`. Ta dvojice
 * znamená „tenhle zápis JE zaúčtování dokladu" a drží na ní idempotence
 * (UNIQUE z migrace 1007), zámek `booked_at` i mazání zápisu s rollbackem dokladu —
 * ruční zápis do ní proto sáhnout NESMÍ. Tahle tabulka řeší druhou potřebu: „ten
 * interní doklad SOUVISÍ s touhle fakturou" (dohad, kurzový rozdíl, přeúčtování,
 * oprava), aniž by se čemukoli tvářil jako její zaúčtování.
 *
 * BEZPEČNOST: `doc_id` nemá FK (je polymorfní přes čtyři tabulky), takže tenanta
 * na straně dokladu ověřuje VÝHRADNĚ {@see documentExists()} — bez něj by šlo přes
 * doc_id navázat cizí doklad a přes panel „Souvisí" si z něj přečíst popisná data
 * (IDOR). Stranu zápisu drží složené FK (supplier_id, entry_id) z migrace 1514.
 */
final class JournalEntryDocumentLinkRepository
{
    /** Typy dokladu, na které lze zápis navázat — musí odpovídat ENUM v migraci 1514. */
    public const DOC_TYPES = ['invoice', 'purchase_invoice', 'cash', 'bank'];

    /** Strop vazeb na zápis — pojistka proti zaplevelení panelu „Souvisí". */
    public const MAX_LINKS_PER_ENTRY = 50;

    /** Strop délky poznámky (sloupec je VARCHAR(255)). */
    public const MAX_NOTE_LENGTH = 255;

    /** Kolik kandidátů vrátit na jeden typ dokladu v našeptávači. */
    private const CANDIDATES_PER_TYPE = 8;

    public function __construct(private readonly Connection $db) {}

    /**
     * Vazby jednoho zápisu (bez popisných dat dokladu — ta doplní JournalLinkService).
     *
     * @return list<array<string,mixed>>
     */
    public function listForEntry(int $entryId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.entry_id, l.doc_type, l.doc_id, l.note, l.created_by, l.created_at,
                    u.name AS created_by_name
               FROM journal_entry_document_links l
               LEFT JOIN users u ON u.id = l.created_by
              WHERE l.entry_id = ? AND l.supplier_id = ?
              ORDER BY l.id'
        );
        $stmt->execute([$entryId, $supplierId]);

        return array_map(static fn (array $r): array => [
            'id'              => (int) $r['id'],
            'entry_id'        => (int) $r['entry_id'],
            'doc_type'        => (string) $r['doc_type'],
            'doc_id'          => (int) $r['doc_id'],
            'note'            => $r['note'] !== null ? (string) $r['note'] : null,
            'created_by'      => $r['created_by'] !== null ? (int) $r['created_by'] : null,
            'created_by_name' => $r['created_by_name'] !== null ? (string) $r['created_by_name'] : null,
            'created_at'      => (string) $r['created_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, int $entryId, int $supplierId): ?array
    {
        foreach ($this->listForEntry($entryId, $supplierId) as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    public function countForEntry(int $entryId, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM journal_entry_document_links WHERE entry_id = ? AND supplier_id = ?'
        );
        $stmt->execute([$entryId, $supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Založí vazbu. Idempotentní: opakované navázání téhož dokladu vrátí PŮVODNÍ
     * řádek (UNIQUE uq_jedl_entry_doc), ne chybu — dvojklik ani retry nesmí skončit
     * hláškou tam, kde výsledek přesně odpovídá tomu, co uživatel chtěl.
     */
    public function add(int $entryId, int $supplierId, string $docType, int $docId, ?string $note, ?int $userId): int
    {
        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO journal_entry_document_links (supplier_id, entry_id, doc_type, doc_id, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), note = COALESCE(VALUES(note), note)'
        );
        $stmt->execute([$supplierId, $entryId, $docType, $docId, $note, $userId]);

        return (int) $pdo->lastInsertId();
    }

    /** Tvrdé smazání — vazba není účetní zápis, §35 neměnnost se jí netýká. */
    public function delete(int $id, int $entryId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM journal_entry_document_links WHERE id = ? AND entry_id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $entryId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Existuje doklad daného typu u TOHOTO tenanta? Jediná brána proti navázání
     * cizího dokladu — `doc_id` FK nemá.
     *
     * `bank_transactions` nemá supplier_id, tenant se u nich vynucuje JOINem na
     * `bank_statements` (shodně s JournalLinkService).
     */
    public function documentExists(int $supplierId, string $docType, int $docId): bool
    {
        if ($docId <= 0 || !in_array($docType, self::DOC_TYPES, true)) {
            return false;
        }
        // Tabulka se vybírá z UZAVŘENÉ množiny výše; do SQL se nikdy nedostane vstup.
        $sql = match ($docType) {
            'invoice'          => 'SELECT 1 FROM invoices WHERE id = ? AND supplier_id = ?',
            'purchase_invoice' => 'SELECT 1 FROM purchase_invoices WHERE id = ? AND supplier_id = ?',
            'cash'             => 'SELECT 1 FROM cash_documents WHERE id = ? AND supplier_id = ?',
            'bank'             => 'SELECT 1 FROM bank_transactions t
                                     JOIN bank_statements s ON s.id = t.statement_id
                                    WHERE t.id = ? AND s.supplier_id = ?',
        };
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$docId, $supplierId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Našeptávač dokladů pro navázání — číslo dokladu / VS nebo název protistrany,
     * vždy v rámci tenanta.
     *
     * @param  list<string> $types podmnožina DOC_TYPES; prázdné = všechny
     * @return list<array<string,mixed>>
     */
    public function searchCandidates(int $supplierId, string $q, array $types = []): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $types = $types === [] ? self::DOC_TYPES : array_values(array_intersect(self::DOC_TYPES, $types));
        $like  = '%' . $q . '%';
        $out   = [];

        if (in_array('invoice', $types, true)) {
            foreach ($this->rows(
                "SELECT i.id, i.varsymbol, i.issue_date, i.total_with_vat,
                        c.company_name, c.first_name, c.last_name,
                        UPPER(COALESCE(cur.code, 'CZK')) AS currency
                   FROM invoices i
                   LEFT JOIN clients c ON c.id = i.client_id AND c.supplier_id = i.supplier_id
                   LEFT JOIN currencies cur ON cur.id = i.currency_id
                  WHERE i.supplier_id = ?
                    AND (i.varsymbol LIKE ? OR c.company_name LIKE ?)
                  ORDER BY i.issue_date DESC, i.id DESC
                  LIMIT " . self::CANDIDATES_PER_TYPE,
                [$supplierId, $like, $like]
            ) as $r) {
                $out[] = [
                    'doc_type' => 'invoice',
                    'doc_id'   => (int) $r['id'],
                    'label'    => (string) ($r['varsymbol'] ?: ('#' . $r['id'])),
                    'sublabel' => $this->partner($r),
                    'date'     => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
                    'amount'   => $r['total_with_vat'] !== null ? (float) $r['total_with_vat'] : null,
                    'currency' => (string) $r['currency'],
                ];
            }
        }

        if (in_array('purchase_invoice', $types, true)) {
            foreach ($this->rows(
                "SELECT p.id, p.varsymbol, p.vendor_invoice_number, p.issue_date, p.total_with_vat,
                        c.company_name, c.first_name, c.last_name,
                        UPPER(COALESCE(cur.code, 'CZK')) AS currency
                   FROM purchase_invoices p
                   LEFT JOIN clients c ON c.id = p.vendor_id AND c.supplier_id = p.supplier_id
                   LEFT JOIN currencies cur ON cur.id = p.currency_id
                  WHERE p.supplier_id = ?
                    AND (p.varsymbol LIKE ? OR p.vendor_invoice_number LIKE ? OR c.company_name LIKE ?)
                  ORDER BY p.issue_date DESC, p.id DESC
                  LIMIT " . self::CANDIDATES_PER_TYPE,
                [$supplierId, $like, $like, $like]
            ) as $r) {
                $out[] = [
                    'doc_type' => 'purchase_invoice',
                    'doc_id'   => (int) $r['id'],
                    'label'    => (string) ($r['vendor_invoice_number'] ?: $r['varsymbol'] ?: ('#' . $r['id'])),
                    'sublabel' => $this->partner($r),
                    'date'     => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
                    'amount'   => $r['total_with_vat'] !== null ? (float) $r['total_with_vat'] : null,
                    'currency' => (string) $r['currency'],
                ];
            }
        }

        if (in_array('cash', $types, true)) {
            foreach ($this->rows(
                "SELECT d.id, d.doc_number, d.issue_date, d.total_amount, d.partner_name, d.description,
                        UPPER(COALESCE(d.currency_code, 'CZK')) AS currency
                   FROM cash_documents d
                  WHERE d.supplier_id = ?
                    AND (d.doc_number LIKE ? OR d.partner_name LIKE ? OR d.description LIKE ?)
                  ORDER BY d.issue_date DESC, d.id DESC
                  LIMIT " . self::CANDIDATES_PER_TYPE,
                [$supplierId, $like, $like, $like]
            ) as $r) {
                $out[] = [
                    'doc_type' => 'cash',
                    'doc_id'   => (int) $r['id'],
                    'label'    => (string) ($r['doc_number'] ?: ('#' . $r['id'])),
                    'sublabel' => $r['partner_name'] !== null && $r['partner_name'] !== ''
                        ? (string) $r['partner_name']
                        : ($r['description'] !== null ? (string) $r['description'] : null),
                    'date'     => $r['issue_date'] !== null ? (string) $r['issue_date'] : null,
                    'amount'   => $r['total_amount'] !== null ? (float) $r['total_amount'] : null,
                    'currency' => (string) $r['currency'],
                ];
            }
        }

        if (in_array('bank', $types, true)) {
            foreach ($this->rows(
                "SELECT t.id, t.posted_at, t.amount, t.counterparty_name, t.variable_symbol, t.bank_ref,
                        UPPER(COALESCE(t.currency, s.currency, 'CZK')) AS currency
                   FROM bank_transactions t
                   JOIN bank_statements s ON s.id = t.statement_id
                  WHERE s.supplier_id = ?
                    AND (t.variable_symbol LIKE ? OR t.counterparty_name LIKE ? OR t.bank_ref LIKE ?)
                  ORDER BY t.posted_at DESC, t.id DESC
                  LIMIT " . self::CANDIDATES_PER_TYPE,
                [$supplierId, $like, $like, $like]
            ) as $r) {
                $out[] = [
                    'doc_type' => 'bank',
                    'doc_id'   => (int) $r['id'],
                    'label'    => (string) ($r['bank_ref'] ?: $r['variable_symbol'] ?: ('#' . $r['id'])),
                    'sublabel' => $r['counterparty_name'] !== null ? (string) $r['counterparty_name'] : null,
                    'date'     => $r['posted_at'] !== null ? (string) $r['posted_at'] : null,
                    'amount'   => $r['amount'] !== null ? (float) $r['amount'] : null,
                    'currency' => (string) $r['currency'],
                ];
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $r */
    private function partner(array $r): ?string
    {
        $company = trim((string) ($r['company_name'] ?? ''));
        if ($company !== '') {
            return $company;
        }
        $person = trim(((string) ($r['first_name'] ?? '')) . ' ' . ((string) ($r['last_name'] ?? '')));
        return $person !== '' ? $person : null;
    }

    /**
     * @param  list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
