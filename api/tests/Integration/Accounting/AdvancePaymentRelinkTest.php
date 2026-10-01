<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Service\Accounting\AdvancePaymentRelink;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Oprava dat „rozpárované" zálohy (api/bin/fix-advance-payment-on-final.php): platba zálohy
 * spárovaná s konečnou fakturou, záloha ručně „uhrazená evidenčně" bez platby.
 */
#[Group('integration')]
final class AdvancePaymentRelinkTest extends BankPostingTestCase
{
    private int $vatRateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vatRateId = (int) ($this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            self::markTestSkipped('Chybí vat_rates v DB.');
        }
    }

    public function testRelinksPaymentToAdvanceAndSettlesFinal(): void
    {
        [$advance, $final, $tx] = $this->brokenPair('REL-1');
        $bankBefore = $this->linesByAccountCode($this->liveEntry('bank', $tx));
        self::assertSame(121000, self::cents($bankBefore['321']['debit'] ?? 0), 'Výchozí stav: platba na konečné faktuře 321/221.');

        $relink = $this->container->get(AdvancePaymentRelink::class);
        $dry = $this->rowFor($relink->run($this->supplierId, false), $final);
        self::assertSame('would_fix', $dry['status']);
        self::assertSame($tx, $dry['tx_id']);
        self::assertSame(121000, self::cents($this->linesByAccountCode($this->liveEntry('bank', $tx))['321']['debit'] ?? 0),
            'Dry-run nic nemění.');

        $done = $this->rowFor($relink->run($this->supplierId, true, $this->userId), $final);
        self::assertSame('fixed', $done['status'], (string) ($done['message'] ?? ''));

        $bank = $this->linesByAccountCode($this->liveEntry('bank', $tx));
        self::assertSame(121000, self::cents($bank['314']['debit'] ?? 0), 'Banka přeúčtovaná na 314/221.');
        self::assertArrayNotHasKey('321', $bank);
        $fin = $this->linesByAccountCode($this->liveEntry('purchase_invoice', $final));
        self::assertSame(121000, self::cents($fin['321']['debit'] ?? 0), 'Konečná faktura zúčtovala zálohu 321 MD.');
        self::assertSame(121000, self::cents($fin['314']['credit'] ?? 0), '314 D.');
        self::assertSame(0, self::cents($this->balance('314')));
        self::assertSame(0, self::cents($this->balance('321')));

        $adv = $this->db->pdo()->query("SELECT status, paid_at FROM purchase_invoices WHERE id = {$advance}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('paid', $adv['status']);
        self::assertSame(self::YEAR . '-06-24', substr((string) $adv['paid_at'], 0, 10), 'Záloha uhrazená k datu platby, ne ručně.');
        self::assertSame(1, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM payment_matches WHERE purchase_invoice_id = {$advance} AND bank_transaction_id = {$tx}"
        )->fetchColumn());

        self::assertNull($this->rowFor($relink->run($this->supplierId, true, $this->userId), $final, false),
            'Opravená dvojice už není kandidát (idempotence).');
    }

    /** Faktura zálohou krytá jen zčásti: platba může být legitimní doplatek — jen report. */
    public function testPartiallyCoveredFinalIsReportOnly(): void
    {
        [, $final, $tx] = $this->brokenPair('REL-3');
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET total_with_vat = 3000.00 WHERE id = ?')->execute([$final]);

        $row = $this->rowFor($this->container->get(AdvancePaymentRelink::class)->run($this->supplierId, true, $this->userId), $final);
        self::assertSame('not_fully_covered', $row['status']);
        self::assertSame(121000, self::cents($this->linesByAccountCode($this->liveEntry('bank', $tx))['321']['debit'] ?? 0));
    }

    /** Záloha uhrazená zápočtem není „bez úhrady" — CLI ji nesmí brát jako kandidáta. */
    public function testAdvanceSettledByOffsetIsNotCandidate(): void
    {
        [$advance, $final] = $this->brokenPair('REL-4');
        $account = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '365' LIMIT 1"
        )->fetchColumn();
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_settlements (supplier_id, doc_type, doc_id, settled_on, amount, account_id, status)
             VALUES (?, 'purchase_invoice', ?, ?, 1210.00, ?, 'confirmed')"
        )->execute([$this->supplierId, $advance, self::YEAR . '-06-21', $account]);

        self::assertNull($this->rowFor($this->container->get(AdvancePaymentRelink::class)->run($this->supplierId, false), $final, false));
    }

    public function testLockedPeriodIsReportOnly(): void
    {
        [, $final, $tx] = $this->brokenPair('REL-2');
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)'
        )->execute([$this->supplierId, self::YEAR . '-06-30']);

        $row = $this->rowFor($this->container->get(AdvancePaymentRelink::class)->run($this->supplierId, true, $this->userId), $final);
        self::assertSame('date_locked', $row['status']);
        self::assertSame(121000, self::cents($this->linesByAccountCode($this->liveEntry('bank', $tx))['321']['debit'] ?? 0),
            'V zamčeném období se nic nemění.');
    }

    /**
     * Stav po ručním „obejití": záloha uhrazená evidenčně, platba na konečné faktuře,
     * konečná faktura zaúčtovaná bez zúčtování zálohy.
     *
     * @return array{0:int,1:int,2:int}
     */
    private function brokenPair(string $suffix): array
    {
        $vendor = $this->client('Dodavatel ' . $suffix);
        $advance = $this->purchaseInvoice('ZPF-' . $suffix, $vendor, 1210.00, 'advance');
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?")
            ->execute([self::YEAR . '-07-01', $advance]);

        $issue = self::YEAR . '-06-20';
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, advance_purchase_invoice_id,
                 advance_paid_amount, vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, reverse_charge,
                 is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", "invoice", ?, 1210.00, "full", ?, ?, ?, ?, ?, 0, 0, 1000.00, 210.00, 1210.00, "received", "40", ?)'
        )->execute([$this->supplierId, $vendor, 'PF-' . $suffix, $advance, $issue, $issue, $issue, $issue, $this->currencyId, $this->userId]);
        $final = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Služba', 1, 'ks', 1000.00, ?, 21.00, 1000.00, 210.00, 1210.00, 0)"
        )->execute([$final, $this->vatRateId]);
        $this->container->get(DocumentAutoPoster::class)->post(
            $this->supplierId, 'purchase_invoice', $final, ['user_id' => $this->userId], $this->userId,
        );

        $tx = $this->transaction($this->statement(), -1210.00, ['match_status' => 'manual', 'posted_at' => self::YEAR . '-06-24']);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET card_last4 = '4242' WHERE id = ?")->execute([$tx]);
        $this->paymentMatch($tx, $final, 1210.00);
        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);
        return [$advance, $final, $tx];
    }

    /**
     * @param array{rows:list<array<string,mixed>>} $result
     * @return array<string,mixed>|null
     */
    private function rowFor(array $result, int $finalId, bool $required = true): ?array
    {
        foreach ($result['rows'] as $row) {
            if ((int) $row['final_id'] === $finalId) {
                return $row;
            }
        }
        if ($required) {
            self::fail('Dvojice s konečnou fakturou #' . $finalId . ' není mezi kandidáty.');
        }
        return null;
    }

    private function liveEntry(string $sourceType, int $sourceId): int
    {
        $id = (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE supplier_id = {$this->supplierId} AND source_type = '{$sourceType}'
                AND source_id = {$sourceId} AND reversed_by IS NULL AND posted_at IS NOT NULL ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        self::assertGreaterThan(0, $id, "Chybí živý zápis {$sourceType} #{$sourceId}.");
        return $id;
    }

    private function balance(string $code): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts c ON c.id = l.account_id
              WHERE e.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ? AND c.account_code LIKE ?"
        );
        $stmt->execute([$this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31', $code . '%']);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round((float) $amount * 100.0);
    }
}
