<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Bank\FxPaymentSettlement;
use PDO;

/**
 * Dorovnání haléřového zbytku u UŽ ZAÚČTOVANÝCH úhrad přijatých faktur.
 *
 * Doklad se zaokrouhlením (total_with_vat 16 370,09, rounding +0,91, k úhradě 16 371,00)
 * má předpis D 321 v nominálu a úhradu zaplacenou zaokrouhleně. Živé párování to řeší
 * {@see BankPostingService::normalizeRoundingFullPurchase()}: alokaci srovná na nominál
 * a rozdíl pošle na 548/648. Úhrady spárované dřív tou normalizací neprošly (párování
 * shodou částky a data má skóre 65, dobropis s příchozí vratkou guard neznal), takže na
 * 321 zůstal haléřový zbytek a doklad ukazoval „Zbývá uhradit −0,91".
 *
 * Dávka vezme tytéž úhrady, normalizuje je TÍMŽ guardem a zápis úhrady přepíše na místě
 * k jeho datu ({@see BankPostingService::repostMatchedInPlace()}). Zamčené datum
 * (podané DPH) nevadí, dokud jde jen o haléřové dorovnání 321 ↔ 548/648 a přiznání
 * k dani z příjmů za rok ještě podané není — hlídá to PostingService pod zámkem.
 * Období, které není otevřené (uzávěrka, schválený rok), se nepřepisuje; řádek se jen
 * vypíše jako `period_not_open`.
 *
 * Idempotentní: po srovnání alokace ≠ částka pohybu, takže ji předfiltr znovu nenabídne.
 * Běží jako auto-backfill v migrate.php a ručně přes
 * api/bin/purchase-rounding-settlement-backfill.php (default dry-run).
 */
final class PurchaseRoundingSettlementBackfill
{
    public function __construct(
        private readonly Connection $db,
        private readonly BankPostingService $bankPosting,
    ) {}

    /** Počet úhrad, které by ostrý běh opravdu dorovnal (napříč firmami). */
    public function pending(): int
    {
        return $this->run(null, false)['fixed'];
    }

    /**
     * @return array{dry_run:bool, candidates:int, fixed:int,
     *   rows:list<array{supplier_id:int, tx_id:int, purchase_invoice_id:int, entry_id:int, entry_date:string,
     *                   amount_to_pay:float, rounding:float, paid:float, status:string, message?:string}>}
     */
    public function run(?int $supplierId, bool $apply, ?int $userId = null): array
    {
        $rows = $this->candidates($supplierId);
        $report = ['dry_run' => !$apply, 'candidates' => count($rows), 'fixed' => 0, 'rows' => []];
        $pdo = $this->db->pdo();

        foreach ($rows as $r) {
            $row = [
                'supplier_id'         => (int) $r['supplier_id'],
                'tx_id'               => (int) $r['tx_id'],
                'purchase_invoice_id' => (int) $r['purchase_invoice_id'],
                'entry_id'            => (int) $r['entry_id'],
                'entry_date'          => (string) $r['entry_date'],
                'amount_to_pay'       => (float) $r['amount_to_pay'],
                'rounding'            => (float) $r['rounding'],
                'paid'                => abs((float) $r['tx_amount']),
                'status'              => '',
            ];
            if ((string) $r['period_status'] !== 'open') {
                $row['status'] = 'period_not_open';
                $report['rows'][] = $row;
                continue;
            }

            $ownTx = !$pdo->inTransaction();
            $savepoint = 'prsb_' . $row['tx_id'];
            $ownTx ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT ' . $savepoint);
            $commit = false;
            try {
                if (!$this->bankPosting->normalizeRoundingFullPurchase($row['supplier_id'], $row['tx_id'])) {
                    $row['status'] = 'not_full_payment';
                } else {
                    $this->bankPosting->repostMatchedInPlace($row['supplier_id'], $row['tx_id'], $userId);
                    $row['status'] = $apply ? 'fixed' : 'would_fix';
                    $report['fixed']++;
                    $commit = $apply;
                }
            } catch (PostingException $e) {
                $row['status'] = 'error:' . $e->errorCode;
                $row['message'] = $e->getMessage();
            }
            if ($ownTx) {
                $commit ? $pdo->commit() : $pdo->rollBack();
            } else {
                if (!$commit) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                }
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            $report['rows'][] = $row;
        }

        return $report;
    }

    /**
     * Předfiltr: jediná alokace úhrady na přijatou fakturu, alokace drží částku POHYBU
     * a ta se od nominálu dokladu liší o haléře (do 1 Kč), úhrada má živý zápis.
     * Rozhoduje až guard služby; tady jde jen o to, koho mu nabídnout.
     *
     * @return list<array<string,mixed>>
     */
    private function candidates(?int $supplierId): array
    {
        $sql = "SELECT pm.supplier_id, bt.id AS tx_id, pm.purchase_invoice_id, bt.amount AS tx_amount,
                       pi.amount_to_pay, pi.rounding, je.id AS entry_id, je.entry_date, p.status AS period_status
                  FROM payment_matches pm
                  JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                  JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id AND pi.supplier_id = pm.supplier_id
                  JOIN journal_entries je ON je.supplier_id = pm.supplier_id AND je.source_type = 'bank'
                                         AND je.source_id = bt.id AND je.reversed_by IS NULL
                  JOIN accounting_periods p ON p.id = je.period_id AND p.supplier_id = je.supplier_id
                 WHERE bt.source = 'statement'
                   AND pm.invoice_id IS NULL
                   AND ABS(pm.amount - ABS(bt.amount)) < 0.005
                   AND ABS(pm.amount - ABS(pi.amount_to_pay)) >= 0.005
                   AND ABS(ABS(bt.amount) - ABS(pi.amount_to_pay)) <= ?
                   AND (SELECT COUNT(*) FROM payment_matches all_pm WHERE all_pm.bank_transaction_id = bt.id) = 1";
        $params = [FxPaymentSettlement::AMOUNT_TOLERANCE];
        if ($supplierId !== null) {
            $sql .= ' AND pm.supplier_id = ?';
            $params[] = $supplierId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY pm.supplier_id, bt.posted_at, bt.id');
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
