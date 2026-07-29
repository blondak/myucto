<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Repository\ExpenseClassificationRuleRepository;
use MyInvoice\Service\Accounting\Expense\RecurringPrepaidSuggestionService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Automatizace 2026 — návrh časového rozlišení ročního předplatného (381) z pravidel s příznakem
 * `recurring_prepaid` (migrace 1102). Read-only: služba jen NAVRHUJE accrual_from/to, uzávěrka
 * (ClosingService) je pak podle nich odloží na 381. Testuje se spárování dodavatele, hranice
 * pololetí, respekt k už vyplněnému rozlišení a tenant izolace.
 */
#[Group('integration')]
final class RecurringPrepaidSuggestionTest extends BankPostingTestCase
{
    private ExpenseClassificationRuleRepository $repo;
    private RecurringPrepaidSuggestionService $recurring;
    private int $vatRateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = $this->container->get(ExpenseClassificationRuleRepository::class);
        $this->recurring = $this->container->get(RecurringPrepaidSuggestionService::class);
        $this->vatRateId = (int) ($this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            self::markTestSkipped('Chybí vat_rates v DB.');
        }
    }

    public function testSuggestsAccrualForRecurringPrepaidVendorInSecondHalf(): void
    {
        $this->recurringRule('Ukázka — cloud (roční 381)', 'Ukázka');
        $vendorId = $this->client('Ukázka a.s.');
        $pf = $this->purchase('PF-Ukázka', $vendorId, self::YEAR . '-09-01', [
            ['Cloud předplatné na rok', null, null, null],
        ]);

        $out = $this->recurring->suggestForInvoice($this->supplierId, $pf);
        $item = $this->firstFor($pf, $out);

        self::assertNotNull($item, 'Dodavatel s recurring_prepaid pravidlem má dostat návrh.');
        self::assertSame(self::YEAR . '-09-01', $item['accrual_from']);
        self::assertSame((self::YEAR + 1) . '-08-31', $item['accrual_to']);
        self::assertSame('recurring_rule', $item['source']);
        self::assertStringContainsString('Ukázka', $item['rule_name']);
    }

    public function testNoSuggestionForFirstHalfInvoice(): void
    {
        $this->recurringRule('Ukázka — cloud', 'Ukázka');
        $vendorId = $this->client('Ukázka a.s.');
        $pf = $this->purchase('PF-Ukázka-H1', $vendorId, self::YEAR . '-03-01', [
            ['Cloud předplatné na rok', null, null, null],
        ]);

        self::assertSame([], $this->recurring->suggestForInvoice($this->supplierId, $pf));
    }

    public function testVendorWithoutRecurringRuleGetsNoSuggestion(): void
    {
        // Pravidlo existuje, ale recurring_prepaid = 0 → žádný návrh rozlišení.
        $this->repo->insert($this->supplierId, [
            'name' => 'Vodafone — telco', 'vendor_name_contains' => 'Vodafone',
            'expense_kind' => 'service', 'recurring_prepaid' => 0,
        ], $this->userId);
        $vendorId = $this->client('Vodafone Czech Republic a.s.');
        $pf = $this->purchase('PF-VF', $vendorId, self::YEAR . '-09-01', [['Vyúčtování', null, null, null]]);

        self::assertSame([], $this->recurring->suggestForInvoice($this->supplierId, $pf));
    }

    public function testDoesNotOverwriteExistingAccrual(): void
    {
        $this->recurringRule('Allianz — pojistné (roční 381)', 'Allianz');
        $vendorId = $this->client('Allianz pojišťovna, a.s.');
        $pf = $this->purchase('PF-ALZ', $vendorId, self::YEAR . '-10-01', [
            ['Pojistné odpovědnosti', 'service', self::YEAR . '-10-01', (self::YEAR + 1) . '-09-30'],
        ]);

        self::assertSame([], $this->recurring->suggestForInvoice($this->supplierId, $pf),
            'Účetní už rozlišení vyplnila — návrh ho nepřepisuje.');
    }

    public function testSkipsNonAccruableKind(): void
    {
        $this->recurringRule('Ukázka', 'Ukázka');
        $vendorId = $this->client('Ukázka a.s.');
        // I když dodavatel sedí, věcný druh výdaje (drobný majetek) se nerozlišuje.
        $pf = $this->purchase('PF-Ukázka-ASSET', $vendorId, self::YEAR . '-09-01', [
            ['Externí disk', 'small_asset', null, null],
        ]);

        self::assertSame([], $this->recurring->suggestForInvoice($this->supplierId, $pf));
    }

    public function testForeignSupplierInvoiceYieldsNothing(): void
    {
        $this->recurringRule('Ukázka', 'Ukázka');
        $vendorId = $this->client('Ukázka a.s.');
        $pf = $this->purchase('PF-Ukázka-FOREIGN', $vendorId, self::YEAR . '-09-01', [['Cloud', null, null, null]]);

        self::assertSame([], $this->recurring->suggestForInvoice($this->otherSupplierId(), $pf),
            'Cizí tenant nesmí dostat návrh k našemu dokladu.');
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    private function recurringRule(string $name, string $vendorContains): int
    {
        return $this->repo->insert($this->supplierId, [
            'name' => $name,
            'vendor_name_contains' => $vendorContains,
            'expense_kind' => 'service',
            'recurring_prepaid' => 1,
        ], $this->userId);
    }

    /**
     * @param list<array{0:string,1:?string,2:?string,3:?string}> $items [popis, expense_kind, accrual_from, accrual_to]
     */
    private function purchase(string $number, int $vendorId, string $taxDate, array $items): int
    {
        $base = 0.0;
        foreach ($items as $it) {
            $base += 1000.0;
        }
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", "invoice", "full", ?, ?, ?, ?, ?, NULL, 0, 0, ?, 0, ?, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $taxDate, $taxDate, $taxDate, $taxDate,
            $this->currencyId, round($base, 2), round($base, 2), $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();

        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code, expense_kind, accrual_from, accrual_to)
             VALUES (?, ?, 1, 'ks', 1000.00, ?, 21.00, 1000.00, 0, 1000.00, ?, '40', ?, ?, ?)"
        );
        foreach (array_values($items) as $i => [$desc, $kind, $from, $to]) {
            $stmt->execute([$id, $desc, $this->vatRateId, $i, $kind, $from, $to]);
        }
        return $id;
    }

    /**
     * @param array<int,array<string,mixed>> $suggestions
     * @return array<string,mixed>|null
     */
    private function firstFor(int $purchaseInvoiceId, array $suggestions): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY order_index, id');
        $stmt->execute([$purchaseInvoiceId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $itemId) {
            if (isset($suggestions[(int) $itemId])) {
                return $suggestions[(int) $itemId];
            }
        }
        return null;
    }
}
