<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Cash;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\Reports\AccountStatementService;
use MyInvoice\Service\Pdf\AccountStatementPdfRenderer;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Pokladní kniha (mini-epic POKLADNA #14, O8). Podvojné účetnictví: reuse
 * {@see AccountStatementService} (opening + running balance z F2) nad analytikou
 * registru + přiznaný join na cash_documents (partner/typ/účel); ruční zápisy na
 * 211R zůstávají s NULL. PDF export doplní PDF stage.
 *
 * Daňová evidence (Epic DE §6): pokladna nemá journal ani analytiku v osnově,
 * takže se kniha staví přímo nad cash_documents dané pokladny (buildTaxEvidenceBook).
 */
final class CashBookAction
{
    use AccountingActionSupport;

    private const MAX_PER_PAGE = 500;

    /** PDF tiskne celý rozsah bez stránkování (obraty a KS jsou za celé období). */
    private const PDF_MAX_ROWS = 100000;

    public function __construct(
        private readonly CashRegisterService $registers,
        private readonly AccountStatementService $statement,
        private readonly Connection $db,
        private readonly AccountStatementPdfRenderer $pdf,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $registerId = (int) $args['id'];
        $q = $request->getQueryParams();

        $from = $this->dateParam($q, 'from', date('Y') . '-01-01');
        $to = $this->dateParam($q, 'to', date('Y-m-d'));
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($q['per_page'] ?? 50)));

        try {
            $register = $this->registers->get($supplierId, $registerId, $to);

            if ($this->isTaxEvidence($supplierId)) {
                return Json::ok($response, $this->buildTaxEvidenceBook($supplierId, $register, $from, $to, $page, $perPage));
            }

            $accountId = $register['account_id'] ?? null;
            if ($accountId === null) {
                throw new CashException('account_invalid', 'Analytika pokladny není v osnově firmy.');
            }

            $stmt = $this->statement->build($supplierId, (int) $accountId, $from, $to, $page, $perPage);
            $docMap = $this->cashDocumentMap($supplierId, $stmt['items']);

            $items = [];
            // R7 banner: zápor kdekoli v OKNĚ (ne jen na načtené stránce).
            $negative = $stmt['opening_balance'] < 0
                || round($stmt['opening_balance'] + $this->minRunningDelta($supplierId, (int) $accountId, $from, $to), 2) < 0;
            foreach ($stmt['items'] as $l) {
                $isCash = (string) $l['source_type'] === 'cash' && $l['source_id'] !== null;
                $doc = $isCash ? ($docMap[(int) $l['source_id']] ?? null) : null;
                $items[] = [
                    'date'         => (string) $l['entry_date'],
                    'document_no'  => $l['document_no'],
                    'doc_type'     => $doc['doc_type'] ?? null,
                    'purpose'      => $doc['purpose'] ?? null,
                    'tax_date'     => $doc['tax_date'] ?? null,
                    'partner_name' => $doc['partner_name'] ?? null,
                    'description'  => $l['description'],
                    'income'       => $l['side'] === 'debit' ? (float) $l['amount'] : null,
                    'expense'      => $l['side'] === 'credit' ? (float) $l['amount'] : null,
                    'balance'      => (float) $l['balance'],
                    'document_id'  => $doc !== null ? (int) $l['source_id'] : null,
                    'entry_id'     => (int) $l['entry_id'],
                ];
            }

            return Json::ok($response, [
                'register'         => $register,
                'opening_balance'  => $stmt['opening_balance'],
                'items'            => $items,
                'income_total'     => $stmt['turnover_md'],
                'expense_total'    => $stmt['turnover_d'],
                'closing_balance'  => $stmt['closing_balance'],
                'balance_negative' => $negative,
                'total'            => $stmt['total'],
                'page'             => $stmt['page'],
                'per_page'         => $stmt['per_page'],
            ]);
        } catch (\Throwable $e) {
            if ($e instanceof CashException) {
                return Json::error($response, 'cash.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus);
            }
            return $this->mapPostingError($response, $e);
        }
    }

    /** PDF pokladní knihy (§5.4) — reuse AccountStatementPdfRenderer, hlavička registru. */
    public function pdf(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $registerId = (int) $args['id'];
        $q = $request->getQueryParams();

        $from = $this->dateParam($q, 'from', date('Y') . '-01-01');
        $to = $this->dateParam($q, 'to', date('Y-m-d'));

        try {
            $register = $this->registers->get($supplierId, $registerId, $to);
            $accountId = $register['account_id'] ?? null;
            if ($accountId === null) {
                throw new CashException('account_invalid', 'Analytika pokladny není v osnově firmy.');
            }

            $data = $this->statement->build($supplierId, (int) $accountId, $from, $to, 1, self::PDF_MAX_ROWS);
            // Hlavička registru místo obecného opisu účtu.
            $data['account']['name'] = (string) $register['name'];
            $data['from'] = $from;
            $data['to'] = $to;

            $bytes = $this->pdf->render($data);
            $filename = str_replace(['/', '\\', ' '], '-',
                'pokladni-kniha-' . (string) $register['account_code'] . '-' . $from . '-' . $to . '.pdf');

            $response->getBody()->write($bytes);
            return $response
                ->withHeader('Content-Type', 'application/pdf')
                ->withHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
                ->withHeader('Content-Length', (string) strlen($bytes))
                ->withHeader('Cache-Control', 'private, no-store');
        } catch (\Throwable $e) {
            if ($e instanceof CashException) {
                return Json::error($response, 'cash.error.' . $e->errorCode, $e->getMessage(), $e->httpStatus);
            }
            return $this->mapPostingError($response, $e);
        }
    }

    /** Validované datum z query (i pojistka proti smetí ve filename Content-Disposition). */
    private function dateParam(array $q, string $key, string $default): string
    {
        $v = (string) ($q[$key] ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : $default;
    }

    private function isTaxEvidence(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() === 'tax_evidence';
    }

    /**
     * DE větev (§6): pokladní kniha přímo nad cash_documents dané pokladny — v daňové
     * evidenci pokladna nemá journal ani analytiku v osnově (R6), takže AccountStatementService
     * (ledger) nelze použít. Kasová báze konzistentní s
     * {@see CashRegisterService::documentsSignedTotal} — signed součet posted dokladů.
     * Běžící zůstatek se počítá v PHP nad chronologicky seřazenými doklady v okně
     * [from, to]; income_total/expense_total/closing_balance jsou za CELÉ okno
     * (stránkování mění jen `items`, stejně jako u AccountStatementService::build).
     *
     * @param array<string,mixed> $register
     * @return array<string,mixed>
     */
    private function buildTaxEvidenceBook(int $supplierId, array $register, string $from, string $to, int $page, int $perPage): array
    {
        $registerId = (int) $register['id'];
        $dayBeforeFrom = date('Y-m-d', strtotime($from . ' -1 day'));
        $opening = $this->registers->documentsSignedTotal($supplierId, $registerId, $dayBeforeFrom);

        $stmt = $this->db->pdo()->prepare(
            "SELECT id, doc_type, doc_number, issue_date, tax_date, description, partner_name, purpose, total_amount
               FROM cash_documents
              WHERE supplier_id = ? AND register_id = ? AND status = 'posted' AND issue_date BETWEEN ? AND ?
              ORDER BY issue_date, id"
        );
        $stmt->execute([$supplierId, $registerId, $from, $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $running = $opening;
        $incomeTotal = 0.0;
        $expenseTotal = 0.0;
        $negative = $opening < 0;
        $allItems = [];
        foreach ($rows as $r) {
            $amount = round((float) $r['total_amount'], 2);
            $isIncome = (string) $r['doc_type'] === 'in';
            $running = round($running + ($isIncome ? $amount : -$amount), 2);
            if ($running < 0) {
                $negative = true;
            }
            if ($isIncome) {
                $incomeTotal = round($incomeTotal + $amount, 2);
            } else {
                $expenseTotal = round($expenseTotal + $amount, 2);
            }
            $allItems[] = [
                'date'         => (string) $r['issue_date'],
                'document_no'  => $r['doc_number'],
                'doc_type'     => (string) $r['doc_type'],
                'purpose'      => (string) $r['purpose'],
                'tax_date'     => $r['tax_date'] !== null ? (string) $r['tax_date'] : null,
                'partner_name' => $r['partner_name'] !== null ? (string) $r['partner_name'] : null,
                'description'  => (string) $r['description'],
                'income'       => $isIncome ? $amount : null,
                'expense'      => !$isIncome ? $amount : null,
                'balance'      => $running,
                'document_id'  => (int) $r['id'],
                'entry_id'     => (int) $r['id'],
            ];
        }

        $total = count($allItems);
        $offset = ($page - 1) * $perPage;

        return [
            'register'         => $register,
            'opening_balance'  => $opening,
            'items'            => array_slice($allItems, $offset, $perPage),
            'income_total'     => $incomeTotal,
            'expense_total'    => $expenseTotal,
            'closing_balance'  => $running,
            'balance_negative' => $negative,
            'total'            => $total,
            'page'             => $page,
            'per_page'         => $perPage,
        ];
    }

    /** MIN kumulativní delty přes CELÉ okno (tvar dle LedgerReportRepository::accountLines). */
    private function minRunningDelta(int $supplierId, int $accountId, string $from, string $to): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(MIN(t.running_delta), 0) FROM (
                SELECT SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END)
                         OVER (ORDER BY e.entry_date, e.id, l.line_no
                               ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running_delta
                  FROM journal_entry_lines l
                  JOIN journal_entries e    ON e.id = l.entry_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                 WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                   AND (l.account_id = ? OR ca.parent_id = ?)
                   AND e.entry_date BETWEEN ? AND ?
            ) t"
        );
        $stmt->execute([$supplierId, $accountId, $accountId, $from, $to]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Mapa cash_documents pro řádky deníku se source_type='cash'.
     *
     * @param list<array<string,mixed>> $items
     * @return array<int,array{doc_type:string, purpose:string, tax_date:?string, partner_name:?string}>
     */
    private function cashDocumentMap(int $supplierId, array $items): array
    {
        $ids = [];
        foreach ($items as $l) {
            if ((string) $l['source_type'] === 'cash' && $l['source_id'] !== null) {
                $ids[(int) $l['source_id']] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $ids = array_keys($ids);
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, doc_type, purpose, tax_date, partner_name FROM cash_documents
              WHERE supplier_id = ? AND id IN ($place)"
        );
        $stmt->execute(array_merge([$supplierId], $ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = [
                'doc_type'     => (string) $r['doc_type'],
                'purpose'      => (string) $r['purpose'],
                'tax_date'     => $r['tax_date'] !== null ? (string) $r['tax_date'] : null,
                'partner_name' => $r['partner_name'] !== null ? (string) $r['partner_name'] : null,
            ];
        }
        return $out;
    }
}
