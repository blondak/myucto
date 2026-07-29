<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Return\UnpaidLiabilityService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * § 23 odst. 3 písm. a) bod 12 ZDP — neuhrazené dluhy po 30 měsících, a protistrana
 * § 23 odst. 3 písm. c) bod 6 (snížení po úhradě).
 *
 * Matice daní z příjmů to vedla mezi vysokými riziky s přesnou formulací: systém data MÁ
 * (splatnost i stav úhrady), dopočet nedělá a ani neupozorní → podhodnocený základ daně.
 *
 * Nejdůležitější je {@see testPaymentAfterAddbackProducesDecrease()}: bez protistrany by
 * poplatník zaplatil daň DVAKRÁT — jednou z připočtení, podruhé tím, že by se snížení po
 * úhradě nikdy neuplatnilo. Proto ledger, ne jen návrh.
 */
#[Group('integration')]
final class UnpaidLiabilityServiceTest extends TestCase
{
    private const YEAR = 2094;

    private Connection $db;
    private UnpaidLiabilityService $service;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $seq = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db      = $c->get(Connection::class);
            $this->service = $c->get(UnpaidLiabilityService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'tax_unpaid_liability_addbacks'")->fetch() === false) {
            $this->markTestSkipped('Migrace 1155 neproběhla.');
        }
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if (in_array(0, [$czId, $vatRateId, $this->userId, $this->currencyId], true)) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        )->execute(['Dluhy s.r.o.', $czId, 'dluhy@example.com', $this->currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Dodavatel", "Test 1", "Praha", "11000", ?, "v@example.com", "cs", ?, 0, 1)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /** Do 30 měsíců se nepřipočítává — lhůta je hmotněprávní podmínka, ne odhad. */
    public function testLiabilityBefore30MonthsIsNotAddedBack(): void
    {
        $this->purchase('2092-08-01', 121000.0);

        // 2092-08 + 30 měsíců = 2095-02; k 31. 12. 2094 lhůta ještě neuplynula.
        $p = $this->service->preview($this->supplierId, self::YEAR, '2094-12-31');

        self::assertSame([], $p['rows']);
        self::assertSame(0.0, $p['net_delta']);
    }

    /** Po 30 měsících se dlužná částka připočte k základu. */
    public function testAgedUnpaidLiabilityIsAddedBack(): void
    {
        $id = $this->purchase('2092-01-15', 121000.0);

        $p = $this->service->preview($this->supplierId, self::YEAR, '2094-12-31');

        self::assertCount(1, $p['rows']);
        self::assertSame($id, $p['rows'][0]['purchase_invoice_id']);
        self::assertTrue($p['rows'][0]['aged']);
        self::assertSame(121000.0, $p['net_delta']);
        self::assertStringContainsString('NEPŘIPOČÍTÁVÁ', implode("\n", $p['warnings']));
    }

    /** Uhrazený dluh se nepřipočítává — není co dodanit. */
    public function testPaidLiabilityIsNotAddedBack(): void
    {
        $this->purchase('2092-01-15', 121000.0, paid: true);

        self::assertSame([], $this->service->preview($this->supplierId, self::YEAR, '2094-12-31')['rows']);
    }

    /**
     * Spárovaná platba je úhradou i BEZ zaúčtovaného bankovního zápisu.
     *
     * Detektor vyžadoval `journal_entries` se `source_type='bank'` a bez něj platbu
     * zahodil — doklad pak vyšel jako neuhrazený a připočetl by se k základu daně.
     * V produkci takhle propadl desetinásobek dokladů, nejhorší na statisíce Kč.
     * Zaúčtování je otázka evidence; peníze odešly bez ohledu na ni.
     */
    public function testMatchedPaymentCountsEvenWithoutPostedEntry(): void
    {
        $id = $this->purchase('2092-01-15', 121_000.0);
        $this->payFromBank($id, 121_000.0);

        self::assertSame(
            [],
            $this->service->preview($this->supplierId, self::YEAR, '2094-12-31')['rows'],
            'Uhrazený doklad se k základu daně nepřipočítává.',
        );
    }

    /**
     * Haléřový zbytek ze zaokrouhlení platby není dluh.
     *
     * Banka platí v celých korunách, faktura bývá na haléře. Bez tolerance vycházel
     * doklad jako částečně neuhrazený se zbytkem 0,04 až 0,35 Kč a generoval by
     * dopočet k základu daně na haléře — 8 dokladů z 26 falešně neuhrazených.
     */
    public function testRoundingResidualIsNotTreatedAsDebt(): void
    {
        $id = $this->purchase('2092-01-15', 64_393.21);
        $this->payFromBank($id, 64_393.00);

        self::assertSame([], $this->service->preview($this->supplierId, self::YEAR, '2094-12-31')['rows']);
    }

    /**
     * Cizoměnový doklad uhrazený ve své měně je uhrazený.
     *
     * `payment_matches.amount` je v měně TRANSAKCE, `total_with_vat` v měně dokladu.
     * Dokud se neporovnávalo ve stejné jednotce, odečítalo se 180 EUR mínus
     * 4 374,90 CZK — tedy jablka od hrušek.
     */
    public function testForeignInvoicePaidInSameCurrencyIsSettled(): void
    {
        $id = $this->purchaseInCurrency('2092-01-15', 236.84, 'EUR', 24.36);
        $this->payFromBank($id, 236.84, 'EUR');

        self::assertSame([], $this->service->preview($this->supplierId, self::YEAR, '2094-12-31')['rows']);
    }

    /**
     * CZK úhrada cizoměnového dokladu se přepočte kurzem dokladu — a částka, která jde
     * do základu daně, je v KORUNÁCH, ne v cizí měně.
     */
    public function testForeignInvoicePaidInCzkIsConvertedAndReportedInCzk(): void
    {
        $rate = 24.36;
        $id = $this->purchaseInCurrency('2092-01-15', 200.0, 'EUR', $rate);
        $this->payFromBank($id, 100.0 * $rate);

        $rows = $this->service->preview($this->supplierId, self::YEAR, '2094-12-31')['rows'];

        self::assertCount(1, $rows);
        self::assertEqualsWithDelta(0.5, $rows[0]['unpaid_ratio'], 0.0001, 'Uhrazena přesně polovina.');
        self::assertEqualsWithDelta(
            100.0 * $rate,
            $rows[0]['unpaid'],
            0.5,
            'Do základu daně jde částka v CZK, ne 100 EUR.',
        );
    }

    /** Částečná úhrada → připočte se jen neuhrazená část. */
    public function testPartialPaymentAddsBackOnlyUnpaidPart(): void
    {
        $this->purchase('2092-01-15', 121000.0, advance: 21000.0);

        $p = $this->service->preview($this->supplierId, self::YEAR, '2094-12-31');

        self::assertEqualsWithDelta(100000.0, $p['net_delta'], 0.01);
    }

    /**
     * PROTISTRANA § 23/3/c/6. Bez ní by poplatník zaplatil daň dvakrát — jednou
     * z připočtení, podruhé tím, že by se snížení nikdy neuplatnilo.
     */
    public function testPaymentAfterAddbackProducesDecrease(): void
    {
        $id = $this->purchase('2092-01-15', 121000.0);
        $this->service->record($this->supplierId, self::YEAR, '2094-12-31', [], $this->userId);

        // Následující rok dluh uhradí.
        $this->markPaid($id);
        $p = $this->service->preview($this->supplierId, self::YEAR + 1, '2095-12-31');

        self::assertSame('decrease', $p['rows'][0]['movement']);
        self::assertSame(121000.0, $p['total_decrease']);
        self::assertSame(-121000.0, $p['net_delta']);
    }

    /** Po zaevidování obou pohybů je čistý stav nula — nic dalšího se neopakuje. */
    public function testAfterDecreaseNettingIsZero(): void
    {
        $id = $this->purchase('2092-01-15', 121000.0);
        $this->service->record($this->supplierId, self::YEAR, '2094-12-31', [], $this->userId);
        $this->markPaid($id);
        $this->service->record($this->supplierId, self::YEAR + 1, '2095-12-31', [], $this->userId);

        self::assertSame([], $this->service->preview($this->supplierId, self::YEAR + 2, '2096-12-31')['rows']);
    }

    /** Opakovaný náhled po zaevidování už připočtení nenabízí — netting drží. */
    public function testRecordedAddbackIsNotOfferedAgain(): void
    {
        $this->purchase('2092-01-15', 121000.0);
        $this->service->record($this->supplierId, self::YEAR, '2094-12-31', [], $this->userId);

        $p = $this->service->preview($this->supplierId, self::YEAR, '2094-12-31');

        self::assertSame(0.0, $p['net_delta'], 'Cílový stav je už evidovaný, delta musí být nula.');
    }

    /** Účetní může doklad z připočtení vyloučit (nedaňový titul, insolvence, sankce). */
    public function testAccountantCanExcludeInvoiceFromAddback(): void
    {
        $keep = $this->purchase('2092-01-15', 121000.0);
        $this->purchase('2092-02-15', 50000.0);

        $this->service->record($this->supplierId, self::YEAR, '2094-12-31', [$keep], $this->userId);

        $recorded = $this->service->recordedForYear($this->supplierId, self::YEAR);
        self::assertSame(121000.0, $recorded['increase'], 'Zaeviduje se jen ponechaný doklad.');
    }

    /**
     * Snížení po úhradě se NEFILTRUJE. Jakmile bylo něco připočteno, protistrana musí
     * proběhnout vždy — jinak zůstane základ trvale nadhodnocený.
     */
    public function testDecreaseIsNotFilteredOut(): void
    {
        $id = $this->purchase('2092-01-15', 121000.0);
        $this->service->record($this->supplierId, self::YEAR, '2094-12-31', [], $this->userId);
        $this->markPaid($id);

        // Filtr obsahuje jiný (neexistující) doklad — snížení musí projít i tak.
        $this->service->record($this->supplierId, self::YEAR + 1, '2095-12-31', [999999], $this->userId);

        self::assertSame(121000.0, $this->service->recordedForYear($this->supplierId, self::YEAR + 1)['decrease']);
    }

    /** Podklad pro ř. 30 / ř. 160 čte LEDGER, ne návrh. */
    public function testRecordedForYearReadsLedgerNotPreview(): void
    {
        $this->purchase('2092-01-15', 121000.0);

        self::assertSame(['increase' => 0.0, 'decrease' => 0.0],
            $this->service->recordedForYear($this->supplierId, self::YEAR),
            'Dokud se nezaeviduje, do přiznání nic nejde.');

        $this->service->record($this->supplierId, self::YEAR, '2094-12-31', [], $this->userId);

        self::assertSame(121000.0, $this->service->recordedForYear($this->supplierId, self::YEAR)['increase']);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function purchase(string $dueDate, float $withVat, float $advance = 0.0, bool $paid = false): int
    {
        $this->seq++;
        $vat = round($withVat - $withVat / 1.21, 2);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, advance_paid_amount, status, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId, $this->vendorId, 'PF-' . $this->seq,
            $dueDate, $dueDate, $dueDate, $dueDate, $this->currencyId,
            round($withVat - $vat, 2), $vat, $withVat, $advance,
            $paid ? 'paid' : 'received', $this->userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Spárovaná platba BEZ zaúčtovaného bankovního zápisu — přesně stav, který
     * detektor zahazoval. Peníze odešly bez ohledu na to, jestli je někdo zaúčtoval.
     */
    private function payFromBank(int $invoiceId, float $amount, string $currency = 'CZK'): void
    {
        $pdo = $this->db->pdo();
        $this->seq++;
        $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, currency, statement_date)
             VALUES (?, "ul-test.gpc", ?, "123456789/0100", ?, "2020-01-31")'
        )->execute([$this->supplierId, hash('sha256', 'ul' . $this->seq . microtime(true)), $currency]);
        $statementId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, counterparty_name, match_status)
             VALUES (?, "2020-01-31", ?, ?, "Dodavatel", "manual")'
        )->execute([$statementId, -$amount, $currency]);
        $txId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type)
             VALUES (?, ?, ?, ?, "manual")'
        )->execute([$this->supplierId, $txId, $invoiceId, $amount]);
    }

    /** Přijatá faktura v cizí měně s kurzem. */
    private function purchaseInCurrency(string $dueDate, float $withVat, string $code, float $rate): int
    {
        $id = $this->purchase($dueDate, $withVat);
        $curId = (int) $this->db->pdo()->query("SELECT id FROM currencies WHERE code = '$code' LIMIT 1")->fetchColumn();
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET currency_id = ?, exchange_rate = ? WHERE id = ?')
            ->execute([$curId, $rate, $id]);

        return $id;
    }

    private function markPaid(int $invoiceId): void
    {
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET status = "paid" WHERE id = ?')
            ->execute([$invoiceId]);
    }
}
