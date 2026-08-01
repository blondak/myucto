<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Cash;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CashDocumentRepository;
use MyInvoice\Repository\CashRegisterRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\InvoiceMath;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use MyInvoice\Service\Currency\CnbRateDeviationChecker;
use MyInvoice\Service\Vat\VatStatusService;
use DateTimeImmutable;
use PDO;

/**
 * Pokladní doklady PPD/VPD (mini-epic POKLADNA #14). Vytvoření + zaúčtování
 * v JEDNÉ transakci (O2), zaúčtování VÝHRADNĚ přes {@see PostingService}
 * (source_type='cash', idempotence na ('cash', doc.id)). Buildery per účel
 * (§3.4), přesná rovnost Σ(base+vat)==total (O6), storno = protizápis + úklid
 * invoice_payments / PF statusu (§3.6). Chyby jako {@see CashException}.
 */
final class CashDocumentService
{
    /** @var array<string,list<string>> účel → povolené směry (matice §3.5). */
    private const PURPOSE_MATRIX = [
        'sale'             => ['in'],
        'purchase'         => ['out'],
        'invoice_payment'  => ['in'],
        'purchase_payment' => ['out'],
        'transfer'         => ['in', 'out'],
        'other'            => ['in', 'out'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly DocumentSeriesService $series,
        private readonly PostingRuleRepository $rules,
        private readonly InvoicePaymentService $invoicePayments,
        private readonly FinalFromProformaCreator $finalCreator,
        private readonly PaymentTaxDocumentCreator $taxDocCreator,
        private readonly PurchaseInvoiceRepository $purchaseInvoices,
        private readonly InvoiceRepository $invoices,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly TaxConstantsRepository $taxConstants,
        private readonly LedgerReportRepository $ledger,
        private readonly CashRegisterRepository $registers,
        private readonly CashDocumentRepository $documents,
        private readonly CashRegisterService $cashRegisters,
        private readonly CashRulePresets $rulePresets,
        private readonly CnbExchangeRateClient $cnb,
        private readonly CnbRateDeviationChecker $rateChecker,
        private readonly DocumentLockService $documentLocks,
        private readonly VatStatusService $vatStatus,
    ) {}

    /**
     * Vytvoří doklad; `data['post'] ?? true` → create + post v jedné transakci (O2).
     *
     * @return array{id:int, doc_number:?string, journal_entry_id:?int, status:string, warnings:list<string>}
     */
    public function create(int $supplierId, array $data, ?int $userId): array
    {
        $doc = $this->normalize($data);
        $register = $this->requireActiveRegister($supplierId, $doc['register_id']);
        $doc = $this->resolveCurrency($doc, $register);
        $this->validateDoc($supplierId, $doc, $doc['vat_lines'], $register);

        $post = array_key_exists('post', $data) ? (bool) $data['post'] : true;

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $docId = $this->documents->insert($supplierId, $doc, $userId);
            $this->documents->replaceVatLines($docId, $doc['vat_mode'] === 'vat' ? $doc['vat_lines'] : []);

            if ($post) {
                $result = $this->doPost($supplierId, $docId, $userId);
            } else {
                $result = ['doc_number' => null, 'journal_entry_id' => null, 'status' => 'draft', 'warnings' => []];
            }
            if ($ownTx) {
                $pdo->commit();
            }
            return array_merge(['id' => $docId], $result);
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Úprava draftu (jen status=draft). */
    public function updateDraft(int $supplierId, int $id, array $data): void
    {
        $existing = $this->documents->find($supplierId, $id);
        if ($existing === null) {
            throw new CashException('validation', 'Pokladní doklad nenalezen.', 404);
        }
        if ($existing['status'] !== 'draft') {
            throw new CashException('doc_not_draft', 'Upravovat lze jen rozpracovaný (draft) doklad.');
        }
        $doc = $this->normalize($data);
        $register = $this->requireActiveRegister($supplierId, $doc['register_id']);
        $doc = $this->resolveCurrency($doc, $register);
        $this->validateDoc($supplierId, $doc, $doc['vat_lines'], $register);

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $this->documents->updateHeader($supplierId, $id, $doc);
            $this->documents->replaceVatLines($id, $doc['vat_mode'] === 'vat' ? $doc['vat_lines'] : []);
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

    public function deleteDraft(int $supplierId, int $id): void
    {
        $existing = $this->documents->find($supplierId, $id);
        if ($existing === null) {
            throw new CashException('validation', 'Pokladní doklad nenalezen.', 404);
        }
        if ($existing['status'] !== 'draft') {
            throw new CashException('doc_not_draft', 'Smazat lze jen rozpracovaný (draft) doklad.');
        }
        $this->documents->deleteDraft($supplierId, $id);
    }

    /**
     * TVRDÉ smazání dokladu v jakémkoli stavu — na rozdíl od storna (které nechává
     * doklad i protizápis v evidenci) zmizí doklad i jeho účetní zápisy beze stopy.
     * Použitelné jen v otevřeném a neuzamčeném období; audit stopu drží activity log.
     *
     * @return array{deleted_entry_ids:list<int>}
     */
    public function deleteDocument(int $supplierId, int $id): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $doc = $this->documents->lockForPost($supplierId, $id);
            if ($doc === null) {
                throw new CashException('validation', 'Pokladní doklad nenalezen.', 404);
            }
            $this->assertTaxDateUnlocked($supplierId, (string) ($doc['tax_date'] ?? $doc['issue_date']));

            // Vazba na fakturu: úhradu zrušit dřív, než zmizí sám doklad.
            if ($doc['invoice_payment_id'] !== null) {
                $this->invoicePayments->deletePayment((int) $doc['invoice_payment_id']);
                $this->documents->setInvoicePaymentId($id, null);
            }
            if ($doc['purpose'] === 'purchase_payment' && $doc['purchase_invoice_id'] !== null) {
                // Stejné pravidlo jako u storna: PF zpět do stavu před úhradou dle
                // tvaru dokladu (zaúčtovaný → 'booked', journal-free DE → 'received').
                $this->purchaseInvoices->setStatus(
                    (int) $doc['purchase_invoice_id'],
                    $doc['journal_entry_id'] !== null ? 'booked' : 'received',
                    $supplierId,
                    null,
                );
            }

            // Protizápis mazat první — jeho reversed_by ukazuje na původní zápis.
            $entryIds = [];
            foreach (['reversal_entry_id', 'journal_entry_id'] as $col) {
                if ($doc[$col] === null) {
                    continue;
                }
                $entryId = (int) $doc[$col];
                $this->assertEntryPeriodOpen($supplierId, $entryId);
                $this->documents->deleteJournalEntry($supplierId, $entryId);
                $entryIds[] = $entryId;
            }

            $this->documents->deleteDocument($supplierId, $id);

            if ($ownTx) {
                $pdo->commit();
            }
            return ['deleted_entry_ids' => $entryIds];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Zápis smazat jen v otevřeném období — zavřené/uzamčené se nesmí měnit. */
    private function assertEntryPeriodOpen(int $supplierId, int $entryId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.status
               FROM journal_entries je
               JOIN accounting_periods p ON p.id = je.period_id AND p.supplier_id = je.supplier_id
              WHERE je.id = ? AND je.supplier_id = ?'
        );
        $stmt->execute([$entryId, $supplierId]);
        $status = $stmt->fetchColumn();
        if ($status !== false && (string) $status !== 'open') {
            throw new CashException(
                'period_not_open',
                'Účetní zápis dokladu je v období „' . $status . '“ — smazat lze jen doklad v otevřeném období.',
                409,
            );
        }
    }

    /** Detail dokladu vč. vat_lines, registru a čísel vázaných faktur. */
    public function get(int $supplierId, int $id): array
    {
        $doc = $this->documents->find($supplierId, $id);
        if ($doc === null) {
            throw new CashException('validation', 'Pokladní doklad nenalezen.', 404);
        }
        $doc['vat_lines'] = $this->documents->vatLinesFor($id);
        $register = $this->registers->find($supplierId, (int) $doc['register_id']);
        $doc['register'] = $register !== null
            ? ['id' => (int) $register['id'], 'name' => (string) $register['name'], 'account_code' => (string) $register['account_code']]
            : null;
        $doc['invoice_number'] = $doc['invoice_id'] !== null ? $this->invoiceNumber($supplierId, (int) $doc['invoice_id']) : null;
        $doc['purchase_invoice_number'] = $doc['purchase_invoice_id'] !== null
            ? $this->purchaseNumber($supplierId, (int) $doc['purchase_invoice_id']) : null;
        return $doc;
    }

    /**
     * @return array{items:list<array<string,mixed>>, total:int, page:int, per_page:int}
     */
    public function listDocuments(int $supplierId, array $filters, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $res = $this->documents->listDocuments($supplierId, $filters, $perPage, ($page - 1) * $perPage);
        return [
            'items'    => $res['items'],
            'total'    => $res['total'],
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Zaúčtuje draft (samostatná /post cesta pro budoucí draft UI).
     * V daňové evidenci (DE §6) je `journal_entry_id` null (journal-free post path).
     *
     * @return array{doc_number:string, journal_entry_id:?int, warnings:list<string>}
     */
    public function post(int $supplierId, int $id, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $result = $this->doPost($supplierId, $id, $userId);
            if ($ownTx) {
                $pdo->commit();
            }
            return [
                'doc_number'       => (string) $result['doc_number'],
                'journal_entry_id' => $result['journal_entry_id'] !== null ? (int) $result['journal_entry_id'] : null,
                'warnings'         => $result['warnings'],
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Storno zaúčtovaného dokladu (protizápis + úklid vazeb). `meta.reason` povinný.
     * V daňové evidenci (DE §6) je storno bez protizápisu → `reversal_entry_id` null.
     *
     * @return array{reversal_entry_id:?int}
     */
    public function reverse(int $supplierId, int $id, array $meta, ?int $userId): array
    {
        $reason = trim((string) ($meta['reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            throw new CashException('reason_required', 'Důvod storna je povinný (min. 3 znaky).');
        }
        $entryDate = isset($meta['entry_date']) && $meta['entry_date'] !== null && $meta['entry_date'] !== ''
            ? (string) $meta['entry_date'] : null;

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $doc = $this->documents->lockForPost($supplierId, $id);
            if ($doc === null) {
                throw new CashException('validation', 'Pokladní doklad nenalezen.', 404);
            }

            // Storno se řídí ULOŽENÝM tvarem dokladu (journal_entry_id), NE aktuálním
            // účetním režimem firmy: accounting_mode je přepínatelný kdykoli (SettingsAction),
            // takže doklad zaúčtovaný v podvojném účetnictví (journal_entry_id vyplněno) musí
            // vždy dostat protizápis — i po přepnutí na daňovou evidenci — a naopak journal-free
            // doklad (DE §6) se vždy stornuje bez posting enginu. Draft má journal_entry_id NULL,
            // takže gate na status='posted' zůstává v obou režimech správný.
            if ($doc['status'] !== 'posted') {
                throw new CashException('doc_not_posted', 'Stornovat lze jen zaúčtovaný doklad.');
            }
            $this->assertTaxDateUnlocked($supplierId, (string) ($doc['tax_date'] ?? $doc['issue_date']));
            $hasJournal = $doc['journal_entry_id'] !== null;

            // Protizápis jen když byl doklad zaúčtován do deníku; jinak storno bez posting enginu.
            $reversalId = $hasJournal
                ? $this->posting->reverse($supplierId, (int) $doc['journal_entry_id'], [
                    'entry_date'  => $entryDate ?? date('Y-m-d'),
                    'description' => 'Storno ' . $doc['doc_number'] . ': ' . $reason,
                    'user_id'     => $userId,
                    'posted_by'   => $userId,
                ])
                : null;

            if ($doc['invoice_payment_id'] !== null) {
                $this->invoicePayments->deletePayment((int) $doc['invoice_payment_id']);
                $this->documents->setInvoicePaymentId($id, null);
            }
            if ($doc['purpose'] === 'purchase_payment' && $doc['purchase_invoice_id'] !== null) {
                // PF vrátit do stavu PŘED úhradou dle tvaru dokladu: zaúčtovaný (journal) byl
                // 'booked', journal-free (daňová evidence, PF se neúčtuje) byl 'received'.
                $this->purchaseInvoices->setStatus(
                    (int) $doc['purchase_invoice_id'],
                    $hasJournal ? 'booked' : 'received',
                    $supplierId,
                    null,
                );
            }
            if ($reversalId === null) {
                $this->documents->markReversedNoJournal($id);
            } else {
                $this->documents->markReversed($id, $reversalId);
            }

            if ($ownTx) {
                $pdo->commit();
            }
            return ['reversal_entry_id' => $reversalId];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Doúčtuje HISTORICKÝ pokladní doklad zaúčtovaný v éře daňové evidence
     * (status='posted', journal_entry_id NULL) do deníku — pro CLI backfill po
     * přepnutí firmy na podvojné účetnictví (audit 2026-07 G5). Používá STEJNOU
     * cestu jako běžné zaúčtování (buildLines() + PostingService), ale bez
     * side-effectů (úhrady faktur) — ty proběhly už při původním zaúčtování
     * dokladu v daňové evidenci. Idempotentní: doklad s journal_entry_id už
     * vyplněným vrací beze změny (already=true).
     *
     * @return array{journal_entry_id:int, already:bool}
     */
    public function backfillJournal(int $supplierId, int $id): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $result = $this->doBackfillJournal($supplierId, $id);
            if ($ownTx) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Čisté sestavení účetních řádků pro doúčtování historického dokladu
     * (dry-run náhled backfillu, žádný zápis) — stejná logika (buildLines())
     * jako {@see doBackfillJournal()}.
     *
     * @return list<array{account_code:string, side:string, amount:float}>
     */
    public function previewBackfillLines(int $supplierId, int $id): array
    {
        $doc = $this->documents->find($supplierId, $id);
        if ($doc === null) {
            throw new CashException('validation', 'Pokladní doklad nenalezen.', 404);
        }
        $register = $this->registers->find($supplierId, (int) $doc['register_id']);
        if ($register === null) {
            throw new CashException('register_not_found', 'Pokladna nenalezena.', 404);
        }
        $doc['vat_lines'] = $this->documents->vatLinesFor($id);
        $lines = $this->buildLines($supplierId, $doc, (string) $register['account_code']);
        return $this->attachCashFx($lines, $doc, (string) $register['account_code']);
    }

    /**
     * Našeptávač nezaplacených FV/PF (jen CZK) pro úhradu hotově (C5a).
     *
     * @return list<array<string,mixed>>
     */
    public function searchUnpaid(int $supplierId, string $kind, string $q, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $like = '%' . addcslashes(trim($q), '%_\\') . '%';
        $pdo = $this->db->pdo();

        if ($kind === 'invoice') {
            $stmt = $pdo->prepare(
                "SELECT i.id, i.varsymbol AS number, i.issue_date, i.amount_to_pay, i.paid_total,
                        cur.code AS currency, c.company_name AS partner_name
                   FROM invoices i
                   JOIN currencies cur ON cur.id = i.currency_id
                   JOIN clients c ON c.id = i.client_id
                  WHERE i.supplier_id = ?
                    AND i.status NOT IN ('draft','cancelled')
                    AND i.invoice_type <> 'proforma'
                    AND cur.code = 'CZK'
                    AND (i.amount_to_pay - i.paid_total) > 0.005
                    AND (i.varsymbol LIKE ? OR c.company_name LIKE ?)
                  ORDER BY i.issue_date DESC, i.id DESC
                  LIMIT " . $limit
            );
            $stmt->execute([$supplierId, $like, $like]);
            return array_map(static function (array $r): array {
                $total = (float) $r['amount_to_pay'];
                $paid = (float) $r['paid_total'];
                return [
                    'id'            => (int) $r['id'],
                    'kind'          => 'invoice',
                    'number'        => (string) ($r['number'] ?? ''),
                    'partner_name'  => (string) ($r['partner_name'] ?? ''),
                    'total'         => round($total, 2),
                    'paid'          => round($paid, 2),
                    'remaining'     => round($total - $paid, 2),
                    'currency_code' => (string) $r['currency'],
                    'issued_on'     => (string) $r['issue_date'],
                ];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }

        if ($kind === 'purchase_invoice') {
            $stmt = $pdo->prepare(
                "SELECT pi.id, pi.vendor_invoice_number, pi.varsymbol, pi.issue_date, pi.total_with_vat,
                        cur.code AS currency, c.company_name AS partner_name
                   FROM purchase_invoices pi
                   JOIN currencies cur ON cur.id = pi.currency_id
                   JOIN clients c ON c.id = pi.vendor_id
                  WHERE pi.supplier_id = ?
                    AND pi.status IN ('received','booked')
                    AND cur.code = 'CZK'
                    -- DDKP (§ 28 ZDPH) není platební cíl: peníze odešly už na zálohové
                    -- faktuře, doklad jen nese nárok na odpočet DPH (343/314) a závazek
                    -- na 321 nikdy nezaložil. Bez filtru by ho našeptávač nabídl k úhradě
                    -- hotově a zaúčtoval 321 MD / 211 D → fantomové saldo. Stejné pravidlo
                    -- drží BankPostingService (protiúčet) i PurchaseInvoiceRepository
                    -- (příkaz k úhradě). Zálohová PF ('advance') se hotově platit SMÍ.
                    AND pi.document_kind <> 'tax_document'
                    AND (pi.vendor_invoice_number LIKE ? OR pi.varsymbol LIKE ? OR c.company_name LIKE ?)
                  ORDER BY pi.issue_date DESC, pi.id DESC
                  LIMIT " . $limit
            );
            $stmt->execute([$supplierId, $like, $like, $like]);
            return array_map(static function (array $r): array {
                $total = (float) $r['total_with_vat'];
                return [
                    'id'            => (int) $r['id'],
                    'kind'          => 'purchase_invoice',
                    'number'        => (string) ($r['vendor_invoice_number'] ?? $r['varsymbol'] ?? ''),
                    'partner_name'  => (string) ($r['partner_name'] ?? ''),
                    'total'         => round($total, 2),
                    'paid'          => 0.0,
                    'remaining'     => round($total, 2),
                    'currency_code' => (string) $r['currency'],
                    'issued_on'     => (string) $r['issue_date'],
                ];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }

        throw new CashException('validation', 'Neplatný typ dokladu (invoice|purchase_invoice).');
    }

    // ── post (v transakci volajícího) ──────────────────────────────────────────

    /**
     * @return array{doc_number:string, journal_entry_id:?int, status:string, warnings:list<string>}
     */
    private function doPost(int $supplierId, int $id, ?int $userId): array
    {
        $doc = $this->documents->lockForPost($supplierId, $id);
        if ($doc === null) {
            throw new CashException('validation', 'Pokladní doklad nenalezen.', 404);
        }

        $taxEvidence = $this->supplierAccountingMode($supplierId) === 'tax_evidence';

        // Idempotence dvojkliku: už zaúčtovaný doklad se NIKDY nepřeúčtovává (B2).
        // Rozhoduje POUZE status='posted' (ne režim ani journal_entry_id) — re-post
        // legitimně zaúčtovaného dokladu vrací idempotentně v OBOU režimech a nikdy
        // nehodí doc_not_draft.
        if ($doc['status'] !== 'draft') {
            if ($doc['status'] === 'posted') {
                return [
                    'doc_number'       => (string) $doc['doc_number'],
                    'journal_entry_id' => $doc['journal_entry_id'] !== null ? (int) $doc['journal_entry_id'] : null,
                    'status'           => 'posted',
                    'warnings'         => [],
                ];
            }
            throw new CashException('doc_not_draft', 'Zaúčtovat lze jen rozpracovaný (draft) doklad.');
        }
        $this->assertTaxDateUnlocked($supplierId, (string) ($doc['tax_date'] ?? $doc['issue_date']));

        $register = $this->requireActiveRegister($supplierId, (int) $doc['register_id']);
        $doc['vat_lines'] = $this->documents->vatLinesFor($id);
        $this->validateDoc($supplierId, $doc, $doc['vat_lines'], $register);

        $docNumber = $doc['doc_number'];
        if ($docNumber === null) {
            $seriesCode = $doc['doc_type'] === 'in' ? 'cash_in' : 'cash_out';
            $fiscalYear = (int) substr((string) $doc['issue_date'], 0, 4);
            $docNumber = $this->series->next($supplierId, $seriesCode, $fiscalYear);
        }

        // Daňová evidence (DE §6, R6): posted doklad BEZ journalu a BEZ posting enginu /
        // kontroly účetního období (v DE období neexistují; zámek je booked_at, R14).
        // Side-effecty úhrad FV/PF (anotace pro peněžní deník, noha A) zůstávají shodné.
        if ($taxEvidence) {
            $this->applySideEffects($supplierId, $id, $doc, $docNumber, $userId);
            $this->documents->markPostedNoJournal($id, $docNumber);

            return [
                'doc_number'       => $docNumber,
                'journal_entry_id' => null,
                'status'           => 'posted',
                'warnings'         => $this->collectWarnings($supplierId, $doc, (string) $register['account_code']),
            ];
        }

        $lines = $this->buildLines($supplierId, $doc, (string) $register['account_code']);
        $lines = $this->attachCashFx($lines, $doc, (string) $register['account_code']);

        $entryId = $this->posting->postDocument($supplierId, 'cash', (int) $doc['id'], $lines, [
            'entry_date'    => (string) $doc['issue_date'],
            'document_date' => (string) ($doc['tax_date'] ?? $doc['issue_date']),
            'document_no'   => $docNumber,
            'description'   => (string) $doc['description'],
            'posted'        => true,
            'user_id'       => $userId,
            'posted_by'     => $userId,
        ]);

        $this->applySideEffects($supplierId, $id, $doc, $docNumber, $userId);
        $this->documents->markPosted($id, $docNumber, $entryId);

        return [
            'doc_number'       => $docNumber,
            'journal_entry_id' => $entryId,
            'status'           => 'posted',
            'warnings'         => $this->collectWarnings($supplierId, $doc, (string) $register['account_code']),
        ];
    }

    /**
     * @return array{journal_entry_id:int, already:bool}
     */
    private function doBackfillJournal(int $supplierId, int $id): array
    {
        $doc = $this->documents->lockForPost($supplierId, $id);
        if ($doc === null) {
            throw new CashException('validation', 'Pokladní doklad nenalezen.', 404);
        }
        if ($doc['status'] !== 'posted') {
            throw new CashException('doc_not_posted', 'Doúčtovat historii lze jen u zaúčtovaného dokladu.');
        }
        if ($doc['journal_entry_id'] !== null) {
            return ['journal_entry_id' => (int) $doc['journal_entry_id'], 'already' => true];
        }

        $register = $this->registers->find($supplierId, (int) $doc['register_id']);
        if ($register === null) {
            throw new CashException('register_not_found', 'Pokladna nenalezena.', 404);
        }
        $doc['vat_lines'] = $this->documents->vatLinesFor($id);
        $lines = $this->buildLines($supplierId, $doc, (string) $register['account_code']);
        $lines = $this->attachCashFx($lines, $doc, (string) $register['account_code']);

        $entryId = $this->posting->postDocument($supplierId, 'cash', (int) $doc['id'], $lines, [
            'entry_date'    => (string) $doc['issue_date'],
            'document_date' => (string) ($doc['tax_date'] ?? $doc['issue_date']),
            'document_no'   => (string) $doc['doc_number'],
            'description'   => (string) $doc['description'],
            'posted'        => true,
        ]);

        $this->documents->markPosted($id, (string) $doc['doc_number'], $entryId);

        return ['journal_entry_id' => $entryId, 'already' => false];
    }

    /** Side-effecty úhrad faktur (v téže transakci). */
    private function applySideEffects(int $supplierId, int $id, array $doc, string $docNumber, ?int $userId): void
    {
        if ($doc['purpose'] === 'invoice_payment' && $doc['invoice_id'] !== null) {
            $res = $this->invoicePayments->recordPayment(
                (int) $doc['invoice_id'],
                (float) $doc['total_amount'],
                (string) $doc['issue_date'],
                ['source' => 'cash', 'note' => $docNumber, 'created_by' => $userId],
            );
            $this->documents->setInvoicePaymentId($id, (int) $res['payment_id']);

            $invoice = $this->invoices->find((int) $doc['invoice_id']);
            if (($invoice['invoice_type'] ?? null) === 'proforma') {
                if ($res['became_paid']) {
                    $this->finalCreator->create(
                        (int) $doc['invoice_id'],
                        $userId ?? 0,
                        (string) $doc['issue_date'],
                    );
                } else {
                    try {
                        $this->taxDocCreator->createForPayment((int) $res['payment_id'], $userId ?? 0);
                    } catch (\RuntimeException) {
                        // Neplátce DPH / reverse charge / jiná podmínka — DDKP nevzniká.
                    }
                }
            }
        } elseif ($doc['purpose'] === 'purchase_payment' && $doc['purchase_invoice_id'] !== null) {
            $this->purchaseInvoices->setStatus((int) $doc['purchase_invoice_id'], 'paid', $supplierId, (string) $doc['issue_date']);
        }
    }

    /** @return list<string> */
    private function collectWarnings(int $supplierId, array $doc, string $accountCode): array
    {
        $warnings = [];
        $balance = $this->supplierAccountingMode($supplierId) === 'tax_evidence'
            ? $this->cashRegisters->documentsSignedTotal(
                $supplierId,
                (int) $doc['register_id'],
                (string) $doc['issue_date'],
            )
            : $this->accountBalance($supplierId, $accountCode, (string) $doc['issue_date']);
        if (self::cents($balance) < 0) {
            $warnings[] = 'cash.warning.negative_balance';
        }
        if ($doc['purpose'] === 'sale' && $doc['vat_mode'] === 'vat'
            && self::cents($doc['total_amount']) > self::cents((float) $this->taxConstants
                ->forYear((int) substr((string) $doc['issue_date'], 0, 4))['kh_item_threshold'])
            && trim((string) ($doc['partner_dic'] ?? '')) === ''
        ) {
            $warnings[] = 'cash.warning.dic_missing_over_10k';
        }
        // Valutový doklad s RUČNÍM kurzem, který se liší od denního ČNB nad práh (§C —
        // stejný audit jako u faktur): varování, NEblokovat (ČNB-fetchnutý kurz sedí → null).
        if (strtoupper((string) ($doc['currency_code'] ?? 'CZK')) !== 'CZK'
            && $this->rateChecker->deviationWarning(
                $supplierId,
                (string) $doc['currency_code'],
                (string) $doc['issue_date'],
                (float) ($doc['fx_rate'] ?? 0),
            ) !== null
        ) {
            $warnings[] = 'cash.warning.fx_rate_deviation';
        }
        return $warnings;
    }

    // ── buildery zaúčtování (§3.4) ──────────────────────────────────────────────

    /**
     * @return list<array{account_code:string, side:string, amount:float}>
     */
    private function buildLines(int $supplierId, array $doc, string $cashAccount): array
    {
        $total = round((float) $doc['total_amount'], 2);
        $vatMode = $doc['vat_mode'] === 'vat';
        $vatLines = $doc['vat_lines'] ?? [];
        $baseSum = $vatMode ? $this->sumBase($vatLines) : $total;

        return match ($doc['purpose']) {
            'sale' => $this->buildSale($supplierId, $doc, $cashAccount, $total, $baseSum, $vatMode ? $vatLines : []),
            'purchase' => $this->buildPurchase($supplierId, $doc, $cashAccount, $total, $baseSum, $vatMode ? $vatLines : []),
            'invoice_payment' => $this->buildInvoicePayment($supplierId, $doc, $cashAccount, $total),
            'purchase_payment' => $this->buildPurchasePayment($supplierId, $doc, $cashAccount, $total),
            'transfer' => $doc['doc_type'] === 'in'
                ? [
                    $this->line($cashAccount, 'debit', $total),
                    $this->line($this->ruleAccount($supplierId, 'cash.transfer.frombank', 'credit', '261'), 'credit', $total),
                ]
                : [
                    $this->line($this->ruleAccount($supplierId, 'cash.deposit.cashtobank', 'debit', '261'), 'debit', $total),
                    $this->line($cashAccount, 'credit', $total),
                ],
            'other' => $this->buildOther($supplierId, $doc, $cashAccount, $total),
            default => throw new CashException('invalid_purpose_type', 'Neznámý účel dokladu.'),
        };
    }

    /**
     * @param list<array<string,mixed>> $vatLines
     * @return list<array{account_code:string, side:string, amount:float}>
     */
    private function buildSale(int $supplierId, array $doc, string $cashAccount, float $total, float $baseSum, array $vatLines): array
    {
        $revenue = $this->ruleAccount($supplierId, 'cash.revenue', 'credit', '602');
        $lines = [$this->line($cashAccount, 'debit', $total)];
        $lines[] = $this->line($revenue, 'credit', $baseSum);
        foreach ($vatLines as $vl) {
            $lines[] = $this->line('343', 'credit', (float) $vl['vat_amount']);
        }
        return $lines;
    }

    /**
     * @param list<array<string,mixed>> $vatLines
     * @return list<array{account_code:string, side:string, amount:float}>
     */
    private function buildPurchase(int $supplierId, array $doc, string $cashAccount, float $total, float $baseSum, array $vatLines): array
    {
        $expense = $this->ruleAccount($supplierId, 'cash.purchase', 'debit', '501');
        $lines = [$this->line($expense, 'debit', $baseSum)];
        foreach ($vatLines as $vl) {
            $lines[] = $this->line('343', 'debit', (float) $vl['vat_amount']);
        }
        $lines[] = $this->line($cashAccount, 'credit', $total);
        return $lines;
    }

    /**
     * Úhrada vydané faktury hotově: běžná FV → 211/311, proforma (zálohová) → inkaso
     * přijaté zálohy 211/324 (B5 — proforma není daňový doklad, nemá saldokonto 311).
     *
     * @return list<array{account_code:string, side:string, amount:float}>
     */
    private function buildInvoicePayment(int $supplierId, array $doc, string $cashAccount, float $total): array
    {
        if ($doc['invoice_id'] !== null && $this->invoiceType($supplierId, (int) $doc['invoice_id']) === 'proforma') {
            return [
                $this->line($cashAccount, 'debit', $total),
                $this->line($this->ruleAccount($supplierId, 'advance.received.collection', 'credit', '324'), 'credit', $total),
            ];
        }
        return [
            $this->line($cashAccount, 'debit', $total),
            $this->line($this->ruleAccount($supplierId, 'payment.receivable.cash', 'credit', '311'), 'credit', $total),
        ];
    }

    /**
     * Úhrada přijaté faktury hotově: běžná PF → 321/211, zálohová PF (document_kind=advance)
     * → poskytnutá záloha 314/211 (B5).
     *
     * @return list<array{account_code:string, side:string, amount:float}>
     */
    private function buildPurchasePayment(int $supplierId, array $doc, string $cashAccount, float $total): array
    {
        $kind = $doc['purchase_invoice_id'] !== null
            ? $this->purchaseDocumentKind($supplierId, (int) $doc['purchase_invoice_id'])
            : 'invoice';
        // DDKP NAVÁZANÝ NA ZÁLOHOVOU FAKTURU nemá závazek 321 ani vlastní úhradu (účtuje jen
        // 343/314) — peníze odešly už na záloze. Bez tohoto guardu by spadl do běžné větve
        // a zaúčtoval fantomové 321 MD / 211 D proti dokladu, který 321 nikdy nezaložil.
        // Zrcadlo BankPostingService::ddkp_not_payable; našeptávač DDKP nenabízí, tohle je
        // pojistka proti přímému volání API.
        if ($kind === 'tax_document') {
            throw new CashException(
                'ddkp_not_payable',
                'Daňový doklad k platbě (DDKP) se neplatí samostatně — úhradu navaž na '
                    . 'zálohovou fakturu (document_kind=advance), ke které DDKP patří.',
            );
        }
        // Samostatný DDKP (bez zálohové faktury) vlastní úhradu naopak MÁ — platí se jako
        // záloha na 314. Zrcadlo bankovní větve.
        if ($kind === 'advance' || $kind === 'tax_document_standalone') {
            return [
                $this->line($this->ruleAccount($supplierId, 'advance.paid.payment', 'debit', '314'), 'debit', $total),
                $this->line($cashAccount, 'credit', $total),
            ];
        }
        return [
            $this->line($this->ruleAccount($supplierId, 'payment.payable.cash', 'debit', '321'), 'debit', $total),
            $this->line($cashAccount, 'credit', $total),
        ];
    }

    private function invoiceType(int $supplierId, int $invoiceId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT invoice_type FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$invoiceId, $supplierId]);
        $v = $stmt->fetchColumn();
        return $v === false ? 'invoice' : (string) $v;
    }

    /**
     * Druh dokladu pro výběr saldokontního účtu. SAMOSTATNÝ daňový doklad k platbě
     * (bez vazby na zálohovou fakturu) se vrací jako `tax_document_standalone` — je to
     * jediný doklad, který k té platbě existuje, takže se platí a účtuje jako záloha.
     */
    private function purchaseDocumentKind(int $supplierId, int $pfId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT document_kind, parent_purchase_invoice_id
               FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$pfId, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return 'invoice';
        }
        $kind = (string) $row['document_kind'];

        return $kind === 'tax_document' && $row['parent_purchase_invoice_id'] === null
            ? 'tax_document_standalone'
            : $kind;
    }

    /**
     * @return list<array{account_code:string, side:string, amount:float}>
     */
    private function buildOther(int $supplierId, array $doc, string $cashAccount, float $total): array
    {
        $counter = $this->resolveOtherCounter($supplierId, $doc);
        return $doc['doc_type'] === 'in'
            ? [$this->line($cashAccount, 'debit', $total), $this->line($counter, 'credit', $total)]
            : [$this->line($counter, 'debit', $total), $this->line($cashAccount, 'credit', $total)];
    }

    /** Protiúčet pro purpose=other: buď volný účet, nebo neúčtově-211 strana kontace. */
    private function resolveOtherCounter(int $supplierId, array $doc): string
    {
        $counterCode = $doc['counter_account_code'] ?? null;
        if ($counterCode !== null && $counterCode !== '') {
            return (string) $counterCode;
        }
        $rule = $this->rules->resolve($supplierId, (string) $doc['rule_key']);
        if ($rule === null) {
            throw new CashException('counter_account_invalid', 'Kontace ' . (string) $doc['rule_key'] . ' neexistuje.');
        }
        $debit = (string) ($rule['debit_account_code'] ?? '');
        $credit = (string) ($rule['credit_account_code'] ?? '');
        if (str_starts_with($debit, '211')) {
            return $credit;
        }
        if (str_starts_with($credit, '211')) {
            return $debit;
        }
        return $debit !== '' ? $debit : $credit;
    }

    /** Účet z kontace (per-tenant override) s fallbackem; strana = debit|credit. */
    private function ruleAccount(int $supplierId, string $ruleKey, string $side, string $fallback): string
    {
        $rule = $this->rules->resolve($supplierId, $ruleKey);
        $code = $rule[$side . '_account_code'] ?? null;
        return $code !== null && $code !== '' ? (string) $code : $fallback;
    }

    /**
     * @return array{account_code:string, side:string, amount:float}
     */
    private function line(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => round($amount, 2)];
    }

    // ── validace (§3.5) ─────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $doc
     * @param list<array<string,mixed>> $vatLines
     * @param array<string,mixed> $register
     */
    private function validateDoc(int $supplierId, array $doc, array $vatLines, array $register): void
    {
        $purpose = (string) $doc['purpose'];
        $docType = (string) $doc['doc_type'];
        $vatMode = (string) $doc['vat_mode'];

        if (!isset(self::PURPOSE_MATRIX[$purpose]) || !in_array($docType, ['in', 'out'], true)) {
            throw new CashException('invalid_purpose_type', 'Neplatná kombinace typu a účelu dokladu.');
        }
        if (!in_array($docType, self::PURPOSE_MATRIX[$purpose], true)) {
            throw new CashException('invalid_purpose_type', 'Účel „' . $purpose . '" nelze použít pro tento typ dokladu.');
        }
        // Valutová pokladna v1: jen prodej/nákup/ostatní pohyb účtované jedním kurzem
        // (žádný kurzový rozdíl při zaúčtování). Úhrady faktur (saldokonto 311/321 → FX
        // rozdíl §24/6) a převody přes 261 (peníze na cestě) vyžadují korunovou pokladnu.
        if (strtoupper((string) ($register['currency_code'] ?? 'CZK')) !== 'CZK'
            && !in_array($purpose, ['sale', 'purchase', 'other'], true)
        ) {
            throw new CashException(
                'foreign_register_purpose_unsupported',
                'Valutová pokladna zatím podporuje jen prodej, nákup a ostatní pohyb — úhrady faktur a převody veďte v korunové pokladně.',
            );
        }
        if (self::cents($doc['total_amount']) <= 0) {
            throw new CashException('validation', 'Částka dokladu musí být větší než 0.');
        }
        if (!self::isDate((string) $doc['issue_date'])) {
            throw new CashException('validation', 'Datum vystavení je povinné.');
        }
        if (trim((string) $doc['description']) === '') {
            throw new CashException('validation', 'Popis (obsah účetního případu) je povinný.');
        }

        // Vazby na faktury dle účelu.
        if ($purpose === 'invoice_payment') {
            if ($doc['invoice_id'] === null || $doc['purchase_invoice_id'] !== null) {
                throw new CashException('validation', 'Úhrada FV vyžaduje právě jedno invoice_id.');
            }
            $this->validateInvoicePayment($supplierId, (int) $doc['invoice_id'], (float) $doc['total_amount']);
        } elseif ($purpose === 'purchase_payment') {
            if ($doc['purchase_invoice_id'] === null || $doc['invoice_id'] !== null) {
                throw new CashException('validation', 'Úhrada PF vyžaduje právě jedno purchase_invoice_id.');
            }
            $this->validatePurchasePayment($supplierId, (int) $doc['purchase_invoice_id'], (float) $doc['total_amount']);
        } else {
            if ($doc['invoice_id'] !== null || $doc['purchase_invoice_id'] !== null) {
                throw new CashException('validation', 'Vazba na fakturu je povolena jen u úhrady FV/PF.');
            }
        }

        // DPH.
        if ($vatMode === 'vat') {
            if (!in_array($purpose, ['sale', 'purchase'], true)) {
                throw new CashException('vat_purpose_not_allowed', 'Daňový režim je povolen jen pro prodej a nákup.');
            }
            $this->validateVatLines($doc, $vatLines);
            // Nad prahem KH spadá doklad do B.2, kam patří evidenční číslo
            // dodavatele, ne interní číslo VPD. Přesně 10 000 Kč je ještě B.3.
            $khThreshold = (float) $this->taxConstants
                ->forYear((int) substr((string) $doc['issue_date'], 0, 4))['kh_item_threshold'];
            if ($purpose === 'purchase' && self::cents($doc['total_amount']) > self::cents($khThreshold)) {
                throw new CashException(
                    'purchase_vat_over_10k',
                    sprintf('Daňový nákup nad %.0f Kč zaevidujte jako přijatou fakturu a uhraďte režimem Úhrada faktury (§30 ZDPH).', $khThreshold),
                );
            }
        } elseif ($vatLines !== []) {
            // Řádky DPH bez daňového režimu = nekonzistence vstupu.
            throw new CashException('validation', 'DPH rozpad je povolen jen u daňového dokladu.');
        }

        // purpose=other: právě jedno z rule_key / counter_account_code, protiúčet platný.
        if ($purpose === 'other') {
            $this->validateOther($supplierId, $doc, $register);
        }
    }

    private function validateInvoicePayment(int $supplierId, int $invoiceId, float $amount): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.status, i.invoice_type, i.amount_to_pay, i.paid_total, cur.code AS currency
               FROM invoices i JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ? AND i.supplier_id = ?'
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($inv === false) {
            throw new CashException('invoice_not_found', 'Vydaná faktura nenalezena.', 404);
        }
        // Proforma (zálohová) se hotově hradit SMÍ (B5 — inkaso zálohy 211/324); jen
        // draft/cancelled zůstávají zakázané.
        if (in_array((string) $inv['status'], ['draft', 'cancelled'], true)) {
            throw new CashException('invoice_invalid_status', 'Fakturu v tomto stavu nelze hradit hotově.');
        }
        // B6 (FX úhrady 563/663) řeší VĚDOMĚ jen banku: pokladní doklad je CZK-only už z
        // konstrukce (normalize() fixuje currency_code='CZK'/fx_rate=1.0 a cash_documents
        // nemá sloupec pro cizoměnovou částku), takže kurzový přepočet úhrady zde nelze
        // vyjádřit. Cizoměnová hotovostní úhrada by vyžadovala rozšíření schématu (foreign
        // amount + měna) i UI — mimo scope B6.
        if ((string) $inv['currency'] !== 'CZK') {
            throw new CashException('foreign_currency_invoice', 'Úhrada cizoměnové faktury z pokladny zatím není podporována.');
        }
        $remaining = self::cents($inv['amount_to_pay']) - self::cents($inv['paid_total']);
        if (self::cents($amount) > $remaining) {
            throw new CashException('amount_exceeds_remaining', 'Částka převyšuje zbývající úhradu faktury.');
        }
    }

    private function validatePurchasePayment(int $supplierId, int $purchaseInvoiceId, float $amount): void
    {
        $pf = $this->purchaseInvoices->find($purchaseInvoiceId, $supplierId);
        if ($pf === null) {
            throw new CashException('invoice_not_found', 'Přijatá faktura nenalezena.', 404);
        }
        if ((string) $pf['currency'] !== 'CZK') {
            throw new CashException('foreign_currency_invoice', 'Úhrada cizoměnové faktury z pokladny zatím není podporována.');
        }
        if (!in_array((string) $pf['status'], ['received', 'booked'], true)) {
            throw new CashException('invoice_invalid_status', 'Přijatou fakturu v tomto stavu nelze hradit hotově.');
        }
        if (self::cents($amount) !== self::cents($pf['total_with_vat'])) {
            throw new CashException('partial_purchase_payment', 'Přijatou fakturu lze hotově uhradit jen v plné výši.');
        }
    }

    /**
     * @param list<array<string,mixed>> $vatLines
     */
    private function validateVatLines(array $doc, array $vatLines): void
    {
        if ($vatLines === []) {
            throw new CashException('vat_rate_invalid', 'Daňový doklad vyžaduje aspoň jeden řádek DPH.');
        }
        $year = (int) substr((string) ($doc['tax_date'] ?? $doc['issue_date']), 0, 4);
        $constants = $this->taxConstants->forYear($year);
        $allowed = [];
        foreach (['vat_rate_standard', 'vat_rate_reduced'] as $key) {
            $rate = round((float) ($constants[$key] ?? 0), 2);
            if ($rate > 0) {
                $allowed[] = $rate;
            }
        }
        $sumCents = 0;
        foreach ($vatLines as $vl) {
            $rate = round((float) $vl['vat_rate'], 2);
            if ($rate <= 0 || !in_array($rate, $allowed, true)) {
                throw new CashException('vat_rate_invalid', 'Sazba DPH ' . $rate . ' % není v číselníku pro rok ' . $year . '.');
            }
            $sumCents += self::cents($vl['base_amount']) + self::cents($vl['vat_amount']);
        }
        if ($sumCents !== self::cents($doc['total_amount'])) {
            throw new CashException('vat_lines_mismatch', 'Σ(základ+daň) rozpadu se musí přesně rovnat celkové částce.');
        }
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $register
     */
    private function validateOther(int $supplierId, array $doc, array $register): void
    {
        $ruleKey = $doc['rule_key'] ?? null;
        $counter = $doc['counter_account_code'] ?? null;
        $hasRule = $ruleKey !== null && $ruleKey !== '';
        $hasCounter = $counter !== null && $counter !== '';
        if ($hasRule === $hasCounter) {
            throw new CashException('counter_account_invalid', 'Zadejte buď kontaci, nebo protiúčet (právě jedno).');
        }
        if ($hasCounter) {
            $account = $this->accounts->findByCode($supplierId, (string) $counter);
            if ($account === null || empty($account['is_active'])) {
                throw new CashException('counter_account_invalid', 'Protiúčet ' . (string) $counter . ' není v osnově nebo je neaktivní.');
            }
            if ((string) $counter === (string) $register['account_code']) {
                throw new CashException('counter_account_invalid', 'Protiúčet nesmí být účet pokladny.');
            }
        } elseif ($this->rules->resolve($supplierId, (string) $ruleKey) === null) {
            throw new CashException('counter_account_invalid', 'Kontace ' . (string) $ruleKey . ' neexistuje.');
        } elseif (!$this->rulePresets->isAllowedForOther($supplierId, (string) $ruleKey)) {
            // Bez tohoto filtru by prošla i kontace bez nohy na 211 (např. bankovní
            // strana převodu 261/221) a resolveOtherCounter() by z ní odvodil
            // nesmyslný protiúčet.
            throw new CashException(
                'counter_account_invalid',
                'Kontace ' . (string) $ruleKey . ' se netýká pokladny nebo ji řeší vlastní účel dokladu.',
            );
        }

        // Valutová pokladna: účel „ostatní" nesmí obejít blok úhrad/převodů přes protiúčet
        // saldokonta/peněz na cestě (261/311/321/314/324) — ty vyžadují korunovou pokladnu
        // (FX rozdíl §24/6 / dvojí konverze). Platí i pro kontaci (resolveOtherCounter).
        if (strtoupper((string) ($register['currency_code'] ?? 'CZK')) !== 'CZK') {
            $effectiveCounter = $this->resolveOtherCounter($supplierId, $doc);
            if (preg_match('/^(?:261|311|321|314|324)/', $effectiveCounter) === 1) {
                throw new CashException(
                    'foreign_register_purpose_unsupported',
                    'Valutová pokladna zatím podporuje jen prodej, nákup a ostatní pohyb — úhrady faktur a převody (protiúčet 261/311/321/314/324) veďte v korunové pokladně.',
                );
            }
        }
    }

    // ── pomocné ─────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function requireActiveRegister(int $supplierId, int $registerId): array
    {
        $register = $this->registers->find($supplierId, $registerId);
        if ($register === null) {
            throw new CashException('register_not_found', 'Pokladna nenalezena.', 404);
        }
        if (empty($register['is_active'])) {
            throw new CashException('register_inactive', 'Pokladna je neaktivní.');
        }
        return $register;
    }

    private function accountBalance(int $supplierId, string $accountCode, string $date): float
    {
        $account = $this->accounts->findByCode($supplierId, $accountCode);
        if ($account === null) {
            return 0.0;
        }
        $dayAfter = date('Y-m-d', strtotime($date . ' +1 day'));
        return $this->ledger->accountOpening($supplierId, (int) $account['id'], $dayAfter, $dayAfter);
    }

    /**
     * Účetní režim firmy (Epic DE §2.1). `tax_evidence` = daňová evidence OSVČ
     * (kasová báze, no-journal post path §6), `double_entry` = podvojné účetnictví.
     */
    private function supplierAccountingMode(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $mode = $stmt->fetchColumn();
        return $mode === 'tax_evidence' ? 'tax_evidence' : 'double_entry';
    }

    private function invoiceNumber(int $supplierId, int $invoiceId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT varsymbol FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$invoiceId, $supplierId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    private function purchaseNumber(int $supplierId, int $purchaseInvoiceId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT vendor_invoice_number FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$purchaseInvoiceId, $supplierId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    /**
     * Podklad pro PDF pokladního dokladu (§5.5) — doklad + firma + zaúčtování
     * (MD/D účty pro patičku). Jen zaúčtované/stornované doklady (draft nemá PDF).
     *
     * @return array{document:array<string,mixed>, supplier:array<string,mixed>,
     *   posting:list<array{side:string, amount:float, account_code:string, account_name:string}>,
     *   generated_at:string}
     */
    public function pdfData(int $supplierId, int $id): array
    {
        $doc = $this->get($supplierId, $id);
        if ((string) $doc['status'] === 'draft') {
            throw new CashException('doc_not_draft', 'PDF lze vystavit jen pro zaúčtovaný doklad.');
        }
        $posting = $doc['journal_entry_id'] !== null
            ? $this->loadPostingLines($supplierId, (int) $doc['journal_entry_id'])
            : [];
        return [
            'document'     => $doc,
            'supplier'     => $this->loadSupplier($supplierId, (string) $doc['issue_date']),
            'posting'      => $posting,
            'generated_at' => date('Y-m-d'),
        ];
    }

    /** @return array<string,mixed> */
    private function loadSupplier(int $supplierId, string $atDate): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT company_name, street, city, zip, ic, dic, is_vat_payer
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return [];
        }
        // Patička PDF nese plátcovství k datu vystavení DOKLADU (historie),
        // ne dnešní cache — doklad z období plátcovství ho musí ukazovat i po zrušení registrace.
        $row['is_vat_payer'] = $this->vatStatus->isVatPayerAt($supplierId, $atDate) ? 1 : 0;
        return $row;
    }

    /**
     * @return list<array{side:string, amount:float, account_code:string, account_name:string}>
     */
    private function loadPostingLines(int $supplierId, int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT jel.side, jel.amount, coa.account_code, coa.name AS account_name
               FROM journal_entry_lines jel
               JOIN chart_of_accounts coa ON coa.id = jel.account_id
              WHERE jel.entry_id = ? AND jel.supplier_id = ?
              ORDER BY jel.line_no ASC, jel.id ASC'
        );
        $stmt->execute([$entryId, $supplierId]);
        return array_map(static fn (array $r): array => [
            'side'         => (string) $r['side'],
            'amount'       => (float) $r['amount'],
            'account_code' => (string) $r['account_code'],
            'account_name' => (string) ($r['account_name'] ?? ''),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param list<array<string,mixed>> $vatLines
     */
    private function sumBase(array $vatLines): float
    {
        $sum = 0.0;
        foreach ($vatLines as $vl) {
            $sum += (float) $vl['base_amount'];
        }
        return round($sum, 2);
    }

    /**
     * Finalizuje měnu/kurz/částky dokladu dle MĚNY POKLADNY (§11 valutová pokladna).
     *
     * CZK pokladna: currency_code='CZK', fx_rate=1, amount_foreign=NULL, total_amount
     * beze změny (chování v1 zcela zachováno). Valutová pokladna: total_amount =
     * amount_foreign × kurz ČNB k datu vystavení (CZK ekvivalent = autoritativní pro
     * deník i CZK zůstatek); kurz z klienta ČNB (cache-first), nebo ruční fx_rate override.
     *
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $register
     * @return array<string,mixed>
     */
    private function resolveCurrency(array $doc, array $register): array
    {
        $currency = strtoupper(trim((string) ($register['currency_code'] ?? 'CZK')));
        if ($currency === 'CZK') {
            $doc['currency_code']  = 'CZK';
            $doc['fx_rate']        = 1.0;
            $doc['amount_foreign'] = null;
            return $doc;
        }

        // Nepodporovaný účel odmítni DŘÍV, než se řeší kurz. Kurz na výsledku nic nemění
        // (doklad stejně neprojde) a lookup ČNB by přebil skutečný důvod technickým
        // 'fx_rate_unavailable' — uživatel by pak doplňoval kurz do dokladu, který
        // valutová pokladna nepodpoří tak jako tak. validateDoc() kontrolu opakuje
        // (defense-in-depth pro ostatní vstupní body), je idempotentní.
        if (!in_array((string) ($doc['purpose'] ?? ''), ['sale', 'purchase', 'other'], true)) {
            throw new CashException(
                'foreign_register_purpose_unsupported',
                'Valutová pokladna zatím podporuje jen prodej, nákup a ostatní pohyb — úhrady faktur a převody veďte v korunové pokladně.',
            );
        }

        // Částka v cizí měně: primárně amount_foreign; fallback total_amount (FE, které
        // valutovou pokladnu neumí, pošle částku jako total_amount).
        $foreign = round((float) ($doc['amount_foreign'] ?? $doc['total_amount'] ?? 0), 2);
        if (self::cents($foreign) <= 0) {
            throw new CashException('validation', 'Částka v cizí měně musí být větší než 0.');
        }
        $rate = (float) ($doc['fx_rate'] ?? 0);
        if ($rate <= 0) {
            $info = $this->cnb->getRate($currency, new DateTimeImmutable((string) $doc['issue_date']));
            if ($info === null) {
                throw new CashException(
                    'fx_rate_unavailable',
                    'Kurz ČNB pro měnu ' . $currency . ' k ' . (string) $doc['issue_date'] . ' není k dispozici — doplňte kurz.',
                );
            }
            $rate = (float) $info['rate'];
        }
        $doc['currency_code']  = $currency;
        $doc['fx_rate']        = round($rate, 6);
        $doc['amount_foreign'] = $foreign;

        // DPH rozpad: §4/12 — daň se NEPOČÍTÁ cizím kurzem, jen se HOTOVÝ rozpad (základ+daň
        // per sazba) převede kurzem DOKLADU na CZK. CZK total = Σ převedených řádků (invariant
        // Σ(základ+daň)_CZK == total_amount_CZK je pak splněn přesně z konstrukce). Bez DPH:
        // total = amount_foreign × kurz.
        if (($doc['vat_mode'] ?? 'none') === 'vat' && !empty($doc['vat_lines'])) {
            [$doc['vat_lines'], $doc['total_amount']] = $this->convertVatLinesToCzk($doc['vat_lines'], $rate);
        } else {
            $doc['total_amount'] = round($foreign * $rate, 2);
        }
        return $doc;
    }

    /**
     * Převede DPH rozpad z měny dokladu na CZK kurzem dokladu (§4/12): každý řádek
     * base_amount/vat_amount × kurz, zaokrouhlený na haléře. CZK total = Σ(základ+daň)
     * všech řádků → přesná rovnost s total_amount (žádné dodatečné dorovnání 648/548;
     * doklad se skládá z položek, ne naopak). Cizoměnové částky rozpadu jsou jen
     * pro zobrazení (v DB se ukládá CZK, jako u faktury).
     *
     * @param list<array<string,mixed>> $lines
     * @return array{0: list<array{vat_rate:float, base_amount:float, vat_amount:float}>, 1: float}
     */
    private function convertVatLinesToCzk(array $lines, float $rate): array
    {
        $out = [];
        $totalCents = 0;
        foreach ($lines as $l) {
            $baseCents = (int) round(((float) $l['base_amount']) * $rate * 100.0);
            $vatCents  = (int) round(((float) $l['vat_amount']) * $rate * 100.0);
            $out[] = [
                'vat_rate'    => round((float) $l['vat_rate'], 2),
                'base_amount' => $baseCents / 100.0,
                'vat_amount'  => $vatCents / 100.0,
            ];
            $totalCents += $baseCents + $vatCents;
        }
        return [$out, $totalCents / 100.0];
    }

    /**
     * Doplní cizoměnovou stopu (currency_code/fx_rate/amount_foreign) na řádek analytiky
     * pokladny (211<suffix>) u valutového dokladu — nosič cizoměnové pozice pro přecenění
     * §24/6 (ClosingRepository::bankProposals ho vidí a FxRevaluationService přecení). CZK
     * doklad → beze změny (chování v1 zachováno). Protiúčty (602/501/343/261…) zůstávají CZK.
     *
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     * @return list<array<string,mixed>>
     */
    private function attachCashFx(array $lines, array $doc, string $cashAccount): array
    {
        $currency = strtoupper((string) ($doc['currency_code'] ?? 'CZK'));
        if ($currency === 'CZK' || ($doc['amount_foreign'] ?? null) === null) {
            return $lines;
        }
        $foreign = abs(round((float) $doc['amount_foreign'], 2));
        $rate = (float) ($doc['fx_rate'] ?? 0);
        foreach ($lines as $i => $line) {
            if (($line['account_code'] ?? null) === $cashAccount) {
                $lines[$i]['currency_code']  = $currency;
                $lines[$i]['fx_rate']        = $rate;
                $lines[$i]['amount_foreign'] = $foreign;
            }
        }
        return $lines;
    }

    /**
     * Normalizuje vstup na kanonický tvar (klíče shodné s DB řádkem doc).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalize(array $data): array
    {
        $purpose = (string) ($data['purpose'] ?? '');
        $docType = (string) ($data['doc_type'] ?? '');
        $vatMode = ($data['vat_mode'] ?? 'none') === 'vat' ? 'vat' : 'none';
        $issueDate = trim((string) ($data['issue_date'] ?? ''));
        $taxDate = $vatMode === 'vat'
            ? (self::isDate((string) ($data['tax_date'] ?? '')) ? (string) $data['tax_date'] : $issueDate)
            : null;

        $vatLines = [];
        if ($vatMode === 'vat' && is_array($data['vat_lines'] ?? null)) {
            foreach ($data['vat_lines'] as $vl) {
                if (!is_array($vl)) {
                    continue;
                }
                $vatLines[] = [
                    'vat_rate'    => round((float) ($vl['vat_rate'] ?? 0), 2),
                    'base_amount' => round((float) ($vl['base_amount'] ?? 0), 2),
                    'vat_amount'  => round((float) ($vl['vat_amount'] ?? 0), 2),
                ];
            }
            $vatLines = self::recomputeVatLines($vatLines);
        }

        return [
            'register_id'          => (int) ($data['register_id'] ?? 0),
            'doc_type'             => $docType,
            'purpose'              => $purpose,
            'issue_date'           => $issueDate,
            'tax_date'             => $taxDate,
            'partner_name'         => self::nullableString($data['partner_name'] ?? null),
            'partner_ic'           => self::nullableString($data['partner_ic'] ?? null),
            'partner_dic'          => self::nullableString($data['partner_dic'] ?? null),
            'description'          => trim((string) ($data['description'] ?? '')),
            'vat_mode'             => $vatMode,
            'total_amount'         => round((float) ($data['total_amount'] ?? 0), 2),
            // Měna/kurz/cizoměnová částka se finalizují v resolveCurrency() dle měny
            // pokladny; tady jen surový passthrough vstupu (valutová pokladna).
            'currency_code'        => 'CZK',
            'fx_rate'              => isset($data['fx_rate']) && (float) $data['fx_rate'] > 0 ? round((float) $data['fx_rate'], 6) : null,
            'amount_foreign'       => isset($data['amount_foreign']) && $data['amount_foreign'] !== null && $data['amount_foreign'] !== ''
                ? round((float) $data['amount_foreign'], 2) : null,
            'rule_key'             => self::nullableString($data['rule_key'] ?? null),
            'counter_account_code' => self::nullableString($data['counter_account_code'] ?? null),
            'invoice_id'           => isset($data['invoice_id']) && (int) $data['invoice_id'] > 0 ? (int) $data['invoice_id'] : null,
            'purchase_invoice_id'  => isset($data['purchase_invoice_id']) && (int) $data['purchase_invoice_id'] > 0 ? (int) $data['purchase_invoice_id'] : null,
            'status'               => 'draft',
            'vat_lines'            => $vatLines,
        ];
    }

    /**
     * Přepočte rozpad DPH na backendu podle § 37 odst. 2 — daň koeficientem z ceny
     * včetně daně, základ jako zbytek. Autoritativní je tedy jen BRUTTO řádku
     * (`base + vat`) a sazba; poslaný rozpad se přepíše.
     *
     * Proč: pokladna byla jediné místo, kde byla hodnota z frontendu autoritativní.
     * `validateVatLines()` ověřovala pouze `Σ(základ+daň) == celkem`, takže při brutto
     * 121 Kč a sazbě 21 % projde **12 101** různých rozpadů, přestože zákonný je právě
     * jeden (daň 21,00). Klient — vlastní i cizí přes API — tak mohl odvést libovolně
     * nízkou daň. Kontrola součtu tuhle třídu chyb nezachytí z principu.
     *
     * Proč se tím nerozbije zadání ZDOLA (§ 37/1): přepočet je vůči němu idempotentní.
     * Změřeno na 10 000 000 kombinací (0,01–50 000 Kč × sazby 12 a 21 %) —
     * `private/scripts/cash_vat_split_sweep.php`: **nula** rozdílů. Rozpad zadaný zdola
     * projde přepočtem shora beze změny, takže oba režimy frontendu zůstávají platné.
     *
     * Počítá se SSOT `InvoiceMath::compute()` v režimu shora — týž kód, kterým se
     * počítají faktury, včetně distribuce zaokrouhlovacího rezidua per sazba (dva řádky
     * téže sazby dají dohromady přesně daň z jejich součtu). Vlastní varianta vzorce by
     * byla přesně ta divergence duplicit, kterou má audit vymýtit.
     *
     * Volá se v `normalize()`, tedy v MĚNĚ DOKLADU a před `resolveCurrency()`:
     * podle § 4 odst. 12 se daň cizím kurzem nepočítá, kurzem se převádí až hotový rozpad.
     *
     * @param list<array{vat_rate:float, base_amount:float, vat_amount:float}> $lines
     * @return list<array{vat_rate:float, base_amount:float, vat_amount:float}>
     */
    private static function recomputeVatLines(array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $items = [];
        foreach ($lines as $l) {
            $items[] = [
                'quantity'                => 1,
                'unit_price_without_vat'  => round($l['base_amount'] + $l['vat_amount'], 2),
                'vat_rate_snapshot'       => $l['vat_rate'],
            ];
        }

        $computed = InvoiceMath::compute($items, false, true)['items'];

        $out = [];
        foreach ($lines as $i => $l) {
            $out[] = [
                'vat_rate'    => $l['vat_rate'],
                'base_amount' => $computed[$i]['base'],
                'vat_amount'  => $computed[$i]['vat'],
            ];
        }
        return $out;
    }

    private function assertTaxDateUnlocked(int $supplierId, string $date): void
    {
        if ($this->documentLocks->forDate($supplierId, $date)->dateLocked) {
            throw new CashException(
                'date_locked',
                'Daňové období je uzamčené; pokladní doklad v něm nelze zaúčtovat ani stornovat.',
                409,
            );
        }
    }

    private static function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }

    private static function cents(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
