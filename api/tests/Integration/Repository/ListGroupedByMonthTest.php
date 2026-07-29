<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Repository;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Charakterizační (golden baseline) testy pro měsíční grupování výpisů faktur PŘED
 * přepisem SQL 10.6→11.8 (Epic SQL fáze 2). Zamykají:
 *   • InvoiceRepository::listGroupedByMonth — bucket = DATE_FORMAT(COALESCE(tax_date, issue_date))
 *     (R1/F3: COALESCE→effective_tax_date), pořadí měsíců DESC, součty per měna a draft-predikce,
 *   • PurchaseInvoiceRepository::listGroupedByMonth — bucket = DATE_FORMAT(issue_date)
 *     (F3: bare issue_date range), vyřazení draftu ze součtu, ale započtení do count.
 *
 * Izolace přes filtr client_id/vendor_id na čerstvě vytvořený subjekt → výstup je
 * deterministický (jen naše řádky). Transakce + rollback. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class ListGroupedByMonthTest extends TestCase
{
    private Connection $db;
    private InvoiceRepository $invoices;
    private PurchaseInvoiceRepository $purchases;
    private \PDO $pdo;

    private int $supplierId = 0;
    private int $czkId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->invoices = $container->get(InvoiceRepository::class);
            $this->purchases = $container->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->pdo = $this->db->pdo();

        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->czkId = (int) ($this->pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->czId === 0 || $this->czkId === 0) {
            $this->markTestSkipped('Chybí supplier/user/country/CZK.');
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testInvoicesGroupedByCoalesceMonth(): void
    {
        $client = $this->client('Grouped Klient', true, false);

        // 2001-06: issued 15. (1210), draft 20. (605), issued s tax v 06 ale issue v 05 (847).
        $this->invoice($client, '2001-06-15', '2001-06-15', 1000.0, 210.0, 1210.0, 'issued');
        $this->invoice($client, '2001-06-20', '2001-06-20', 500.0, 105.0, 605.0, 'draft');
        $this->invoice($client, '2001-05-28', '2001-06-03', 700.0, 147.0, 847.0, 'issued');
        // 2001-05: issued (2420).
        $this->invoice($client, '2001-05-10', '2001-05-10', 2000.0, 420.0, 2420.0, 'issued');

        $res = $this->invoices->listGroupedByMonth([
            'supplier_id' => $this->supplierId,
            'client_id'   => $client,
        ]);
        $data = $res['data'];
        self::assertCount(2, $data, 'Dva měsíční buckety (2001-06, 2001-05).');
        self::assertSame('2001-06', $data[0]['month'], 'Pořadí DESC — nejnovější měsíc první.');
        self::assertSame('2001-05', $data[1]['month']);

        // 2001-06: 3 doklady (2 issued + 1 draft).
        self::assertSame(3, $data[0]['count']);
        $june = $this->currencyTotals($data[0], 'CZK');
        self::assertEqualsWithDelta(1700.0, $june['without_vat'], 0.01, 'Červen net = 1000+700 (issued).');
        self::assertEqualsWithDelta(357.0, $june['vat'], 0.01, 'Červen DPH = 210+147.');
        self::assertEqualsWithDelta(2057.0, $june['with_vat'], 0.01, 'Červen s DPH = 1210+847.');
        self::assertEqualsWithDelta(605.0, $june['draft_with_vat'], 0.01, 'Draft-predikce červen = 605.');

        // 2001-05: 1 doklad.
        self::assertSame(1, $data[1]['count']);
        $may = $this->currencyTotals($data[1], 'CZK');
        self::assertEqualsWithDelta(2420.0, $may['with_vat'], 0.01, 'Květen s DPH = 2420.');
        self::assertEqualsWithDelta(0.0, $may['draft_with_vat'], 0.01, 'Květen bez draftu.');
    }

    /**
     * Dobropis obrat vždy SNIŽUJE (§ 4a ZDPH) — i když je zadaný s kladným součtem.
     *
     * Dřív se jen spoléhalo na to, že `credit_note` má záporné částky. Dobropis bez
     * negace (blokovaná je jen dvojí, viz InvoiceAmountPolicy) by obrat NAVÝŠIL,
     * a obrat rozhoduje o limitu registrace k DPH.
     */
    public function testCreditNoteAlwaysReducesTurnoverRegardlessOfSign(): void
    {
        $client = $this->client('Dobropis Klient', true, false);

        $this->invoice($client, '2002-03-10', '2002-03-10', 10000.0, 2100.0, 12100.0, 'issued');
        // Správně zadaný dobropis (záporné částky).
        $negative = $this->invoice($client, '2002-03-11', '2002-03-11', -1000.0, -210.0, -1210.0, 'issued');
        // Chybně zadaný dobropis (kladné částky) — musí se chovat stejně.
        $positive = $this->invoice($client, '2002-03-12', '2002-03-12', 2000.0, 420.0, 2420.0, 'issued');
        $this->pdo->prepare("UPDATE invoices SET invoice_type = 'credit_note' WHERE id IN (?, ?)")
            ->execute([$negative, $positive]);

        $res = $this->invoices->listGroupedByMonth([
            'supplier_id' => $this->supplierId,
            'client_id'   => $client,
        ]);
        $march = $this->currencyTotals($res['data'][0], 'CZK');

        self::assertEqualsWithDelta(7000.0, $march['without_vat'], 0.01, '10000 − 1000 − 2000, oba dobropisy odečteny.');
        self::assertEqualsWithDelta(1470.0, $march['vat'], 0.01, '2100 − 210 − 420.');
        self::assertEqualsWithDelta(8470.0, $march['with_vat'], 0.01, '12100 − 1210 − 2420.');
    }

    public function testPurchasesGroupedByIssueMonth(): void
    {
        $vendor = $this->client('Grouped Dodavatel', false, true);

        // 2001-06: received (968) + draft (605 — do count, ne do součtu).
        $this->purchase($vendor, '2001-06-15', '2001-06-15', 800.0, 168.0, 968.0, 'received');
        $this->purchase($vendor, '2001-06-20', '2001-06-20', 500.0, 105.0, 605.0, 'draft');
        // 2001-05: received (1815).
        $this->purchase($vendor, '2001-05-10', '2001-05-10', 1500.0, 315.0, 1815.0, 'received');

        $res = $this->purchases->listGroupedByMonth([
            'supplier_id' => $this->supplierId,
            'vendor_id'   => $vendor,
        ]);
        $data = $res['data'];
        self::assertCount(2, $data, 'Dva měsíce (2001-06, 2001-05).');
        self::assertSame('2001-06', $data[0]['month'], 'Pořadí DESC dle issue_date.');
        self::assertSame('2001-05', $data[1]['month']);

        // 2001-06: count=2 (received + draft), součet jen received.
        self::assertSame(2, $data[0]['count'], 'Draft se počítá do count.');
        $june = $this->currencyTotals($data[0], 'CZK');
        self::assertEqualsWithDelta(968.0, $june['with_vat'], 0.01, 'Červen náklad = jen received 968 (draft vyřazen).');
        self::assertEqualsWithDelta(800.0, $june['without_vat'], 0.01);

        self::assertSame(1, $data[1]['count']);
        $may = $this->currencyTotals($data[1], 'CZK');
        self::assertEqualsWithDelta(1815.0, $may['with_vat'], 0.01, 'Květen náklad = 1815.');
    }

    /** Hledání `q` musí najít doklad i podle TEXTU POLOŽKY, ne jen čísla/dodavatele. */
    public function testPurchaseSearchMatchesItemDescription(): void
    {
        if ((int) ($this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0) === 0) {
            self::markTestSkipped('Chybí vat_rates.');
        }
        $vendor = $this->client('Alza QSearch', false, true);
        $withItem = $this->purchase($vendor, '2001-07-10', '2001-07-10', 66033.06, 13866.94, 79900.0, 'received');
        $this->purchaseItem($withItem, 'Lenovo ThinkPad X1 Carbon Zoxxo', 66033.06);
        // Jiný doklad téhož dodavatele bez toho slova — nesmí se najít.
        $this->purchase($vendor, '2001-07-11', '2001-07-11', 500.0, 105.0, 605.0, 'received');

        $res = $this->purchases->listGroupedByMonth([
            'supplier_id' => $this->supplierId, 'vendor_id' => $vendor, 'q' => 'Zoxxo',
        ], 1, 50);

        $ids = $this->collectIds($res['data']);
        self::assertSame([$withItem], $ids, 'Najde se jen doklad, jehož POLOŽKA obsahuje hledaný text.');
        self::assertSame(1, $res['meta']['total'], 'Count (EXISTS, ne JOIN) sedí — doklad se nezmnoží dle počtu položek.');
    }

    public function testInvoiceSearchMatchesItemDescription(): void
    {
        if ((int) ($this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0) === 0) {
            self::markTestSkipped('Chybí vat_rates.');
        }
        $client = $this->client('Odberatel QSearch', true, false);
        $withItem = $this->invoice($client, '2001-07-10', '2001-07-10', 1000.0, 210.0, 1210.0, 'issued');
        $this->invoiceItem($withItem, 'Konzultace Ziqqurat pro klienta', 1000.0);
        $this->invoice($client, '2001-07-11', '2001-07-11', 500.0, 105.0, 605.0, 'issued');

        $res = $this->invoices->listGroupedByMonth([
            'supplier_id' => $this->supplierId, 'client_id' => $client, 'q' => 'Ziqqurat',
        ], 1, 50);

        $ids = $this->collectIds($res['data']);
        self::assertSame([$withItem], $ids, 'Vydaná faktura se najde dle textu položky.');
        self::assertSame(1, $res['meta']['total'], 'Count sdílí WHERE s hlavním dotazem — zůstává konzistentní.');
    }

    public function testInvoicesBookedFilter(): void
    {
        $client = $this->client('Booked Klient', true, false);
        $bookedId   = $this->invoice($client, '2001-06-15', '2001-06-15', 1000.0, 210.0, 1210.0, 'issued');
        $unbookedId = $this->invoice($client, '2001-06-16', '2001-06-16', 500.0, 105.0, 605.0, 'issued');
        $this->pdo->prepare('UPDATE invoices SET booked_at = NOW() WHERE id = ?')->execute([$bookedId]);

        // booked = '1' → jen zaúčtované (booked_at IS NOT NULL)
        $res = $this->invoices->listGroupedByMonth([
            'supplier_id' => $this->supplierId, 'client_id' => $client, 'booked' => '1',
        ]);
        self::assertSame([$bookedId], $this->collectIds($res['data']), 'booked=1 vrací jen zaúčtované.');

        // booked = '0' → jen nezaúčtované (booked_at IS NULL)
        $res = $this->invoices->listGroupedByMonth([
            'supplier_id' => $this->supplierId, 'client_id' => $client, 'booked' => '0',
        ]);
        self::assertSame([$unbookedId], $this->collectIds($res['data']), 'booked=0 vrací jen nezaúčtované.');

        // bez filtru (a prázdný řetězec) → obě
        $res = $this->invoices->listGroupedByMonth([
            'supplier_id' => $this->supplierId, 'client_id' => $client, 'booked' => '',
        ]);
        self::assertSame([$bookedId, $unbookedId], $this->collectIds($res['data']), 'booked="" = bez filtru.');
    }

    public function testPurchasesBookedFilter(): void
    {
        $vendor = $this->client('Booked Dodavatel', false, true);
        $bookedId   = $this->purchase($vendor, '2001-06-15', '2001-06-15', 800.0, 168.0, 968.0, 'received');
        $unbookedId = $this->purchase($vendor, '2001-06-16', '2001-06-16', 500.0, 105.0, 605.0, 'received');
        $this->pdo->prepare('UPDATE purchase_invoices SET booked_at = NOW() WHERE id = ?')->execute([$bookedId]);

        // booked = '1' → jen zaúčtované
        $res = $this->purchases->listGroupedByMonth([
            'supplier_id' => $this->supplierId, 'vendor_id' => $vendor, 'booked' => '1',
        ]);
        self::assertSame([$bookedId], $this->collectIds($res['data']), 'booked=1 vrací jen zaúčtované.');

        // booked = '0' → jen nezaúčtované
        $res = $this->purchases->listGroupedByMonth([
            'supplier_id' => $this->supplierId, 'vendor_id' => $vendor, 'booked' => '0',
        ]);
        self::assertSame([$unbookedId], $this->collectIds($res['data']), 'booked=0 vrací jen nezaúčtované.');

        // bez filtru → obě
        $res = $this->purchases->listGroupedByMonth([
            'supplier_id' => $this->supplierId, 'vendor_id' => $vendor,
        ]);
        self::assertSame([$bookedId, $unbookedId], $this->collectIds($res['data']), 'bez filtru = obě.');
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /**
     * Vytáhne (a seřadí) ID dokladů napříč měsíčními buckety.
     *
     * @param list<array<string,mixed>> $data
     * @return list<int>
     */
    private function collectIds(array $data): array
    {
        $ids = [];
        foreach ($data as $g) {
            foreach ($g['invoices'] as $row) {
                $ids[] = (int) $row['id'];
            }
        }
        sort($ids);
        return $ids;
    }

    /**
     * @param array<string,mixed> $group
     * @return array<string,float>
     */
    private function currencyTotals(array $group, string $cur): array
    {
        foreach ($group['totals_per_currency'] as $t) {
            if ($t['currency'] === $cur) {
                return array_map('floatval', $t);
            }
        }
        return [];
    }

    private function client(string $name, bool $customer, bool $vendor): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "grp@example.com", "cs", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->czkId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    private function invoice(int $clientId, string $issueDate, ?string $taxDate, float $net, float $vat, float $gross, string $status): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 1.0, 0, ?, ?, ?, ?, "1", ?)'
        );
        $stmt->execute([
            $this->supplierId, 'GRP-' . uniqid(), $clientId, $issueDate, $taxDate, $issueDate,
            $this->czkId, $net, $vat, $gross, $status, $this->userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function purchase(int $vendorId, string $issueDate, ?string $taxDate, float $net, float $vat, float $gross, string $status): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, ?, "invoice", ?, ?, ?, ?, ?, 1.0, 0, "{}", ?, ?, ?, ?, "40", "full", ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, 'GRPP-' . uniqid(), 'GRPP-' . uniqid(),
            $issueDate, $taxDate, $issueDate, $issueDate,
            $this->czkId, $net, $vat, $gross, $status, $this->userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function purchaseItem(int $purchaseInvoiceId, string $description, float $net): void
    {
        $vatRateId = (int) ($this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->pdo->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
             VALUES (?, ?, 1, 'ks', ?, ?, 21.00, ?, 0, ?, 0, '40')"
        )->execute([$purchaseInvoiceId, $description, $net, $vatRateId, $net, $net]);
    }

    private function invoiceItem(int $invoiceId, string $description, float $net): void
    {
        $vatRateId = (int) ($this->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->pdo->prepare(
            "INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, 1, 'ks', ?, ?, 21.00, ?, 0, ?, 0)"
        )->execute([$invoiceId, $description, $net, $vatRateId, $net, $net]);
    }
}
