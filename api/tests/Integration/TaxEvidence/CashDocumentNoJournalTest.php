<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingException;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Epic DE §6 (R6) — no-journal post path v CashDocumentService gatovaná na
 * supplier.accounting_mode='tax_evidence'. Ověřuje, že:
 *   (a) v tax_evidence je posted PPD/VPD journal-free (journal_entry_id NULL) a
 *       nevyžaduje otevřené účetní období ani posting engine,
 *   (b) v double_entry se doklad DÁL účtuje do journalu a vyžaduje otevřené období
 *       (regrese — chování beze změny),
 *   (c) storno v tax_evidence funguje bez posting enginu (reversal_entry_id NULL),
 *   (d) storno/idempotence se řídí ULOŽENÝM tvarem dokladu (journal_entry_id/status),
 *       ne aktuálním režimem firmy — odolné vůči přepnutí accounting_mode kdykoli,
 *   (e) storno úhrady PF v tax_evidence vrací PF na 'received' (ne 'booked').
 *
 * tax_evidence supplier NEMÁ seedovanou osnovu (COA) — ověřuje reálnou dosažitelnost
 * no-journal cesty (pokladna i doklad bez jediného účtu v chart_of_accounts).
 * Používá VÝHRADNĚ dva throwaway suppliery (jeden per režim), aby se nedotklo
 * reálných dat supplieru 1 ani demo-dat pokladen. Vše v transakci → rollback.
 */
#[Group('integration')]
final class CashDocumentNoJournalTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private CashDocumentService $service;
    private CashRegisterService $registers;
    private AccountingPeriodRepository $periods;

    private int $currencyId = 0;
    private int $userId = 0;
    private int $countryId = 0;

    private int $teSupplierId = 0;
    private int $deSupplierId = 0;
    private int $dePeriodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db        = $container->get(Connection::class);
            $this->service   = $container->get(CashDocumentService::class);
            $this->registers = $container->get(CashRegisterService::class);
            $this->periods   = $container->get(AccountingPeriodRepository::class);
            $seeder          = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->countryId  = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId        = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->currencyId === 0 || $this->userId === 0 || $this->countryId === 0 || $vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data (currency/user/country/vat_rate) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->teSupplierId = $this->makeSupplier('tax_evidence', $vatRateId);
        $this->deSupplierId = $this->makeSupplier('double_entry', $vatRateId);
        // COA se seeduje JEN pro double_entry — tax_evidence tenant osnovu nemá (R6).
        // Tím se ověří reálná dosažitelnost no-journal cesty: pokladnu i doklad musí
        // jít pořídit bez jediného účtu v chart_of_accounts.
        $seeder->seedForSupplier($this->deSupplierId);
        // Otevřené období jen pro double_entry (v DE období neexistují, R14).
        $this->dePeriodId = $this->periods->create($this->deSupplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    /** (a) tax_evidence: posted doklad je journal-free a nevyžaduje otevřené období. */
    public function testTaxEvidencePostHasNoJournalAndNeedsNoPeriod(): void
    {
        // Žádné accounting_period pro tento supplier neexistuje → posting engine by
        // v double_entry hodil chybu; v tax_evidence musí projít.
        self::assertSame(0, $this->periodCount($this->teSupplierId), 'Předpoklad: DE supplier nemá žádné období.');

        $reg = $this->registers->create($this->teSupplierId, ['name' => 'Pokladna', 'account_code' => '211', 'is_default' => true]);
        $res = $this->service->create($this->teSupplierId, $this->sale($reg, 1500.00), $this->userId);

        self::assertSame('posted', $res['status']);
        self::assertNull($res['journal_entry_id'], 'Posted doklad v DE musí mít journal_entry_id NULL.');
        self::assertNotNull($res['doc_number'], 'Doklad dostane číslo řady i bez journalu.');

        // DB: journal_entry_id NULL a žádný journal_entries záznam pro doklad.
        self::assertNull($this->docJournalId($res['id']));
        self::assertSame(0, $this->journalEntryCountFor($this->teSupplierId, $res['id']), 'V DE nevzniká žádný zápis v deníku.');
    }

    public function testTaxEvidenceNegativeCashBalanceUsesCashDocuments(): void
    {
        $reg = $this->registers->create($this->teSupplierId, [
            'name' => 'Pokladna EP8', 'account_code' => '211', 'is_default' => true,
        ]);
        $out = $this->service->create($this->teSupplierId, [
            'register_id' => $reg,
            'issue_date' => self::YEAR . '-06-15',
            'description' => 'Výdaj bez předchozího příjmu',
            'purpose' => 'purchase',
            'doc_type' => 'out',
            'total_amount' => 500.00,
            'post' => true,
        ], $this->userId);

        self::assertContains('cash.warning.negative_balance', $out['warnings']);
        self::assertEqualsWithDelta(
            -500.00,
            $this->registers->documentsSignedTotal($this->teSupplierId, $reg, self::YEAR . '-06-15'),
            0.001,
        );
    }

    /** (b) REGRESE: double_entry se DÁL účtuje do journalu a vyžaduje otevřené období. */
    public function testDoubleEntryStillPostsToJournalAndRequiresOpenPeriod(): void
    {
        $reg = $this->registers->create($this->deSupplierId, ['name' => 'Pokladna', 'account_code' => '211', 'is_default' => true]);
        $res = $this->service->create($this->deSupplierId, $this->sale($reg, 1500.00), $this->userId);

        self::assertSame('posted', $res['status']);
        self::assertNotNull($res['journal_entry_id'], 'Double_entry MUSÍ účtovat do journalu (beze změny).');
        self::assertSame(1, $this->journalEntryCountFor($this->deSupplierId, $res['id']), 'Právě jeden zápis pro doklad.');
        // Kontace 211 MD = brutto (byte-stabilní posting §3.4).
        $byAcc = $this->linesByAccountCode($this->deSupplierId, (int) $res['journal_entry_id']);
        self::assertEqualsWithDelta(1500.00, $byAcc['211']['debit'], 0.001);

        // Zavřené období → další post musí selhat posting enginem (beze změny).
        $this->periods->setStatus($this->dePeriodId, $this->deSupplierId, 'closed');
        $this->expectException(PostingException::class);
        $this->service->create($this->deSupplierId, $this->sale($reg, 600.00), $this->userId);
    }

    /** (c) tax_evidence: storno funguje bez posting enginu (reversal_entry_id NULL). */
    public function testTaxEvidenceReverseWithoutPostingEngine(): void
    {
        $reg = $this->registers->create($this->teSupplierId, ['name' => 'Pokladna', 'account_code' => '211', 'is_default' => true]);
        $posted = $this->service->create($this->teSupplierId, $this->sale($reg, 800.00), $this->userId);

        $rev = $this->service->reverse(
            $this->teSupplierId,
            $posted['id'],
            ['reason' => 'Chybný doklad', 'entry_date' => self::YEAR . '-06-30'],
            $this->userId,
        );

        self::assertNull($rev['reversal_entry_id'], 'Storno v DE nemá protizápis.');
        self::assertSame('reversed', $this->docStatus($posted['id']));
        // Žádný protizápis v deníku (ani původní, ani reversal).
        self::assertSame(0, $this->journalEntryCountFor($this->teSupplierId, $posted['id']));

        // Storno storna → chyba (doklad už není posted).
        try {
            $this->service->reverse($this->teSupplierId, $posted['id'], ['reason' => 'Znovu'], $this->userId);
            self::fail('Storno storna musí selhat.');
        } catch (\MyInvoice\Service\Accounting\Cash\CashException $e) {
            self::assertSame('doc_not_posted', $e->errorCode);
        }
    }

    /**
     * (HIGH) Storno se řídí ULOŽENÝM tvarem dokladu (journal_entry_id), NE aktuálním
     * režimem firmy. Doklad zaúčtovaný v double_entry (journal) → přepnutí firmy na
     * tax_evidence → storno MUSÍ vytvořit protizápis (reversal_entry_id != NULL),
     * ne tichý no-journal storno, jinak zůstane živý zápis 211 = rozvaha nesedí.
     */
    public function testReverseFollowsStoredJournalNotCurrentMode(): void
    {
        $reg = $this->registers->create($this->deSupplierId, ['name' => 'Pokladna', 'account_code' => '211', 'is_default' => true]);
        $posted = $this->service->create($this->deSupplierId, $this->sale($reg, 1500.00), $this->userId);
        self::assertNotNull($posted['journal_entry_id'], 'Předpoklad: doklad zaúčtován do deníku.');
        self::assertEqualsWithDelta(1500.00, $this->accountNet($this->deSupplierId, '211'), 0.001);

        // Firma přepne účetní režim na daňovou evidenci PO zaúčtování.
        $this->setSupplierMode($this->deSupplierId, 'tax_evidence');

        $rev = $this->service->reverse(
            $this->deSupplierId,
            $posted['id'],
            ['reason' => 'Chybný doklad', 'entry_date' => self::YEAR . '-06-30'],
            $this->userId,
        );

        self::assertNotNull($rev['reversal_entry_id'], 'Storno dokladu s journalem MUSÍ mít protizápis i po přepnutí na DE.');
        self::assertSame($rev['reversal_entry_id'], $this->docReversalId($posted['id']));
        self::assertSame('reversed', $this->docStatus($posted['id']));
        // Ledger vyrovnaný: původní MD 1500 + protizápis D 1500 → net 0.
        self::assertEqualsWithDelta(0.0, $this->accountNet($this->deSupplierId, '211'), 0.001, 'Protizápis musí vyrovnat účet 211.');
    }

    /**
     * (HIGH, opačný směr) Doklad zaúčtovaný journal-free v tax_evidence → přepnutí
     * firmy na double_entry → (a) re-post je idempotentní (gate na status='posted',
     * ne na režimu → nehodí doc_not_draft), (b) storno funguje bez posting enginu
     * (journal_entry_id byl NULL → žádný protizápis).
     */
    public function testTaxEvidenceRepostIdempotentAndReversibleAfterSwitchToDoubleEntry(): void
    {
        $reg = $this->registers->create($this->teSupplierId, ['name' => 'Pokladna', 'account_code' => '211', 'is_default' => true]);
        $posted = $this->service->create($this->teSupplierId, $this->sale($reg, 900.00), $this->userId);
        self::assertNull($posted['journal_entry_id']);

        // Firma přepne na podvojné účetnictví PO zaúčtování journal-free dokladu.
        $this->setSupplierMode($this->teSupplierId, 'double_entry');

        // (a) Re-post téhož již zaúčtovaného dokladu → idempotentní návrat, žádná chyba.
        $again = $this->service->post($this->teSupplierId, $posted['id'], $this->userId);
        self::assertNull($again['journal_entry_id'], 'Re-post journal-free dokladu nesmí dodatečně účtovat.');
        self::assertSame('posted', $this->docStatus($posted['id']));

        // (b) Storno se řídí uloženým tvarem (journal NULL) → no-journal storno.
        $rev = $this->service->reverse($this->teSupplierId, $posted['id'], ['reason' => 'Oprava'], $this->userId);
        self::assertNull($rev['reversal_entry_id'], 'Journal-free doklad se stornuje bez protizápisu i po přepnutí na double_entry.');
        self::assertSame('reversed', $this->docStatus($posted['id']));
        self::assertSame(0, $this->journalEntryCountFor($this->teSupplierId, $posted['id']));
    }

    /**
     * (MED/LOW) Storno úhrady PF v tax_evidence musí vrátit PF do stavu PŘED úhradou,
     * tj. 'received' (v DE se PF neúčtuje = nikdy nebyla 'booked').
     */
    public function testTaxEvidenceReversePurchasePaymentRevertsPfToReceived(): void
    {
        $reg = $this->registers->create($this->teSupplierId, ['name' => 'Pokladna', 'account_code' => '211', 'is_default' => true]);
        $vendorId = $this->makeVendor($this->teSupplierId);
        $pfId = $this->makePurchaseInvoice($this->teSupplierId, $vendorId, 1200.00);

        $posted = $this->service->create($this->teSupplierId, $this->purchasePayment($reg, $pfId, 1200.00), $this->userId);
        self::assertSame('posted', $posted['status']);
        self::assertSame('paid', $this->pfStatus($pfId), 'Úhrada PF ji nastaví na paid.');

        $rev = $this->service->reverse($this->teSupplierId, $posted['id'], ['reason' => 'Vratka'], $this->userId);
        self::assertNull($rev['reversal_entry_id']);
        self::assertSame('received', $this->pfStatus($pfId), 'Storno úhrady v DE vrací PF na received, ne booked.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function setSupplierMode(int $supplierId, string $mode): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET accounting_mode = ? WHERE id = ?')
            ->execute([$mode, $supplierId]);
    }

    private function docReversalId(int $id): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT reversal_entry_id FROM cash_documents WHERE id = ?');
        $stmt->execute([$id]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (int) $v;
    }

    /** Signed net MD−D účtu přes všechny zápisy deníku (kontrola vyrovnanosti). */
    private function accountNet(int $supplierId, string $code): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN jel.side = 'debit' THEN jel.amount ELSE -jel.amount END), 0)
               FROM journal_entry_lines jel
               JOIN chart_of_accounts coa ON coa.id = jel.account_id
              WHERE jel.supplier_id = ? AND coa.account_code = ?"
        );
        $stmt->execute([$supplierId, $code]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function pfStatus(int $pfId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$pfId]);
        return (string) $stmt->fetchColumn();
    }

    private function makeVendor(int $supplierId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, "Dodavatel DE", "Ulice 1", "Praha", "11000", ?, "vendor-de@example.com", ?)'
        )->execute([$supplierId, $this->countryId, $this->currencyId]);
        return (int) $pdo->lastInsertId();
    }

    private function makePurchaseInvoice(int $supplierId, int $vendorId, float $total): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, issue_date, due_date, received_at,
                 currency_id, vendor_snapshot, total_with_vat, status, created_by)
             VALUES (?, ?, "DEPF-1", ?, ?, ?, ?, "{}", ?, "received", ?)'
        )->execute([
            $supplierId,
            $vendorId,
            self::YEAR . '-06-10',
            self::YEAR . '-06-24',
            self::YEAR . '-06-10',
            $this->currencyId,
            $total,
            $this->userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function purchasePayment(int $registerId, int $pfId, float $total): array
    {
        return [
            'register_id'         => $registerId,
            'issue_date'          => self::YEAR . '-06-15',
            'description'         => 'Úhrada PF hotově',
            'purpose'             => 'purchase_payment',
            'doc_type'            => 'out',
            'total_amount'        => $total,
            'purchase_invoice_id' => $pfId,
            'post'                => true,
        ];
    }

    private function makeSupplier(string $mode, int $vatRateId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier
                (company_name, street, city, zip, country_id, email,
                 default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "de-test@example.com", ?, ?, ?)'
        )->execute(['DE Test ' . $mode, $this->countryId, $this->currencyId, $vatRateId, $mode]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function sale(int $registerId, float $total): array
    {
        return [
            'register_id'  => $registerId,
            'issue_date'   => self::YEAR . '-06-15',
            'description'  => 'Pokladní tržba',
            'purpose'      => 'sale',
            'doc_type'     => 'in',
            'total_amount' => $total,
            'post'         => true,
        ];
    }

    private function periodCount(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM accounting_periods WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function docJournalId(int $id): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT journal_entry_id FROM cash_documents WHERE id = ?');
        $stmt->execute([$id]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (int) $v;
    }

    private function docStatus(int $id): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM cash_documents WHERE id = ?');
        $stmt->execute([$id]);
        return (string) $stmt->fetchColumn();
    }

    private function journalEntryCountFor(int $supplierId, int $cashDocId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries
              WHERE supplier_id = ? AND source_type = 'cash' AND source_id = ?"
        );
        $stmt->execute([$supplierId, $cashDocId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,array{debit:float,credit:float}> */
    private function linesByAccountCode(int $supplierId, int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT coa.account_code AS code, jel.side, jel.amount
               FROM journal_entry_lines jel
               JOIN chart_of_accounts coa ON coa.id = jel.account_id
              WHERE jel.entry_id = ? AND jel.supplier_id = ?'
        );
        $stmt->execute([$entryId, $supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) {
            $code = (string) $l['code'];
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][(string) $l['side']] += (float) $l['amount'];
        }
        return $out;
    }
}
