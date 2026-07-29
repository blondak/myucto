<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingSuggestionRepository;
use PDO;

/**
 * Featura H (REAL_data_followup_UX.md) — jednotná, READ-ONLY fronta „čeká na ruční
 * zaúčtování" napříč zdroji, kde dnes stav mizí beze stopy nebo je rozeseknutý po
 * několika samostatných obrazovkách:
 *
 *  - bank_no_suggestion  — bankovní pohyby, pro které automatika NEVYTVOŘILA vůbec žádný návrh
 *                          (BankPostingService skip `no_rule`/`fx_not_supported` — dosud jediné
 *                          NEVIDITELNÉ položky, audit „43 pohybů, které automatika neumí"),
 *  - purchase_invoice / sales_invoice — doklady bez zaúčtovaného předpisu (booked_at IS NULL),
 *  - document_request    — chybějící doklad vyžádaný od klienta (document_requests, stav requested).
 *
 * Avíza (`bank_transactions.source='email_notice'`) se NEÚČTUJÍ nikdy a proto se do fronty
 * nezahrnují — stejná konvence jako {@see BankPostingSuggestionRepository::paginateUnposted()}
 * (`bt.source = 'statement'`).
 *
 * ČEKAJÍCÍ NÁVRHY KONTACE ZDE ÚMYSLNĚ NEJSOU. Fronta odpovídá na otázku „co ještě není
 * zaúčtované" — návrh ale znamená, že automatika svou práci odvedla a čeká se jen na
 * schválení; doklad sám bývá dávno zaúčtovaný. Míchat obojí do jednoho čísla znamenalo,
 * že „K doúčtování" hlásilo desítky položek, které se dokladů vůbec netýkaly (na ostrých
 * datech 58 zvětralých AI návrhů, viz migrace 1132). Schvalování návrhů má vlastní
 * obrazovku — Automat (`automation-cockpit`), taby „Ke schválení" / „Chybí vstup".
 *
 * Nic v této třídě nezapisuje — je to čistě agregační dotaz nad existujícími stavy.
 */
final class ManualPostingQueueService
{
    private const TYPES = ['bank_no_suggestion', 'purchase_invoice', 'sales_invoice', 'document_request'];

    public function __construct(
        private readonly Connection $db,
        private readonly BankPostingSuggestionRepository $suggestions,
    ) {}

    /**
     * @param array{type?:?string, reason?:?string, page?:int, per_page?:int} $filters
     * @return array{items:list<array<string,mixed>>, total:int, page:int, per_page:int,
     *               counts:array{by_type:array<string,int>, by_reason:array<string,int>}}
     */
    public function queue(int $supplierId, array $filters = []): array
    {
        $all = array_merge(
            $this->bankNoSuggestionItems($supplierId),
            $this->unbookedDocumentItems($supplierId, 'purchase_invoice'),
            $this->unbookedDocumentItems($supplierId, 'sales_invoice'),
            $this->documentRequestItems($supplierId),
        );

        usort($all, static function (array $a, array $b): int {
            $cmp = strcmp((string) $b['date'], (string) $a['date']);
            return $cmp !== 0 ? $cmp : strcmp((string) $b['id'], (string) $a['id']);
        });

        $countsByType = array_fill_keys(self::TYPES, 0);
        $countsByReason = [];
        foreach ($all as $item) {
            $countsByType[$item['type']]++;
            $countsByReason[$item['reason']] = ($countsByReason[$item['reason']] ?? 0) + 1;
        }

        $type = isset($filters['type']) && in_array($filters['type'], self::TYPES, true) ? (string) $filters['type'] : null;
        $reason = isset($filters['reason']) && $filters['reason'] !== '' ? (string) $filters['reason'] : null;
        $filtered = array_values(array_filter($all, static function (array $item) use ($type, $reason): bool {
            if ($type !== null && $item['type'] !== $type) return false;
            if ($reason !== null && $item['reason'] !== $reason) return false;
            return true;
        }));

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($filtered, $offset, $perPage),
            'total' => count($filtered),
            'page' => $page,
            'per_page' => $perPage,
            'counts' => ['by_type' => $countsByType, 'by_reason' => $countsByReason],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function bankNoSuggestionItems(int $supplierId): array
    {
        return array_map(static function (array $r): array {
            $isForeign = $r['currency'] !== 'CZK';
            return [
                'id' => 'bank_no_suggestion:' . $r['id'],
                'type' => 'bank_no_suggestion',
                'date' => substr($r['posted_at'], 0, 10),
                'amount' => $r['amount'],
                'currency' => $r['currency'],
                'counterparty' => $r['counterparty_name'],
                'description' => $r['description'],
                'reason' => $isForeign ? 'fx_not_supported' : 'no_rule',
                'reason_detail' => null,
                'suggested_action' => $isForeign ? 'post_manually_fx' : 'create_rule_or_post_manually',
                'link' => ['route' => 'bank-detail', 'params' => ['id' => $r['statement_id']], 'query' => ['posting_status' => 'unposted']],
                'refs' => [
                    'suggestion_id' => null, 'bank_transaction_id' => $r['id'],
                    'statement_id' => $r['statement_id'], 'purchase_invoice_id' => null,
                    'invoice_id' => null, 'document_request_id' => null,
                ],
            ];
        }, $this->suggestions->unpostedWithoutSuggestion($supplierId));
    }

    /** @return list<array<string,mixed>> */
    private function unbookedDocumentItems(int $supplierId, string $type): array
    {
        $isPurchase = $type === 'purchase_invoice';
        $sql = $isPurchase
            ? "SELECT pi.id, pi.issue_date, pi.total_with_vat, cur.code AS currency, pi.vendor_invoice_number,
                      c.company_name AS counterparty
                 FROM purchase_invoices pi
                 JOIN currencies cur ON cur.id = pi.currency_id
                 JOIN clients c ON c.id = pi.vendor_id
                WHERE pi.supplier_id = ? AND pi.booked_at IS NULL
                  AND pi.status NOT IN ('draft','cancelled') AND pi.document_kind <> 'advance'
                ORDER BY pi.issue_date DESC, pi.id DESC"
            : "SELECT i.id, i.issue_date, i.total_with_vat, cur.code AS currency, i.varsymbol AS vendor_invoice_number,
                      c.company_name AS counterparty
                 FROM invoices i
                 JOIN currencies cur ON cur.id = i.currency_id
                 JOIN clients c ON c.id = i.client_id
                WHERE i.supplier_id = ? AND i.booked_at IS NULL
                  AND i.status NOT IN ('draft','cancelled')
                  AND i.invoice_type IN ('invoice','credit_note','tax_document','penalty')
                ORDER BY i.issue_date DESC, i.id DESC";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        $route = $isPurchase ? 'purchase-invoice-detail' : 'invoice-detail';
        $action = $isPurchase ? 'post_purchase_invoice' : 'post_sales_invoice';
        $sign = $isPurchase ? -1 : 1;
        return array_map(static function (array $r) use ($type, $route, $action, $sign): array {
            return [
                'id' => $type . ':' . $r['id'],
                'type' => $type,
                'date' => (string) $r['issue_date'],
                'amount' => $sign * (float) $r['total_with_vat'],
                'currency' => (string) $r['currency'],
                'counterparty' => $r['counterparty'],
                'description' => $r['vendor_invoice_number'] === null ? null : (string) $r['vendor_invoice_number'],
                'reason' => 'document_not_posted',
                'reason_detail' => null,
                'suggested_action' => $action,
                'link' => ['route' => $route, 'params' => ['id' => (int) $r['id']]],
                'refs' => [
                    'suggestion_id' => null, 'bank_transaction_id' => null, 'statement_id' => null,
                    'purchase_invoice_id' => $type === 'purchase_invoice' ? (int) $r['id'] : null,
                    'invoice_id' => $type === 'sales_invoice' ? (int) $r['id'] : null,
                    'document_request_id' => null,
                ],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    private function documentRequestItems(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT dr.id, dr.description, dr.amount, dr.context_date, dr.deadline, dr.created_at,
                    bt.counterparty_name, bt.currency AS bank_currency, c.company_name AS pi_vendor_name
               FROM document_requests dr
          LEFT JOIN bank_transactions bt ON bt.id = dr.bank_transaction_id
          LEFT JOIN purchase_invoices pi ON pi.id = dr.purchase_invoice_id
          LEFT JOIN clients c ON c.id = pi.vendor_id
              WHERE dr.supplier_id = ? AND dr.status = 'requested'
              ORDER BY COALESCE(dr.context_date, DATE(dr.created_at)) DESC, dr.id DESC"
        );
        $stmt->execute([$supplierId]);
        return array_map(static function (array $r): array {
            return [
                'id' => 'document_request:' . $r['id'],
                'type' => 'document_request',
                'date' => (string) ($r['context_date'] ?? substr((string) $r['created_at'], 0, 10)),
                'amount' => $r['amount'] === null ? null : (float) $r['amount'],
                'currency' => $r['bank_currency'] === null ? null : (string) $r['bank_currency'],
                'counterparty' => $r['counterparty_name'] ?? $r['pi_vendor_name'],
                'description' => (string) $r['description'],
                'reason' => 'document_missing',
                'reason_detail' => (string) $r['description'],
                'suggested_action' => 'resolve_document_request',
                'link' => ['route' => 'document-requests', 'params' => [], 'query' => []],
                'deadline' => $r['deadline'] === null ? null : (string) $r['deadline'],
                'refs' => [
                    'suggestion_id' => null, 'bank_transaction_id' => null, 'statement_id' => null,
                    'purchase_invoice_id' => null, 'invoice_id' => null, 'document_request_id' => (int) $r['id'],
                ],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
