<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Bank\BankTransactionReleaseService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Zúčtování poskytnuté zálohy 321/314 na konečné přijaté faktuře musí sledovat SKUTEČNĚ
 * zaúčtovanou úhradu zálohy i tehdy, když se úhrada zaúčtuje, zruší nebo přepáruje AŽ PO
 * zaúčtování konečné faktury.
 *
 * Reálný průběh, ze kterého test vychází (syntetická data): zálohová faktura zaplacená
 * kartou, konečná faktura navázaná na zálohu, platba několikrát odpárovaná a znovu
 * spárovaná. Zúčtování 321/314 se počítá jen při zaúčtování konečné faktury, takže každá
 * pozdější změna úhrady nechala 314 rozjeté proti 321 a účetní to „spravila" přesunem
 * platby na konečnou fakturu a ruční úhradou zálohy.
 */
#[Group('integration')]
final class PurchaseAdvanceSettlementSyncTest extends BankPostingTestCase
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

    /** Pořadí z praxe: konečná faktura zaúčtovaná dřív, než se spáruje platba zálohy kartou. */
    public function testFinalPostedBeforeAdvancePaymentGetsSettlementWhenPaymentPosts(): void
    {
        $vendor  = $this->client('Dodavatel záloha karta');
        $advance = $this->purchaseInvoice('ZPF-SYNC-1', $vendor, 1210.00, 'advance');
        $final   = $this->finalPurchase('PF-SYNC-1', $vendor, 1000.00, 210.00, $advance);
        $finalEntry = $this->postFinal($final);
        self::assertSame(0, self::cents($this->linesByAccountCode($finalEntry)['314']['credit'] ?? 0),
            'Bez zaúčtované úhrady zálohy se zúčtování 321/314 nezapisuje.');

        $tx = $this->payAdvanceByCard($advance, 1210.00);

        $bank = $this->linesByAccountCode($this->liveEntry('bank', $tx));
        self::assertSame(121000, self::cents($bank['314']['debit'] ?? 0), 'Platba zálohy kartou jde na 314.');
        $byAcc = $this->linesByAccountCode($this->liveEntry('purchase_invoice', $final));
        self::assertSame(121000, self::cents($byAcc['321']['debit'] ?? 0), 'Konečná faktura dostane zúčtování 321 MD.');
        self::assertSame(121000, self::cents($byAcc['314']['credit'] ?? 0), 'A 314 D.');
        self::assertSame($finalEntry, $this->liveEntry('purchase_invoice', $final), 'Zápis se přepíše na místě.');
        $this->assertAdvanceCycleClosed();
    }

    /** Odpárování a znovuspárování platby zálohy po zaúčtování konečné faktury. */
    public function testUnmatchAndRematchOfAdvancePaymentKeepsFinalSettlementInSync(): void
    {
        $vendor  = $this->client('Dodavatel záloha přepárování');
        $advance = $this->purchaseInvoice('ZPF-SYNC-2', $vendor, 1210.00, 'advance');
        $tx = $this->payAdvanceByCard($advance, 1210.00);
        $final = $this->finalPurchase('PF-SYNC-2', $vendor, 1000.00, 210.00, $advance);
        $this->postFinal($final);
        $this->assertAdvanceCycleClosed();

        $this->container->get(BankTransactionReleaseService::class)
            ->release($this->supplierId, $tx, BankTransactionReleaseService::MODE_UNMATCH, $this->userId);

        $byAcc = $this->linesByAccountCode($this->liveEntry('purchase_invoice', $final));
        self::assertSame(0, self::cents($byAcc['314']['credit'] ?? 0),
            'Po zrušení úhrady zálohy nesmí konečná faktura dál čerpat 314.');
        self::assertSame(0, self::cents($this->balance('314')), '314 bez úhrady i bez zúčtování.');
        self::assertSame(-121000, self::cents($this->balance('321')), 'Závazek z konečné faktury je otevřený.');

        $this->db->pdo()->prepare("UPDATE bank_transactions SET match_status = 'manual' WHERE id = ?")->execute([$tx]);
        $this->paymentMatch($tx, $advance, 1210.00);
        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);

        $byAcc = $this->linesByAccountCode($this->liveEntry('purchase_invoice', $final));
        self::assertSame(121000, self::cents($byAcc['314']['credit'] ?? 0), 'Znovu spárovaná úhrada → zúčtování zpět.');
        $this->assertAdvanceCycleClosed();
    }

    /** Ruční přeúčtování konečné faktury (jiný nákladový účet) synchronizace nepřepíše. */
    public function testSettlementSyncKeepsManualRepostOfFinalInvoice(): void
    {
        $vendor  = $this->client('Dodavatel ruční kontace');
        $advance = $this->purchaseInvoice('ZPF-SYNC-3', $vendor, 1210.00, 'advance');
        $final   = $this->finalPurchase('PF-SYNC-3', $vendor, 1000.00, 210.00, $advance);
        $entry   = $this->postFinal($final);
        $this->db->pdo()->prepare(
            "UPDATE journal_entry_lines jel
               JOIN chart_of_accounts c ON c.id = jel.account_id
                SET jel.account_id = (SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = '501' LIMIT 1)
              WHERE jel.entry_id = ? AND c.account_code LIKE '518%'"
        )->execute([$this->supplierId, $entry]);

        $this->payAdvanceByCard($advance, 1210.00);

        $byAcc = $this->linesByAccountCode($this->liveEntry('purchase_invoice', $final));
        self::assertSame(100000, self::cents($byAcc['501']['debit'] ?? 0), 'Ručně opravený nákladový účet zůstává.');
        self::assertArrayNotHasKey('518', $byAcc);
        self::assertSame(121000, self::cents($byAcc['314']['credit'] ?? 0));
        $this->assertAdvanceCycleClosed();
    }

    /**
     * Dorovnání mění JEN řádky páru zúčtování. Dřív se přepsal celý zápis: ruční dimenze
     * řádků zmizely (vazby odešly kaskádou s řádky) a řádky rozdělené podle položek se
     * při dalším průchodu dělily znovu. Ostatní řádky musí zůstat bajtově stejné i s id.
     */
    public function testSyncKeepsLineDimensionsSplitsAndPostedAt(): void
    {
        $dimensions = $this->container->get(DimensionService::class);
        $assignments = $this->container->get(DimensionAssignmentRepository::class);
        $dimensions->setEnabled($this->supplierId, true);
        $types = $dimensions->ensureDefaultTypes($this->supplierId, ['projekt', 'stredisko']);
        $value = fn (int $type, string $code): int => (int) $dimensions->createValue(
            $this->supplierId, $type, ['code' => $code, 'name' => 'Hodnota ' . $code, 'parent_id' => null],
        )['id'];
        $projectA = $value($types['project'], 'ZAL-A');
        $projectB = $value($types['project'], 'ZAL-B');
        $center = $value($types['cost_center'], 'ZAL-S');

        $vendor  = $this->client('Dodavatel dimenze');
        $advance = $this->purchaseInvoice('ZPF-SYNC-6', $vendor, 1210.00, 'advance');
        $final   = $this->finalPurchase('PF-SYNC-6', $vendor, 1000.00, 210.00, $advance, [[600.00, 126.00], [400.00, 84.00]]);
        $dimensions->saveDocument($this->supplierId, 'purchase_invoice', $final, [], [
            1 => [$types['project'] => $projectA],
            2 => [$types['project'] => $projectB],
        ]);
        $entry = $this->postFinal($final);
        // Ruční dimenze jednoho řádku (účetní ji doplnila v deníku).
        $vatLine = (int) $this->db->pdo()->query(
            "SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts c ON c.id = l.account_id
              WHERE l.entry_id = {$entry} AND c.account_code LIKE '343%' LIMIT 1"
        )->fetchColumn();
        $this->db->pdo()->prepare('DELETE FROM journal_entry_line_dimensions WHERE line_id = ?')->execute([$vatLine]);
        $assignments->insertLineDimensions($this->supplierId, $vatLine, [$types['cost_center'] => $center]);

        $before = $assignments->entryLineDimensions($this->supplierId, $entry);
        $linesBefore = $this->journal->linesForEntry($entry, $this->supplierId);
        $header = $this->db->pdo()->query("SELECT posted_at, posted_by FROM journal_entries WHERE id = {$entry}")->fetch(\PDO::FETCH_ASSOC);

        $this->payAdvanceByCard($advance, 1210.00);

        $after = $assignments->entryLineDimensions($this->supplierId, $entry);
        $linesAfter = $this->journal->linesForEntry($entry, $this->supplierId);
        foreach ($linesBefore as $line) {
            $id = (int) $line['id'];
            self::assertArrayHasKey($id, array_column($linesAfter, null, 'id'), "Řádek #{$id} zůstal.");
            self::assertSame($before[$id] ?? [], $after[$id] ?? [], "Dimenze řádku #{$id} beze změny.");
        }
        self::assertSame([$types['cost_center'] => $center], $after[$vatLine] ?? [], 'Ruční dimenze řádku přežila.');
        self::assertCount(count($linesBefore) + 2, $linesAfter, 'Přibyl jen pár 321/314, nákladové řádky se nedělily znovu.');
        self::assertSame(121000, self::cents($this->linesByAccountCode($entry)['314']['credit'] ?? 0));
        self::assertSame($header, $this->db->pdo()->query("SELECT posted_at, posted_by FROM journal_entries WHERE id = {$entry}")->fetch(\PDO::FETCH_ASSOC),
            'posted_at / posted_by zápisu konečné faktury zůstávají.');
        $this->assertAdvanceCycleClosed();
    }

    /**
     * Úhrada zálohy z jiného roku než konečná faktura se do jejího zápisu zpětně nedopíše
     * (rozvaha roku faktury by čerpala zálohu, která tehdy zaplacená nebyla) — zaloguje se.
     */
    public function testPaymentInLaterYearIsNotBackdatedIntoFinal(): void
    {
        $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $vendor  = $this->client('Dodavatel přelom roku');
        $advance = $this->purchaseInvoice('ZPF-SYNC-7', $vendor, 1210.00, 'advance');
        $final   = $this->finalPurchase('PF-SYNC-7', $vendor, 1000.00, 210.00, $advance);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET issue_date = ?, tax_date = ?, received_at = ? WHERE id = ?')
            ->execute([self::YEAR . '-12-20', self::YEAR . '-12-20', self::YEAR . '-12-20', $final]);
        $entry = $this->postFinal($final);

        $this->payAdvanceByCard($advance, 1210.00, (self::YEAR + 1) . '-01-05');

        self::assertSame(0, self::cents($this->linesByAccountCode($entry)['314']['credit'] ?? 0),
            'Zápis prosincové faktury nesmí čerpat lednovou úhradu.');
        $reason = $this->db->pdo()->query(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(payload, '$.reason')) FROM activity_log
              WHERE action = 'accounting.advance_settlement_stale' AND entity_type = 'purchase_invoice'
                AND entity_id = {$final} ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        self::assertSame('payment_in_other_year', $reason);
    }

    /** Souběžná změna zápisu mezi čtením a zápisem → version_conflict, nic se nepřepíše. */
    public function testReplaceEntryLinesRejectsStaleRowVersion(): void
    {
        $vendor  = $this->client('Dodavatel souběh');
        $advance = $this->purchaseInvoice('ZPF-SYNC-8', $vendor, 1210.00, 'advance');
        $final   = $this->finalPurchase('PF-SYNC-8', $vendor, 1000.00, 210.00, $advance);
        $entry   = $this->postFinal($final);
        $version = (int) $this->db->pdo()->query("SELECT row_version FROM journal_entries WHERE id = {$entry}")->fetchColumn();

        try {
            $this->posting->replaceEntryLines($this->supplierId, 'purchase_invoice', $final, $version - 1, [], [
                ['account_code' => '321', 'side' => 'debit', 'amount' => 10.00],
                ['account_code' => '314', 'side' => 'credit', 'amount' => 10.00],
            ]);
            self::fail('Zastaralá row_version musí skončit version_conflict.');
        } catch (PostingException $e) {
            self::assertSame('version_conflict', $e->errorCode);
        }
        self::assertArrayNotHasKey('314', $this->linesByAccountCode($entry));
    }

    /** Totéž pro hotovostní úhradu zálohy a její storno (zrcadlo bankovní větve). */
    public function testCashPaymentOfAdvanceAndItsReversalKeepFinalSettlementInSync(): void
    {
        $register = $this->container->get(CashRegisterService::class)->create(
            $this->supplierId,
            ['name' => 'Pokladna záloha', 'account_code' => '211', 'is_default' => false],
        );
        $vendor  = $this->client('Dodavatel záloha hotově');
        $advance = $this->purchaseInvoice('ZPF-SYNC-4', $vendor, 1210.00, 'advance');
        $final   = $this->finalPurchase('PF-SYNC-4', $vendor, 1000.00, 210.00, $advance);
        $this->postFinal($final);

        $cash = $this->container->get(CashDocumentService::class);
        $doc = $cash->create($this->supplierId, [
            'register_id' => $register, 'issue_date' => self::YEAR . '-06-24', 'description' => 'Úhrada zálohy',
            'purpose' => 'purchase_payment', 'doc_type' => 'out', 'total_amount' => 1210.00,
            'purchase_invoice_id' => $advance, 'post' => true,
        ], $this->userId);

        $byAcc = $this->linesByAccountCode($this->liveEntry('purchase_invoice', $final));
        self::assertSame(121000, self::cents($byAcc['314']['credit'] ?? 0), 'Hotovostní úhrada zálohy → zúčtování 321/314.');
        $this->assertAdvanceCycleClosed();

        $cash->reverse($this->supplierId, (int) $doc['id'], ['reason' => 'Chybná úhrada'], $this->userId);
        $byAcc = $this->linesByAccountCode($this->liveEntry('purchase_invoice', $final));
        self::assertSame(0, self::cents($byAcc['314']['credit'] ?? 0), 'Storno úhrady → zúčtování pryč.');
        self::assertSame(0, self::cents($this->balance('314')));
    }

    /**
     * Vydaná strana: vyúčtovací faktura navázaná na proformu a zaúčtovaná dřív, než přišla
     * platba proformy. Zúčtování přijaté zálohy 324/311 se musí doplnit i odebrat stejně.
     */
    public function testProformaPaidAfterFinalPostedGetsSettlementOnIssuedSide(): void
    {
        $client   = $this->client('Odběratel záloha později');
        $proforma = $this->sale('PRO-SYNC-5', $client, 1000.00, 210.00, 'proforma');
        $final    = $this->sale('FV-SYNC-5', $client, 1000.00, 210.00, 'invoice', $proforma);
        $finalEntry = $this->posting->postDocument($this->supplierId, 'invoice', $final,
            $this->posting->buildFromInvoice($this->supplierId, $final), ['entry_date' => self::YEAR . '-06-20']);
        self::assertSame(0, self::cents($this->linesByAccountCode($finalEntry)['324']['debit'] ?? 0));

        $tx = $this->transaction($this->statement(), 1210.00, [
            'match_status' => 'auto_exact', 'matched_invoice_id' => $proforma, 'posted_at' => self::YEAR . '-06-24',
        ]);
        $this->invoicePayment($proforma, $tx, 1210.00);
        self::assertSame('posted', $this->service->handleTransaction($tx, $this->userId)['action']);

        $byAcc = $this->linesByAccountCode($this->liveEntry('invoice', $final));
        self::assertSame(121000, self::cents($byAcc['324']['debit'] ?? 0), 'Zúčtování přijaté zálohy 324 MD.');
        self::assertSame(121000, self::cents($byAcc['311']['credit'] ?? 0), '311 D.');
        self::assertSame(0, self::cents($this->balance('324')));
        self::assertSame(0, self::cents($this->balance('311')));

        $this->container->get(BankTransactionReleaseService::class)
            ->release($this->supplierId, $tx, BankTransactionReleaseService::MODE_UNMATCH, $this->userId);
        $byAcc = $this->linesByAccountCode($this->liveEntry('invoice', $final));
        self::assertSame(0, self::cents($byAcc['324']['debit'] ?? 0), 'Zrušená platba proformy → bez zúčtování.');
        self::assertSame(0, self::cents($this->balance('324')));
        self::assertSame(121000, self::cents($this->balance('311')), 'Pohledávka z faktury je zase otevřená.');
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    private function sale(string $vs, int $clientId, float $base, float $vat, string $type, ?int $parentId = null): int
    {
        $with  = $base + $vat;
        $issue = self::YEAR . '-06-15';
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, parent_invoice_id, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, prices_include_vat, total_without_vat, total_vat, total_with_vat,
                 paid_total, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, 0, "issued", "1", ?)'
        )->execute([$this->supplierId, $vs, $type, $parentId, $clientId, $issue, $issue, $issue,
            $this->currencyId, $base, $vat, $with, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Dílo', 1, 'ks', ?, ?, 21.00, ?, ?, ?, 0)"
        )->execute([$id, $base, $this->vatRateId, $base, $vat, $with]);
        return $id;
    }

    /** @param list<array{0:float,1:float}>|null $items základ a DPH položek (default jedna položka) */
    private function finalPurchase(string $number, int $vendorId, float $base, float $vat, int $advanceId, ?array $items = null): int
    {
        $with  = $base + $vat;
        $issue = self::YEAR . '-06-20';
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, advance_purchase_invoice_id,
                 advance_paid_amount, vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, reverse_charge,
                 is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", "invoice", ?, ?, "full", ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $advanceId, $with, $issue, $issue, $issue, $issue,
            $this->currencyId, $base, $vat, $with, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        foreach ($items ?? [[$base, $vat]] as $i => [$itemBase, $itemVat]) {
            $this->db->pdo()->prepare(
                "INSERT INTO purchase_invoice_items
                    (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                     vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
                 VALUES (?, 'Služba', 1, 'ks', ?, ?, 21.00, ?, ?, ?, ?)"
            )->execute([$id, $itemBase, $this->vatRateId, $itemBase, $itemVat, $itemBase + $itemVat, $i]);
        }
        return $id;
    }

    private function postFinal(int $finalId): int
    {
        return $this->container->get(DocumentAutoPoster::class)->post(
            $this->supplierId,
            'purchase_invoice',
            $finalId,
            ['user_id' => $this->userId, 'posted_by' => $this->userId],
            $this->userId,
        );
    }

    private function payAdvanceByCard(int $advanceId, float $amount, ?string $date = null): int
    {
        $tx = $this->transaction($this->statement(), -$amount, [
            'match_status' => 'manual',
            'posted_at'    => $date ?? self::YEAR . '-06-24',
            'description'  => 'Platba kartou',
        ]);
        $this->db->pdo()->prepare("UPDATE bank_transactions SET card_last4 = '4242' WHERE id = ?")->execute([$tx]);
        $this->paymentMatch($tx, $advanceId, $amount);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], 'Úhrada zálohy se má zaúčtovat: ' . ($res['reason'] ?? ''));
        return $tx;
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

    /** Σ (MD − D) na účtu (i analytikách) přes všechny zápisy testovacího roku. */
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

    private function assertAdvanceCycleClosed(): void
    {
        self::assertSame(0, self::cents($this->balance('314')), '314 po zúčtování zálohy na nule.');
        self::assertSame(0, self::cents($this->balance('321')), '321 po zúčtování zálohy na nule.');
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round((float) $amount * 100.0);
    }
}
