<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Uzávěrková kontrola `cancelled_with_entry`: stornovaný doklad, jehož zápis je
 * v deníku pořád aktivní.
 *
 * Účtuje se podle deníku, ne podle stavu dokladu — takový zápis proto nese náklad
 * a saldokonto dokladu, o kterém evidence tvrdí, že neexistuje. Uzávěrka by ten
 * rozpor zabetonovala do schváleného období.
 *
 * Reálný případ, kvůli kterému kontrola vznikla: přijatá účtenka byla označená jako
 * stornovaná, ale její zápis (538/321) zůstal aktivní v roce, který byl následně
 * uzavřen a schválen. Aplikační cesta storna (DocumentJournalSync::onCancel) vždy
 * vytvoří protizápis — tenhle stav vznikl mimo ni (import / přímý zásah do DB),
 * a žádná z 28 tehdejších uzávěrkových kontrol ho nezachytila.
 *
 * Izolovaný supplier v transakci s rollbackem (vzor ClosingBalanceInventoryTest).
 */
#[Group('integration')]
final class ClosingCancelledWithEntryTest extends TestCase
{
    private const YEAR = 2094;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private ClosingService $closing;
    private ClosingRepository $closingRepo;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db          = $container->get(Connection::class);
            $this->closing     = $container->get(ClosingService::class);
            $this->closingRepo = $container->get(ClosingRepository::class);
            $this->posting     = $container->get(PostingService::class);
            $this->periods     = $container->get(AccountingPeriodRepository::class);
            $seeder            = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId        = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId             = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        );
        $stmt->execute(['Storno test s.r.o.', $czId, 'storno-test@example.com', $this->currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);

        $stmt = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, is_vendor)
             VALUES (?, ?, "Dodavatelska 1", "Praha", "11000", ?, ?, 1)'
        );
        $stmt->execute([$this->supplierId, 'Dodavatel storno s.r.o.', $czId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();
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

    /**
     * Zrcadlo `paid_proformas_no_advance` na přijaté straně: zálohová faktura je
     * `paid`, ale v deníku k ní není žádná úhrada na 314.
     *
     * Zálohová PF nemá předpis na 321 — do deníku vstupuje až peněžní nohou 314 MD,
     * takže prázdné 314 znamená, že o zaplacené záloze deník neví (chybí pohledávka
     * za dodavatelem). Do uzávěrky se to dřív nedostalo, protože kontrola existovala
     * jen pro vydanou stranu (324).
     */
    public function testPaidAdvanceWithoutBookedPaymentIsReported(): void
    {
        $pdo = $this->db->pdo();
        $issue = self::YEAR . '-04-10';
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, reverse_charge,
                 total_without_vat, total_vat, total_with_vat, tax_deductible, status, paid_at, created_by)
             VALUES (?, ?, ?, "{}", "advance", ?, ?, ?, ?, ?, 0, 5000.00, 1050.00, 6050.00, 1, "paid", ?, ?)'
        )->execute([$this->supplierId, $this->vendorId, 'ZAL-1', $issue, $issue, $issue, $issue, $this->currencyId, $issue, $this->userId]);
        $advanceId = (int) $pdo->lastInsertId();

        $rows = $this->closingRepo->paidAdvancesWithoutBookedPayment($this->supplierId, self::ENDS_ON);
        self::assertCount(1, $rows, 'Zaplacená záloha bez zápisu na 314 musí být nahlášena.');
        self::assertSame($advanceId, $rows[0]['id']);
        self::assertEqualsWithDelta(6050.0, $rows[0]['booked'], 0.01);
        self::assertFalse($this->checkOk('paid_advances_no_payment'), 'Uzávěrková kontrola musí být v chybovém stavu.');
    }

    /** Zaúčtovaná úhrada zálohy na 314 nález zruší. */
    public function testBookedAdvancePaymentClearsTheFinding(): void
    {
        $pdo = $this->db->pdo();
        $issue = self::YEAR . '-04-10';
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, reverse_charge,
                 total_without_vat, total_vat, total_with_vat, tax_deductible, status, paid_at, created_by)
             VALUES (?, ?, ?, "{}", "advance", ?, ?, ?, ?, ?, 0, 5000.00, 1050.00, 6050.00, 1, "paid", ?, ?)'
        )->execute([$this->supplierId, $this->vendorId, 'ZAL-2', $issue, $issue, $issue, $issue, $this->currencyId, $issue, $this->userId]);
        $advanceId = (int) $pdo->lastInsertId();

        // Peněžní noha: bankovní pohyb spárovaný se zálohou, zaúčtovaný 314 MD / 221 D.
        $hash = hash('sha256', 'closing-adv-' . $advanceId);
        $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, statement_date)
             VALUES (?, "gpc", ?, ?, "1000000005", "0100", ?)'
        )->execute([$this->supplierId, 'closing-adv.gpc', $hash, self::YEAR . '-06-15']);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, source, posted_at, amount, currency, counterparty_name, description, match_status)
             VALUES (?, "statement", ?, -6050.00, "CZK", "Dodavatel", "Úhrada zálohy", "manual")'
        )->execute([$statementId, $issue]);
        $txId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type)
             VALUES (?, ?, ?, 6050.00, "manual")'
        )->execute([$this->supplierId, $txId, $advanceId]);

        $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            ['account_code' => '314', 'side' => 'debit', 'amount' => 6050.0],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 6050.0],
        ], [
            'entry_date' => $issue,
            'description' => 'Úhrada poskytnuté zálohy',
            'posted' => true,
            'user_id' => $this->userId,
        ]);

        self::assertSame([], $this->closingRepo->paidAdvancesWithoutBookedPayment($this->supplierId, self::ENDS_ON));
        self::assertTrue($this->checkOk('paid_advances_no_payment'));
    }

    public function testCancelledDocumentWithActiveEntryIsReported(): void
    {
        [$pfId, $entryId] = $this->postedPurchaseInvoice();

        // Čistý stav: doklad není stornovaný → kontrola mlčí.
        self::assertSame([], $this->closingRepo->cancelledDocumentsWithActiveEntry(
            $this->supplierId,
            self::STARTS_ON,
            self::ENDS_ON,
        ));
        self::assertTrue($this->checkOk(), 'Bez storna musí kontrola projít.');

        // Storno MIMO DocumentJournalSync (import / přímý zásah) — zápis zůstane aktivní.
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'cancelled', cancelled_at = ? WHERE id = ?")
            ->execute([self::ENDS_ON . ' 12:00:00', $pfId]);

        $rows = $this->closingRepo->cancelledDocumentsWithActiveEntry($this->supplierId, self::STARTS_ON, self::ENDS_ON);
        self::assertCount(1, $rows, 'Stornovaný doklad s aktivním zápisem musí být nahlášen.');
        self::assertSame($pfId, $rows[0]['id']);
        self::assertSame($entryId, $rows[0]['entry_id']);
        self::assertSame('purchase_invoice', $rows[0]['source_type']);
        self::assertEqualsWithDelta(1000.0, $rows[0]['booked'], 0.01);
        self::assertFalse($this->checkOk(), 'Uzávěrková kontrola musí být v chybovém stavu.');
    }

    public function testReversedEntryClearsTheFinding(): void
    {
        [$pfId, $entryId] = $this->postedPurchaseInvoice();
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'cancelled', cancelled_at = ? WHERE id = ?")
            ->execute([self::ENDS_ON . ' 12:00:00', $pfId]);
        self::assertFalse($this->checkOk());

        // Správné řešení druhou cestou: zápis se stornuje protizápisem.
        $this->posting->reverse($this->supplierId, $entryId, ['user_id' => $this->userId]);

        self::assertSame([], $this->closingRepo->cancelledDocumentsWithActiveEntry(
            $this->supplierId,
            self::STARTS_ON,
            self::ENDS_ON,
        ), 'Po stornu zápisu už nález nesmí existovat.');
        self::assertTrue($this->checkOk());
    }

    /** Zápis mimo uzavírané období se do kontroly nesmí připlést. */
    public function testEntryOutsideRangeIsIgnored(): void
    {
        [$pfId] = $this->postedPurchaseInvoice();
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'cancelled', cancelled_at = ? WHERE id = ?")
            ->execute([self::ENDS_ON . ' 12:00:00', $pfId]);

        self::assertSame([], $this->closingRepo->cancelledDocumentsWithActiveEntry(
            $this->supplierId,
            self::YEAR . '-01-01',
            self::YEAR . '-05-31',
        ));
    }

    /**
     * Přijatá faktura 1000 Kč (bez DPH) zaúčtovaná na 518/321 v uzavíraném období.
     *
     * @return array{0:int, 1:int} [purchase_invoice_id, entry_id]
     */
    private function postedPurchaseInvoice(): array
    {
        $pdo = $this->db->pdo();
        $issue = self::YEAR . '-06-15';
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, reverse_charge,
                 total_without_vat, total_vat, total_with_vat, tax_deductible, status, created_by)
             VALUES (?, ?, ?, "{}", "invoice", ?, ?, ?, ?, ?, 0, 1000.00, 0.00, 1000.00, 1, "booked", ?)'
        )->execute([$this->supplierId, $this->vendorId, 'PF-STORNO-1', $issue, $issue, $issue, $issue, $this->currencyId, $this->userId]);
        $pfId = (int) $pdo->lastInsertId();

        $entryId = $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pfId, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1000.0],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1000.0],
        ], [
            'entry_date'  => $issue,
            'document_no' => 'PF-STORNO-1',
            'description' => 'Přijatá faktura (test storna)',
            'posted'      => true,
            'user_id'     => $this->userId,
        ]);
        $pdo->prepare('UPDATE purchase_invoices SET booked_at = NOW() WHERE id = ?')->execute([$pfId]);

        return [$pfId, $entryId];
    }

    private function checkOk(string $key = 'cancelled_with_entry'): bool
    {
        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, null, null);
        foreach ($result['checks'] as $c) {
            if ($c['key'] === $key) {
                return (bool) $c['ok'];
            }
        }
        self::fail('Kontrola ' . $key . ' chybí v seznamu kontrol.');
    }
}
