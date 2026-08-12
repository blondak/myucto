<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy saldokonta (audit 2026-07, nález H13 — fáze D6/1):
 * otevřené položky per partner, konfrontace Σ položek se zůstatkem účtu z deníku
 * (sedí, když jsou doklady zaúčtované; rozdíl při ručním zápisu mimo fakturu),
 * částečná/hotovostní úhrada snižuje zbytek, plná úhrada položku uzavře,
 * tenant izolace, dobropis se nettuje (ne přičítá), storno po rozvahovém dni
 * nezmizí ze seznamu, credit-side (321/purchase_invoice).
 *
 * T5–T8 doplněny po adversariálním review (H1/H2/H4 fixy + credit-side pokrytí).
 *
 * Vše běží v jedné transakci, kterou tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class SaldoReportTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private SaldoService $saldo;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;
    private InvoicePaymentService $invoicePayments;
    private PurchaseInvoiceRepository $purchaseInvoices;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db               = $container->get(Connection::class);
            $this->posting          = $container->get(PostingService::class);
            $this->saldo            = $container->get(SaldoService::class);
            $this->periods          = $container->get(AccountingPeriodRepository::class);
            $this->seeder           = $container->get(ChartOfAccountsSeeder::class);
            $this->invoicePayments  = $container->get(InvoicePaymentService::class);
            $this->purchaseInvoices = $container->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $iso = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        );
        $iso->execute(['Saldo test s.r.o.', 'saldo@example.com', $this->supplierId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $this->seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    // ── T1: otevřené položky per partner + konfrontace sedí ──────────────────

    public function testOpenItemsPerPartnerAndConfrontationMatches(): void
    {
        $a = $this->client('Alfa s.r.o.');
        $b = $this->client('Beta a.s.');
        $invA = $this->invoice($a, 1210.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $invB = $this->invoice($b, 605.00, self::YEAR . '-03-12', self::YEAR . '-03-26');

        $this->postInvoice($invA, [
            self::l('311', 'debit', 1210.00),
            self::l('602', 'credit', 1000.00),
            self::l('343', 'credit', 210.00),
        ], self::YEAR . '-03-10');
        $this->postInvoice($invB, [
            self::l('311', 'debit', 605.00),
            self::l('602', 'credit', 500.00),
            self::l('343', 'credit', 105.00),
        ], self::YEAR . '-03-12');

        $data = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '311');
        $acc = $this->accBlock($data, '311');
        self::assertNotNull($acc, 'Blok účtu 311 musí existovat.');

        self::assertSame(self::cents(1815.00), self::cents($acc['gl_balance']), 'Zůstatek HK 311 = 1210 + 605.');
        self::assertSame(self::cents(1815.00), self::cents($acc['open_items_total']), 'Σ otevřených položek = zůstatek účtu.');
        self::assertSame(0, self::cents($acc['difference']), 'Rozdíl je nulový.');
        self::assertTrue($acc['matches'], 'Konfrontace sedí, když jsou všechny doklady zaúčtované.');

        self::assertCount(2, $acc['partners'], 'Dva partneři.');
        $pA = $this->partner($acc, $a);
        self::assertNotNull($pA);
        self::assertSame(self::cents(1210.00), self::cents($pA['total_remaining']));
        self::assertCount(1, $pA['items']);
        self::assertSame('invoice', $pA['items'][0]['doc_type']);
        self::assertSame(self::cents(1210.00), self::cents($pA['items'][0]['remaining_czk']));
    }

    // ── T2: ruční zápis mimo fakturu → konfrontace nesouhlasí ────────────────

    public function testManualEntryOnAccountCreatesDiscrepancy(): void
    {
        $a = $this->client('Alfa s.r.o.');
        $invA = $this->invoice($a, 1000.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $this->postInvoice($invA, [
            self::l('311', 'debit', 1000.00),
            self::l('602', 'credit', 1000.00),
        ], self::YEAR . '-03-10');

        // Ruční zápis přímo na 311 bez zdrojové faktury — v deníku je, v otevřených položkách ne.
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            self::l('311', 'debit', 500.00),
            self::l('602', 'credit', 500.00),
        ], ['entry_date' => self::YEAR . '-04-01', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $data = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '311');
        $acc = $this->accBlock($data, '311');
        self::assertNotNull($acc);

        self::assertSame(self::cents(1500.00), self::cents($acc['gl_balance']), 'Zůstatek HK vč. ručního zápisu = 1500.');
        self::assertSame(self::cents(1000.00), self::cents($acc['open_items_total']), 'Otevřené položky = jen faktura 1000.');
        self::assertSame(self::cents(500.00), self::cents($acc['difference']), 'Rozdíl = ruční zápis 500.');
        self::assertFalse($acc['matches'], 'Konfrontace odhalí nesoulad.');
    }

    // ── T3: hotovostní/ruční úhrada (H2) přes invoice_payments snižuje zbytek,
    //        plná úhrada položku uzavře ────────────────────────────────────────

    public function testCashPaymentReducesRemainingAndFullPaymentClosesItem(): void
    {
        $a = $this->client('Alfa s.r.o.');
        $b = $this->client('Beta a.s.');
        $invA = $this->invoice($a, 1000.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $invB = $this->invoice($b, 500.00, self::YEAR . '-03-12', self::YEAR . '-03-26');
        $this->postInvoice($invA, [self::l('311', 'debit', 1000.00), self::l('602', 'credit', 1000.00)], self::YEAR . '-03-10');
        $this->postInvoice($invB, [self::l('311', 'debit', 500.00), self::l('602', 'credit', 500.00)], self::YEAR . '-03-12');

        // A: HOTOVOSTNÍ částečná úhrada 400, B: HOTOVOSTNÍ plná úhrada 500 — obě
        // přes InvoicePaymentService (source='cash'), tak jak je zaznamenává
        // CashDocumentService::applySideEffects. payment_matches (bankovní audit
        // trail) se NEPOUŽIJE — přesně scénář z H2 (post-review): dřív se z
        // payment_matches uhrazeno vůbec nepočítalo a hotovostní úhrada zůstávala
        // vykázaná jako plně otevřená položka.
        $this->invoicePayments->recordPayment($invA, 400.00, self::YEAR . '-04-01', ['source' => 'cash']);
        $this->invoicePayments->recordPayment($invB, 500.00, self::YEAR . '-04-02', ['source' => 'cash']);

        $data = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '311');
        $acc = $this->accBlock($data, '311');
        self::assertNotNull($acc);

        // Zůstatek HK zůstává 1500 (úhrady v tomto testu do deníku samostatně
        // neúčtujeme), otevřené položky ale reflektují hotovostní úhrady: A zbývá
        // 600, B je uzavřená (status přešel na 'paid').
        self::assertSame(self::cents(600.00), self::cents($acc['open_items_total']), 'Otevřeno jen A: 1000 − 400 = 600.');

        $pA = $this->partner($acc, $a);
        self::assertNotNull($pA, 'Partner A má otevřenou částku.');
        self::assertSame(self::cents(600.00), self::cents($pA['items'][0]['remaining_czk']));
        self::assertSame(self::cents(400.00), self::cents($pA['items'][0]['paid_czk']));

        self::assertNull($this->partner($acc, $b), 'Plně (hotovostně) uhrazená faktura B se v otevřených položkách nezobrazí.');
    }

    public function testPaymentAfterAsOfDoesNotHideHistoricalOpenItem(): void
    {
        $a = $this->client('Alfa s.r.o.');
        $invoiceId = $this->invoice($a, 1000.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $this->postInvoice($invoiceId, [
            self::l('311', 'debit', 1000.00),
            self::l('602', 'credit', 1000.00),
        ], self::YEAR . '-03-10');

        $this->invoicePayments->recordPayment($invoiceId, 1000.00, self::YEAR . '-09-01', ['source' => 'cash']);
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            self::l('211', 'debit', 1000.00),
            self::l('311', 'credit', 1000.00),
        ], ['entry_date' => self::YEAR . '-09-01', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $historical = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-06-30', '311');
        $historicalAcc = $this->accBlock($historical, '311');
        self::assertNotNull($historicalAcc);
        self::assertSame(self::cents(1000.00), self::cents($historicalAcc['gl_balance']));
        self::assertSame(self::cents(1000.00), self::cents($historicalAcc['open_items_total']), 'Pozdější úhrada nesmí skrýt položku k asOf.');
        self::assertTrue($historicalAcc['matches']);
        self::assertNotNull($this->partner($historicalAcc, $a));

        $afterPayment = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '311');
        $afterPaymentAcc = $this->accBlock($afterPayment, '311');
        self::assertNotNull($afterPaymentAcc);
        self::assertSame(0, self::cents($afterPaymentAcc['gl_balance']));
        self::assertSame(0, self::cents($afterPaymentAcc['open_items_total']));
        self::assertTrue($afterPaymentAcc['matches']);
    }

    // ── T3b: záloha inkasovaná PŘÍMO na saldokontní účet (bez 324/314) ───────

    /**
     * Účetní, která přijatou zálohu neúčtuje přes 324, ale rovnou na pohledávku
     * (221 MD / 311 D), rozbíjela konfrontaci na dvě strany naráz: proforma je
     * SAMOSTATNÝ doklad, takže `invoice_payments.invoice_id` míří na ni, ne na
     * finální fakturu — ta pak neměla ŽÁDNOU vlastní platbu, `amount_to_pay` jí
     * záloha srazila na nulu a předpis na 311 zůstal plný. Výsledek: plně
     * předplacená faktura svítila jako celá otevřená a Σ položek byla proti
     * hlavní knize nafouklá o dvojnásobek zálohy.
     */
    public function testProformaPaidStraightToReceivableClosesPrepaidInvoice(): void
    {
        $client = $this->client('Předplatitel s.r.o.');

        $proforma = $this->proforma($client, 1210.00, self::YEAR . '-03-01', self::YEAR . '-03-08');
        $txId = $this->bankInvoicePayment($proforma, 1210.00, self::YEAR . '-03-05');
        $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            self::l('221', 'debit', 1210.00),
            self::l('311', 'credit', 1210.00),
        ], ['entry_date' => self::YEAR . '-03-05', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        // Finální faktura navázaná na proformu: plný předpis na 311, amount_to_pay = 0
        // (generovaný sloupec total_with_vat − advance_paid_amount).
        $final = $this->invoice($client, 1210.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id = ?, advance_paid_amount = ? WHERE id = ?')
            ->execute([$proforma, 1210.00, $final]);
        $this->postInvoice($final, [
            self::l('311', 'debit', 1210.00),
            self::l('602', 'credit', 1000.00),
            self::l('343', 'credit', 210.00),
        ], self::YEAR . '-03-10');

        $acc = $this->accBlock($this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '311'), '311');
        self::assertNotNull($acc);
        self::assertSame(0, self::cents($acc['gl_balance']), 'HK: předpis 1210 − inkaso zálohy 1210 = 0.');
        self::assertSame(0, self::cents($acc['open_items_total']), 'Předplacená faktura NENÍ otevřená položka.');
        self::assertSame(0, self::cents($acc['difference']));
        self::assertTrue($acc['matches']);
        self::assertNull($this->partner($acc, $client), 'Ani partner se v saldu neobjeví.');
    }

    /**
     * Zrcadlo předchozího testu na ČÁSTEČNOU zálohu: v saldu smí zůstat přesně
     * nedoplatek. Chytí obě chybné implementace naráz — jak původní (záloha se
     * nezapočte vůbec → otevřeno 1210), tak naivní opravu odečtem celé zálohy od
     * zbytku (→ otevřeno 0 nebo dokonce záporná položka).
     */
    public function testPartialProformaPrepaymentLeavesOnlyRemainderOpen(): void
    {
        $client = $this->client('Předplatitel částečný s.r.o.');

        $proforma = $this->proforma($client, 1210.00, self::YEAR . '-03-01', self::YEAR . '-03-08');
        $txId = $this->bankInvoicePayment($proforma, 605.00, self::YEAR . '-03-05');
        $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            self::l('221', 'debit', 605.00),
            self::l('311', 'credit', 605.00),
        ], ['entry_date' => self::YEAR . '-03-05', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $final = $this->invoice($client, 1210.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id = ?, advance_paid_amount = ? WHERE id = ?')
            ->execute([$proforma, 605.00, $final]);
        $this->postInvoice($final, [
            self::l('311', 'debit', 1210.00),
            self::l('602', 'credit', 1000.00),
            self::l('343', 'credit', 210.00),
        ], self::YEAR . '-03-10');

        $acc = $this->accBlock($this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '311'), '311');
        self::assertNotNull($acc);
        self::assertSame(self::cents(605.00), self::cents($acc['gl_balance']));
        self::assertSame(self::cents(605.00), self::cents($acc['open_items_total']), 'Otevřený je jen nedoplatek 605.');
        self::assertSame(0, self::cents($acc['difference']));
        self::assertTrue($acc['matches']);

        $p = $this->partner($acc, $client);
        self::assertNotNull($p);
        self::assertSame(self::cents(1210.00), self::cents($p['items'][0]['booked_czk']));
        self::assertSame(self::cents(605.00), self::cents($p['items'][0]['paid_czk']));
        self::assertSame(self::cents(605.00), self::cents($p['items'][0]['remaining_czk']));
    }

    /**
     * Regresní pojistka pro tenanty, kteří 324 POUŽÍVAJÍ: inkaso proformy kredituje
     * 324 (ne 311) a finální faktura si zálohu zúčtuje sama zápisem 324 MD / 311 D
     * ve VLASTNÍM zápisu — `booked_signed` proto přichází už netto. Záloha se tedy
     * NESMÍ započítat podruhé přes vazbu parent_invoice_id, jinak by se plně
     * uhrazená část odečetla dvakrát a položka spadla pod nulu.
     */
    public function testAdvanceOn324IsNotCountedTwiceAgainstFinalInvoice(): void
    {
        $client = $this->client('Zálohový přes 324 s.r.o.');

        $proforma = $this->proforma($client, 1210.00, self::YEAR . '-03-01', self::YEAR . '-03-08');
        $txId = $this->bankInvoicePayment($proforma, 605.00, self::YEAR . '-03-05');
        $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            self::l('221', 'debit', 605.00),
            self::l('324', 'credit', 605.00),
        ], ['entry_date' => self::YEAR . '-03-05', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $final = $this->invoice($client, 1210.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id = ?, advance_paid_amount = ? WHERE id = ?')
            ->execute([$proforma, 605.00, $final]);
        $this->postInvoice($final, [
            self::l('311', 'debit', 1210.00),
            self::l('602', 'credit', 1000.00),
            self::l('343', 'credit', 210.00),
            self::l('324', 'debit', 605.00),
            self::l('311', 'credit', 605.00),
        ], self::YEAR . '-03-10');

        $acc = $this->accBlock($this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '311'), '311');
        self::assertNotNull($acc);
        self::assertSame(self::cents(605.00), self::cents($acc['gl_balance']), 'Předpis 1210 − zúčtování zálohy 605.');
        self::assertSame(self::cents(605.00), self::cents($acc['open_items_total']), 'Záloha z 324 se na 311 NEodečítá podruhé.');
        self::assertSame(0, self::cents($acc['difference']));
        self::assertTrue($acc['matches']);

        $p = $this->partner($acc, $client);
        self::assertNotNull($p);
        self::assertSame(self::cents(605.00), self::cents($p['items'][0]['booked_czk']), 'Předpis přichází už netto o zúčtovanou zálohu.');
        self::assertSame(self::cents(605.00), self::cents($p['items'][0]['remaining_czk']));
    }

    // ── T3c: úhrada, kterou deník uznává až PO rozvahovém dni ────────────────

    /**
     * `invoice_payments.paid_on` je datum, které si zapsala účetní; hlavní kniha ale
     * pohledávku odúčtuje až `entry_date` bankovního zápisu. Legacy importy (a lednové
     * výpisy k prosincovým fakturám) tyhle dva dny běžně rozcházejí — a saldokonto pak
     * fakturu k 31. 12. skrylo, přestože ji HK měla otevřenou. Konfrontace musí měřit
     * stejným datem jako deník.
     */
    public function testPaymentPostedAfterAsOfKeepsInvoiceOpen(): void
    {
        $client = $this->client('Lednový výpis s.r.o.');
        $invoiceId = $this->invoice($client, 1000.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $this->postInvoice($invoiceId, [
            self::l('311', 'debit', 1000.00),
            self::l('602', 'credit', 1000.00),
        ], self::YEAR . '-03-10');

        // Doklad nese paid_on v červnu, banka pohyb i zápis až v srpnu.
        $txId = $this->bankInvoicePayment($invoiceId, 1000.00, self::YEAR . '-06-30', self::YEAR . '-08-01');
        $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            self::l('221', 'debit', 1000.00),
            self::l('311', 'credit', 1000.00),
        ], ['entry_date' => self::YEAR . '-08-01', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $before = $this->accBlock($this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-06-30', '311'), '311');
        self::assertNotNull($before);
        self::assertSame(self::cents(1000.00), self::cents($before['gl_balance']));
        self::assertSame(self::cents(1000.00), self::cents($before['open_items_total']), 'K 30. 6. deník úhradu ještě nezná — položka je otevřená.');
        self::assertTrue($before['matches']);

        $after = $this->accBlock($this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '311'), '311');
        self::assertNotNull($after);
        self::assertSame(0, self::cents($after['gl_balance']));
        self::assertSame(0, self::cents($after['open_items_total']));
        self::assertTrue($after['matches']);
    }

    /**
     * Přijatá strana téhož: `status='paid'` + `paid_at` je stav DOKLADU. Když k PF
     * existuje bankovní párování, které deník uznává až po rozvahovém dni, zkratka
     * „paid ⇒ uzavřeno" ze salda odstraní závazek, který HK k tomu dni pořád má.
     * PF BEZ bankovního párování (hotovost, ruční „označit zaplaceno") si zkratku
     * ponechává — to hlídá T7 výše.
     */
    public function testPurchasePaidInDocumentButPostedAfterAsOfStaysOpen(): void
    {
        $vendor = $this->client('Dodavatel lednový s.r.o.');
        $pi = $this->purchaseInvoice($vendor, 1000.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pi, [
            self::l('518', 'debit', 1000.00),
            self::l('321', 'credit', 1000.00),
        ], ['entry_date' => self::YEAR . '-03-10', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $txId = $this->bankPayment($pi, 1000.00, self::YEAR . '-08-01');
        $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            self::l('321', 'debit', 1000.00),
            self::l('221', 'credit', 1000.00),
        ], ['entry_date' => self::YEAR . '-08-01', 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->purchaseInvoices->setStatus($pi, 'paid', $this->supplierId, self::YEAR . '-05-31');

        $before = $this->accBlock($this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-06-30', '321'), '321');
        self::assertNotNull($before);
        self::assertSame(self::cents(1000.00), self::cents($before['gl_balance']));
        self::assertSame(self::cents(1000.00), self::cents($before['open_items_total']), 'PF označená jako zaplacená, ale v deníku k 30. 6. neuhrazená, zůstává otevřená.');
        self::assertSame(0, self::cents($before['difference']));
        self::assertTrue($before['matches']);

        $after = $this->accBlock($this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '321'), '321');
        self::assertNotNull($after);
        self::assertSame(0, self::cents($after['gl_balance']));
        self::assertSame(0, self::cents($after['open_items_total']));
        self::assertTrue($after['matches']);
    }

    // ── T4: tenant izolace ───────────────────────────────────────────────────

    public function testTenantIsolation(): void
    {
        $a = $this->client('Alfa s.r.o.');
        $invA = $this->invoice($a, 1000.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $this->postInvoice($invA, [self::l('311', 'debit', 1000.00), self::l('602', 'credit', 1000.00)], self::YEAR . '-03-10');

        // Druhý supplier s vlastní osnovou/obdobím/klientem/fakturou.
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Druhá", "Brno", "60200", ?, "druha-saldo@example.com", ?, ?)'
        );
        $stmt->execute(['Druhá firma s.r.o.', $this->czId, $this->currencyId, $this->vatRateId]);
        $supplier2 = (int) $this->db->pdo()->lastInsertId();
        $this->seeder->seedForSupplier($supplier2);
        $this->periods->create($supplier2, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $c2 = $this->client('Cizí klient s.r.o.', $supplier2);
        $inv2 = $this->invoice($c2, 9999.00, self::YEAR . '-03-05', self::YEAR . '-03-19', $supplier2);
        $this->posting->postDocument($supplier2, 'invoice', $inv2, [
            self::l('311', 'debit', 9999.00), self::l('602', 'credit', 9999.00),
        ], ['entry_date' => self::YEAR . '-03-05', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $data = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '311');
        $acc = $this->accBlock($data, '311');
        self::assertNotNull($acc);
        self::assertSame(self::cents(1000.00), self::cents($acc['gl_balance']), 'Zůstatek nezahrnuje cizího tenanta.');
        self::assertSame(self::cents(1000.00), self::cents($acc['open_items_total']));
        foreach ($acc['partners'] as $p) {
            self::assertNotSame('Cizí klient s.r.o.', $p['partner_name'], 'Partner cizího tenanta nesmí prosáknout.');
            self::assertNotSame(self::cents(9999.00), self::cents($p['total_remaining']));
        }
    }

    // ── T5 (H1): dobropis se v saldu partnera NETTUJE, ne přičítá ────────────

    public function testCreditNoteNetsAgainstInvoiceInPartnerBalance(): void
    {
        $a = $this->client('Alfa s.r.o.');
        $invA = $this->invoice($a, 1210.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $this->postInvoice($invA, [
            self::l('311', 'debit', 1210.00),
            self::l('602', 'credit', 1000.00),
            self::l('343', 'credit', 210.00),
        ], self::YEAR . '-03-10');

        // Dobropis (invoice_type='credit_note') je vždy na OPAČNÉ straně
        // saldokontního účtu (Cr 311) — total_with_vat je konvenčně záporné
        // (InvoiceRepository komentář: "credit_note má záporné částky → odečte se").
        $credA = $this->creditNote($a, -500.00, self::YEAR . '-05-01', self::YEAR . '-05-15');
        $this->posting->postDocument($this->supplierId, 'invoice', $credA, [
            self::l('602', 'debit', 500.00),
            self::l('311', 'credit', 500.00),
        ], ['entry_date' => self::YEAR . '-05-01', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $data = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '311');
        $acc = $this->accBlock($data, '311');
        self::assertNotNull($acc);

        // GL nettuje znaménka nativně (accountOpening) → 1210 − 500 = 710.
        self::assertSame(self::cents(710.00), self::cents($acc['gl_balance']));
        // Post-review fix H1: PŘED opravou by open_items_total vyšlo 1710
        // (abs(1210) + abs(-500)) místo netto 710 — dobropis by se přičetl
        // místo odečetl a konfrontace by NIKDY neseděla.
        self::assertSame(self::cents(710.00), self::cents($acc['open_items_total']), 'Dobropis se nettuje, ne přičítá (H1).');
        self::assertSame(0, self::cents($acc['difference']));
        self::assertTrue($acc['matches'], 'Konfrontace sedí i s dobropisem v otevřených položkách.');

        $pA = $this->partner($acc, $a);
        self::assertNotNull($pA);
        self::assertSame(self::cents(710.00), self::cents($pA['total_remaining']));
        self::assertCount(2, $pA['items'], 'Partner má dvě položky: fakturu a dobropis.');

        $creditItem = null;
        foreach ($pA['items'] as $it) {
            if ((int) $it['doc_id'] === $credA) {
                $creditItem = $it;
            }
        }
        self::assertNotNull($creditItem, 'Dobropis je v seznamu položek.');
        self::assertLessThan(0, $creditItem['remaining_czk'], 'Dobropis je záporná otevřená položka, ne kladná pohledávka.');
        self::assertSame(self::cents(-500.00), self::cents($creditItem['remaining_czk']));
    }

    // ── T6 (H4): storno DATOVANÉ PO rozvahovém dni nezmizí ze seznamu k asOf ─

    public function testReversalAfterAsOfDoesNotHideOpenItem(): void
    {
        $a = $this->client('Alfa s.r.o.');
        $invA = $this->invoice($a, 1000.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $entryId = $this->postInvoice($invA, [
            self::l('311', 'debit', 1000.00),
            self::l('602', 'credit', 1000.00),
        ], self::YEAR . '-03-10');

        // Storno zaúčtované AŽ v červnu (typicky oprava loňské faktury v novém roce).
        $this->posting->reverse($this->supplierId, $entryId, [
            'entry_date' => self::YEAR . '-06-15',
            'posted_by'  => $this->userId,
        ]);
        $this->db->pdo()->prepare('UPDATE invoices SET status = "cancelled" WHERE id = ?')->execute([$invA]);

        // K DUBNU (před stornem) musí být doklad ještě OTEVŘENÝ — před H4 fixem
        // dřívější filtr `reversed_by IS NULL` odrážel AKTUÁLNÍ stav (stornováno)
        // a doklad by k asOf=duben zmizel ze seznamu, i když k tomu dni v hlavní
        // knize ještě "žil" (mirror entry má entry_date až v červnu).
        $beforeReversal = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-04-01', '311');
        $accBefore = $this->accBlock($beforeReversal, '311');
        self::assertNotNull($accBefore);
        self::assertSame(self::cents(1000.00), self::cents($accBefore['gl_balance']), 'GL k dubnu ještě nezná červnové storno.');
        self::assertSame(self::cents(1000.00), self::cents($accBefore['open_items_total']), 'Otevřená položka k dubnu musí existovat (H4).');
        self::assertTrue($accBefore['matches']);
        self::assertNotNull($this->partner($accBefore, $a), 'Partner s otevřenou fakturou musí být v seznamu k dubnu.');

        // K PROSINCI (po stornu) je doklad už uzavřený — originál i zrcadlo se
        // v deníku i v otevřených položkách vyruší (obojí <= asOf).
        $afterReversal = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '311');
        $accAfter = $this->accBlock($afterReversal, '311');
        self::assertNotNull($accAfter);
        self::assertSame(0, self::cents($accAfter['gl_balance']), 'GL k prosinci už storno zohledňuje.');
        self::assertSame(0, self::cents($accAfter['open_items_total']), 'Otevřené položky k prosinci jsou nulové.');
        self::assertTrue($accAfter['matches']);
    }

    // ── T7 (credit-side): 321/purchase_invoice — orientace + hotovostní úhrada ─

    public function testPurchaseInvoiceCreditSideOrientationAndCashPayment(): void
    {
        $vendorA = $this->client('Dodavatel A s.r.o.');
        $vendorB = $this->client('Dodavatel B s.r.o.');
        $piA = $this->purchaseInvoice($vendorA, 1000.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $piB = $this->purchaseInvoice($vendorB, 2000.00, self::YEAR . '-03-12', self::YEAR . '-03-26');

        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $piA, [
            self::l('518', 'debit', 1000.00),
            self::l('321', 'credit', 1000.00),
        ], ['entry_date' => self::YEAR . '-03-10', 'posted_by' => $this->userId, 'user_id' => $this->userId]);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $piB, [
            self::l('518', 'debit', 2000.00),
            self::l('321', 'credit', 2000.00),
        ], ['entry_date' => self::YEAR . '-03-12', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        // B: hotovostní plná úhrada (CashDocumentService::applySideEffects dělá totéž —
        // setStatus('paid') + offsetting zápis Dr 321 / Cr pokladna). Zrcadlíme obojí,
        // ať konfrontace GL vs. otevřené položky zůstane vnitřně konzistentní.
        $this->purchaseInvoices->setStatus($piB, 'paid', $this->supplierId, self::YEAR . '-04-05');
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            self::l('321', 'debit', 2000.00),
            self::l('211', 'credit', 2000.00),
        ], ['entry_date' => self::YEAR . '-04-05', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $data = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '321');
        $acc = $this->accBlock($data, '321');
        self::assertNotNull($acc, 'Blok účtu 321 musí existovat.');

        // Orientace: 321 je credit-normal (závazek) → kladný zůstatek = dlužíme.
        self::assertSame('credit', $acc['account']['normal_side']);
        self::assertGreaterThan(0, $acc['gl_balance'], 'Zůstatek závazku je kladný (credit-side orientace).');
        self::assertSame(self::cents(1000.00), self::cents($acc['gl_balance']), 'A (1000) živý, B (2000) vyrovnané hotovostní platbou.');
        self::assertSame(self::cents(1000.00), self::cents($acc['open_items_total']), 'Otevřeno jen A — B uzavřeno přes status=paid (H2 pro PF).');
        self::assertSame(0, self::cents($acc['difference']));
        self::assertTrue($acc['matches']);

        $pA = $this->partner($acc, $vendorA);
        self::assertNotNull($pA);
        self::assertSame('purchase_invoice', $pA['items'][0]['doc_type']);
        self::assertGreaterThan(0, $pA['items'][0]['booked_czk'], 'Částka PF je zobrazena kladně (ne invertovaná).');
        self::assertSame(self::cents(1000.00), self::cents($pA['items'][0]['remaining_czk']));

        self::assertNull($this->partner($acc, $vendorB), 'Hotovostně uhrazená PF (status=paid) se v otevřených položkách nezobrazí.');
    }

    /**
     * Saldo 314: čerpání poskytnuté zálohy má DVĚ vazební cesty a obě musí do součtu —
     * vyúčtovací faktura přes advance_purchase_invoice_id (321/314) a přijatý DDKP § 28
     * přes parent_purchase_invoice_id (343/314).
     *
     * DDKP první cestu použít nemůže: nad advance_purchase_invoice_id je UNIQUE index.
     * Dokud CTE hledalo jen ji, kredit DDKP na 314 vypadl a záloha svítila jako otevřená
     * o celou částku DPH navíc. Vydaná větev (324) tenhle problém nemá — používá obecné
     * parent_invoice_id IS NOT NULL, které chytí DDKP i finál najednou.
     */
    public function testAdvanceVatDocumentCreditCountsAgainstAdvanceOn314(): void
    {
        $vendor = $this->client('Dodavatel zálohový s.r.o.');

        // Poskytnutá záloha 1 210 uhrazená z banky → 314 MD. Úhrada musí jít přes
        // payment_matches + zaúčtovaný bankovní pohyb, jinak ji `paid` CTE nevidí.
        $advance = $this->purchaseInvoice($vendor, 1210.00, self::YEAR . '-02-01', self::YEAR . '-02-10');
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind = 'advance' WHERE id = ?")
            ->execute([$advance]);
        $txId = $this->bankPayment($advance, 1210.00, self::YEAR . '-02-05');
        $this->posting->postDocument($this->supplierId, 'bank', $txId, [
            self::l('314', 'debit', 1210.00),
            self::l('221', 'credit', 1210.00),
        ], ['entry_date' => self::YEAR . '-02-05', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        // DDKP k té záloze — účtuje JEN DPH (343 MD / 314 D), váže se přes parent.
        $ddkp = $this->purchaseInvoice($vendor, 210.00, self::YEAR . '-02-06', self::YEAR . '-02-06');
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET document_kind = 'tax_document', parent_purchase_invoice_id = ? WHERE id = ?"
        )->execute([$advance, $ddkp]);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $ddkp, [
            self::l('343', 'debit', 210.00),
            self::l('314', 'credit', 210.00),
        ], ['entry_date' => self::YEAR . '-02-06', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $data = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '314');
        $acc = $this->accBlock($data, '314');
        self::assertNotNull($acc, 'Blok účtu 314 musí existovat.');

        $p = $this->partner($acc, $vendor);
        self::assertNotNull($p, 'Záloha musí být v otevřených položkách.');
        $item = $p['items'][0];

        self::assertSame(self::cents(1210.00), self::cents($item['booked_czk']));
        self::assertSame(
            self::cents(1000.00),
            self::cents($item['remaining_czk']),
            'DDKP odčerpal z 314 DPH 210 — otevřený zbytek zálohy je 1000, ne plných 1210.',
        );
    }

    // ── T9 (H4b): stornovaná PF vyrušená RUČNÍM protidokladem zmizí ze salda
    //        k settlement date (cancelled_at), otevřená zůstane k dřívějšímu asOf ─

    public function testCancelledPurchaseWithManualReversalSettledAtCancelledAt(): void
    {
        $vendor = $this->client('Magistrát test s.r.o.');
        $pf = $this->purchaseInvoice($vendor, 800.00, self::YEAR . '-01-10', self::YEAR . '-01-10');

        // Kontace PF (Dr 538 / Cr 321) zůstane ŽIVÁ — reversed_by NULL.
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $pf, [
            self::l('538', 'debit', 800.00),
            self::l('321', 'credit', 800.00),
        ], ['entry_date' => self::YEAR . '-01-10', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        // Zrcadlový RUČNÍ storno k 31.12 — obě strany live (HK i VH netují na 0),
        // reversed_by ZÁMĚRNĚ nenastavujeme (jinak by dotazy reversed_by IS NULL
        // vyloučily jen jednu stranu a rozbily VH). Protidoklad je source_type='manual',
        // takže se v saldu na úrovni dokladu s PF neztuluje.
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            self::l('321', 'debit', 800.00),
            self::l('538', 'credit', 800.00),
        ], ['entry_date' => self::YEAR . '-12-31', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        // Doklad je stornovaný; settlement date = den ručního storna.
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET status = "cancelled", cancelled_at = ? WHERE id = ?')
            ->execute([self::YEAR . '-12-31', $pf]);

        // asOf PŘED stornem: v HK jen kontace → 321 = 800, PF otevřená, konfrontace sedí.
        $before = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-06-30', '321');
        $accBefore = $this->accBlock($before, '321');
        self::assertNotNull($accBefore);
        self::assertSame(self::cents(800.00), self::cents($accBefore['gl_balance']), 'HK 321 před ručním stornem = 800.');
        self::assertSame(self::cents(800.00), self::cents($accBefore['open_items_total']), 'Stornovaná PF je otevřená k asOf < cancelled_at.');
        self::assertSame(0, self::cents($accBefore['difference']));
        self::assertTrue($accBefore['matches']);
        self::assertNotNull($this->partner($accBefore, $vendor), 'Partner s otevřenou PF je k dřívějšímu asOf v seznamu.');

        // asOf K/PO settlement date: PF zmizí ze salda; HK i tady netuje → VH beze změny,
        // konfrontace sedí (0 = 0), difference se nezhorší.
        $after = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '321');
        $accAfter = $this->accBlock($after, '321');
        self::assertNotNull($accAfter, 'Blok 321 při explicitním filtru existuje vždy.');
        self::assertSame(0, self::cents($accAfter['gl_balance']), 'HK 321 po ručním stornu netuje na 0 (VH beze změny).');
        self::assertSame(0, self::cents($accAfter['open_items_total']), 'Stornovaná PF se k cancelled_at v saldu neobjeví.');
        self::assertSame(0, self::cents($accAfter['difference']));
        self::assertTrue($accAfter['matches']);
        self::assertNull($this->partner($accAfter, $vendor), 'Partner stornované PF k settlement date zmizí ze salda.');
    }

    public function testClosingEntryDoesNotZeroHistoricalGlConfrontation(): void
    {
        $a = $this->client('Alfa s.r.o.');
        $invoiceId = $this->invoice($a, 1000.00, self::YEAR . '-03-10', self::YEAR . '-03-24');
        $this->postInvoice($invoiceId, [
            self::l('311', 'debit', 1000.00),
            self::l('602', 'credit', 1000.00),
        ], self::YEAR . '-03-10');

        $this->posting->postDocument($this->supplierId, 'closing', $this->periodId, [
            self::l('702', 'debit', 1000.00),
            self::l('311', 'credit', 1000.00),
        ], [
            'entry_date' => self::YEAR . '-12-31',
            'posted_by'  => $this->userId,
            'user_id'    => $this->userId,
        ]);

        $data = $this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '311');
        $acc = $this->accBlock($data, '311');
        self::assertNotNull($acc);
        self::assertSame(self::cents(1000.00), self::cents($acc['gl_balance']), 'Závěrkový převod nesmí vynulovat zůstatek k rozvahovému dni.');
        self::assertSame(self::cents(1000.00), self::cents($acc['open_items_total']));
        self::assertSame(0, self::cents($acc['difference']));
        self::assertTrue($acc['matches']);
    }

    // ── T10 (task #3, D6/2): asOf mimo vybrané období — dohledá se skutečné
    //        období a spočítá se k němu korektně (i uzavřené/approved) ──────────

    /**
     * Vybrané $periodId je novější rok (self::YEAR); asOf leží v PŘEDCHOZÍM,
     * samostatném (uzavřeném) roce — typický případ „chci vidět saldo 311
     * k 31.12. minulého roku, abych porovnala počáteční stav letoška". Před
     * opravou SaldoAction takový dotaz vůbec nešel poslat (validace as_of proti
     * hranicím vybraného období) — tenhle test jde na úroveň SaldoService a
     * ověřuje, že výpočet k cizímu období vyjde správně a `as_of_period` v
     * odpovědi nese SKUTEČNÉ (ne vybrané) období.
     */
    public function testAsOfInDifferentClosedPeriodThanSelectedComputesCorrectly(): void
    {
        $prevYear = self::YEAR - 1;
        $prevPeriodId = $this->periods->create($this->supplierId, $prevYear, $prevYear . '-01-01', $prevYear . '-12-31');

        // Zaúčtovat PŘED uzavřením — PostingService odmítá zápisy do closed období (§35 ZoÚ).
        $a = $this->client('Alfa s.r.o.');
        $invA = $this->invoice($a, 1000.00, $prevYear . '-03-10', $prevYear . '-03-24');
        $this->postInvoice($invA, [
            self::l('311', 'debit', 1000.00),
            self::l('602', 'credit', 1000.00),
        ], $prevYear . '-03-10');

        // Uzavřené/schválené období — přesně scénář z hlášení účetní.
        // row_version po create() je 1 (DEFAULT 1005), ne 0.
        $ok = $this->periods->setStatusCas($prevPeriodId, $this->supplierId, 'closed', 1, $this->userId);
        self::assertTrue($ok, 'Fixture: uzavření testovacího období selhalo (CAS).');

        // periodId = novější (self::YEAR/self::periodId), asOf = konec PŘEDCHOZÍHO roku.
        $data = $this->saldo->build($this->supplierId, $this->periodId, $prevYear . '-12-31', '311');
        $acc = $this->accBlock($data, '311');
        self::assertNotNull($acc, 'Blok 311 musí existovat i při asOf mimo vybrané období.');
        self::assertSame(self::cents(1000.00), self::cents($acc['gl_balance']), 'Zůstatek HK k 31.12. minulého roku.');
        self::assertSame(self::cents(1000.00), self::cents($acc['open_items_total']));
        self::assertSame(0, self::cents($acc['difference']), 'Konfrontace musí sedět i napříč obdobími.');
        self::assertTrue($acc['matches']);
        self::assertNotNull($this->partner($acc, $a));

        self::assertSame($this->periodId, $data['period']['id'], 'Vybrané období v odpovědi zůstává to z period_id.');
        self::assertNotNull($data['as_of_period'], 'as_of_period musí být dohledané.');
        self::assertSame($prevPeriodId, $data['as_of_period']['id'], 'as_of_period je skutečné období, do kterého asOf spadá.');
        self::assertSame($prevYear, $data['as_of_period']['fiscal_year']);
        self::assertSame('closed', $data['as_of_period']['status']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function client(string $name, ?int $supplierId = null): int
    {
        $sid = $supplierId ?? $this->supplierId;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, "Ulice 1", "Praha", "11000", ?, ?, ?)'
        );
        $stmt->execute([$sid, $name, $this->czId, 'c' . uniqid() . '@example.com', $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function invoice(int $clientId, float $total, string $issue, string $due, ?int $supplierId = null): int
    {
        $sid = $supplierId ?? $this->supplierId;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, client_id, issue_date, due_date, currency_id, created_by, total_with_vat, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "issued")'
        );
        $vs = (string) random_int(1000000000, 1999999999);
        $stmt->execute([$sid, $vs, $clientId, $issue, $due, $this->currencyId, $this->userId, $total]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Proforma (zálohová faktura): samostatný doklad, na který finál ukazuje parent_invoice_id. */
    private function proforma(int $clientId, float $total, string $issue, string $due): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, due_date, currency_id, created_by, total_with_vat, status)
             VALUES (?, ?, 'proforma', ?, ?, ?, ?, ?, ?, 'issued')"
        );
        $stmt->execute([
            $this->supplierId, (string) random_int(1000000000, 1999999999), $clientId,
            $issue, $due, $this->currencyId, $this->userId, $total,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Bankovní úhrada VYDANÉ faktury (nebo proformy): výpis + pohyb + invoice_payments.
     * `$postedOn` (den pohybu v bance) smí být jiný než `$paidOn` (den z dokladu) —
     * přesně tak vypadá lednový výpis k prosincové faktuře. Vrací id pohybu.
     */
    private function bankInvoicePayment(int $invoiceId, float $amount, string $paidOn, ?string $postedOn = null): int
    {
        $pdo = $this->db->pdo();
        $hash = hash('sha256', 'saldo-inv-' . $invoiceId . '-' . random_int(1, PHP_INT_MAX));
        $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, statement_date)
             VALUES (?, "gpc", ?, ?, "1000000005", "0100", ?)'
        )->execute([$this->supplierId, 'saldo-inv.gpc', $hash, $postedOn ?? $paidOn]);
        $statementId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, source, posted_at, amount, currency, counterparty_name, description, match_status, matched_invoice_id)
             VALUES (?, "statement", ?, ?, "CZK", "Odběratel", "Úhrada faktury", "manual", ?)'
        )->execute([$statementId, $postedOn ?? $paidOn, $amount, $invoiceId]);
        $txId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id)
             VALUES (?, ?, ?, ?, "CZK", "bank", ?)'
        )->execute([$this->supplierId, $invoiceId, $paidOn, $amount, $txId]);

        return $txId;
    }

    /** Dobropis: invoice_type='credit_note', total_with_vat konvenčně záporné. */
    private function creditNote(int $clientId, float $total, string $issue, string $due, ?int $supplierId = null): int
    {
        $sid = $supplierId ?? $this->supplierId;
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, due_date, currency_id, created_by, total_with_vat, status)
             VALUES (?, ?, 'credit_note', ?, ?, ?, ?, ?, ?, 'issued')"
        );
        $vs = (string) random_int(1000000000, 1999999999);
        $stmt->execute([$sid, $vs, $clientId, $issue, $due, $this->currencyId, $this->userId, $total]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Bankovní úhrada přijaté faktury: výpis + pohyb + payment_matches. Vrací id pohybu,
     * na který se pak zaúčtuje bankovní zápis (`paid` CTE vyžaduje obojí).
     */
    private function bankPayment(int $purchaseInvoiceId, float $amount, string $postedOn): int
    {
        $pdo = $this->db->pdo();
        $hash = hash('sha256', 'saldo-test-' . $purchaseInvoiceId . '-' . random_int(1, PHP_INT_MAX));
        $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, source, file_name, file_hash, account_number, bank_code, statement_date)
             VALUES (?, "gpc", ?, ?, "1000000005", "0100", ?)'
        )->execute([$this->supplierId, 'saldo-test.gpc', $hash, self::YEAR . '-06-15']);
        $statementId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, source, posted_at, amount, currency, counterparty_name, description, match_status)
             VALUES (?, "statement", ?, ?, "CZK", "Dodavatel", "Úhrada zálohy", "manual")'
        )->execute([$statementId, $postedOn, -$amount]);
        $txId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type)
             VALUES (?, ?, ?, ?, "manual")'
        )->execute([$this->supplierId, $txId, $purchaseInvoiceId, $amount]);

        return $txId;
    }

    private function purchaseInvoice(int $vendorId, float $total, string $issue, string $due, ?int $supplierId = null): int
    {
        $sid = $supplierId ?? $this->supplierId;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, varsymbol, vendor_id, vendor_invoice_number, document_kind,
                 issue_date, due_date, received_at, currency_id, vendor_snapshot, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, "invoice", ?, ?, ?, ?, "{}", ?, "received", ?)'
        );
        $vs = 'PF-' . random_int(1000000000, 1999999999);
        $stmt->execute([$sid, $vs, $vendorId, $vs, $issue, $due, $issue, $this->currencyId, $total, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     */
    private function postInvoice(int $invoiceId, array $lines, string $date): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $invoiceId,
            $lines,
            ['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );
    }

    /**
     * @return array{account_code:string, side:string, amount:float}
     */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    private function accBlock(array $data, string $code): ?array
    {
        foreach ($data['accounts'] as $acc) {
            if ((string) $acc['account']['code'] === $code) {
                return $acc;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $acc
     * @return array<string,mixed>|null
     */
    private function partner(array $acc, int $partnerId): ?array
    {
        foreach ($acc['partners'] as $p) {
            if ((int) $p['partner_id'] === $partnerId) {
                return $p;
            }
        }
        return null;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
