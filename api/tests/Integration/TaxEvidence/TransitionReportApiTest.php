<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Action\TaxEvidence\TransitionReportAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Integrace přechodového můstku §7b→§24 ZDP (příloha č. 3 ZDP, Epic DE, audit
 * 2026-07 G7): neuhrazená FV a PF k datu přechodu se objeví v podkladech se
 * správnými součty, zaplacené/stornované doklady se vynechají a report nikdy
 * neprosákne mezi tenanty (vzor TaxEvidenceApiTest).
 */
#[Group('integration')]
final class TransitionReportApiTest extends CashJournalTestCase
{
    private TransitionReportAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->container->get(TransitionReportAction::class);
    }

    public function testUnpaidInvoicesAndPurchaseInvoicesSummedCorrectly(): void
    {
        // Sklad vypnutý — deterministická cesta „doplňte ručně" bez ohledu na to,
        // jestli klonovaný template supplier měl stock_enabled zapnutý.
        $this->db->pdo()->prepare('UPDATE supplier SET stock_enabled = 0 WHERE id = ?')->execute([$this->supplierId]);

        // Neuhrazená vydaná faktura (pohledávka) — vstupuje do reportu celou částkou.
        $this->saleInvoice($this->supplierId, ['without' => 8000.0, 'with' => 9000.0, 'status' => 'issued']);
        // Zaplacená vydaná faktura — NESMÍ se objevit.
        $this->saleInvoice($this->supplierId, ['without' => 5000.0, 'with' => 5000.0, 'status' => 'paid', 'paid_at' => self::YEAR . '-05-01']);

        // Neuhrazená přijatá faktura (závazek).
        $this->purchaseInvoice($this->supplierId, ['without' => 2000.0, 'with' => 2200.0, 'status' => 'received']);
        // Zaplacená přijatá faktura — NESMÍ se objevit.
        $this->purchaseInvoice($this->supplierId, ['without' => 1000.0, 'with' => 1000.0, 'status' => 'paid', 'paid_at' => self::YEAR . '-05-01']);

        $res = $this->call(self::YEAR . '-12-31');

        self::assertSame(200, $res['status']);
        self::assertCount(1, $res['body']['receivables']);
        self::assertCount(1, $res['body']['payables']);
        self::assertEqualsWithDelta(9000.0, (float) $res['body']['receivables'][0]['amount_czk'], 0.01);
        self::assertEqualsWithDelta(2200.0, (float) $res['body']['payables'][0]['amount_czk'], 0.01);
        self::assertEqualsWithDelta(9000.0, (float) $res['body']['totals']['receivables_czk'], 0.01);
        self::assertEqualsWithDelta(2200.0, (float) $res['body']['totals']['payables_czk'], 0.01);
        self::assertEqualsWithDelta(6800.0, (float) $res['body']['totals']['net_adjustment_czk'], 0.01);
        self::assertArrayHasKey('inventory', $res['body']);
        self::assertFalse($res['body']['inventory']['enabled']);
    }

    public function testInvoiceIssuedAfterAsOfIsExcluded(): void
    {
        $this->saleInvoice($this->supplierId, ['without' => 1000.0, 'with' => 1000.0, 'status' => 'issued', 'issue_date' => self::YEAR . '-08-01']);

        $res = $this->call(self::YEAR . '-06-30');

        self::assertSame(200, $res['status']);
        self::assertCount(0, $res['body']['receivables']);
    }

    public function testInvoicePaidAfterAsOfStillCountsAsUnpaidAtAsOf(): void
    {
        // Faktura vystavená v červnu, zaplacená až v listopadu — report spuštěný
        // se stavem "K DATU PŘECHODU" (30.6.) ji musí vidět jako neuhrazenou,
        // i když je report vyvolán (a status/paid_total v DB je) až po listopadu.
        $this->saleInvoice($this->supplierId, [
            'without' => 4000.0, 'with' => 4000.0, 'status' => 'paid',
            'issue_date' => self::YEAR . '-06-01', 'paid_at' => self::YEAR . '-11-15',
        ]);

        $res = $this->call(self::YEAR . '-06-30');

        self::assertSame(200, $res['status']);
        self::assertCount(1, $res['body']['receivables']);
        self::assertEqualsWithDelta(4000.0, (float) $res['body']['receivables'][0]['amount_czk'], 0.01);
    }

    public function testPurchaseInvoicePaidAfterAsOfStillCountsAsUnpaidAtAsOf(): void
    {
        $this->purchaseInvoice($this->supplierId, [
            'without' => 900.0, 'with' => 1000.0, 'status' => 'paid',
            'issue_date' => self::YEAR . '-06-01', 'paid_at' => self::YEAR . '-11-15',
        ]);

        $res = $this->call(self::YEAR . '-06-30');

        self::assertSame(200, $res['status']);
        self::assertCount(1, $res['body']['payables']);
        self::assertEqualsWithDelta(1000.0, (float) $res['body']['payables'][0]['amount_czk'], 0.01);
    }

    public function testInventoryValuationWhenStockEnabled(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET stock_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);

        $res = $this->call(self::YEAR . '-12-31');

        self::assertSame(200, $res['status']);
        self::assertTrue($res['body']['inventory']['enabled']);
        self::assertEqualsWithDelta(0.0, (float) $res['body']['inventory']['value_czk'], 0.01, 'Bez skladových pohybů je ocenění 0 Kč.');
    }

    public function testInvalidAsOfReturns422(): void
    {
        $res = $this->call('not-a-date');
        self::assertSame(422, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code']);
    }

    public function testWrongAccountingModeReturns403(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET accounting_mode = ? WHERE id = ?')
            ->execute(['double_entry', $this->supplierId]);

        $res = $this->call(self::YEAR . '-12-31');
        self::assertSame(403, $res['status']);
        self::assertSame('transition_not_applicable', $res['body']['error']['code']);
    }

    public function testAccountingToTaxUsesAppendixTwoSigns(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET accounting_mode = ?, stock_enabled = 0 WHERE id = ?')
            ->execute(['double_entry', $this->supplierId]);
        $this->saleInvoice($this->supplierId, ['without' => 8000.0, 'with' => 9000.0, 'status' => 'issued']);
        $this->purchaseInvoice($this->supplierId, ['without' => 2000.0, 'with' => 2200.0, 'status' => 'received']);

        $res = $this->call(self::YEAR . '-12-31', null, 'accounting_to_tax');

        self::assertSame(200, $res['status']);
        self::assertSame('accounting_to_tax', $res['body']['direction']);
        self::assertSame('Příloha č. 2 ZDP', $res['body']['legal_basis']);
        self::assertEqualsWithDelta(-6800.0, (float) $res['body']['totals']['net_adjustment_czk'], 0.01);
        self::assertArrayNotHasKey('receivables_spread', $res['body']);
    }

    public function testForeignSupplierNeverSeesOwnInvoices(): void
    {
        $this->saleInvoice($this->supplierId, ['without' => 3000.0, 'with' => 3000.0, 'status' => 'issued']);

        $supplierB = $this->cloneSupplier('tax_evidence', true);
        $clientB   = $this->client($supplierB, 'Odběratel B s.r.o.');
        $currencyB = $this->currencyRow($supplierB, 'CZK', '990000333', '2010');
        $this->db->pdo()->prepare(
            "INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, income_tax_exempt, vat_classification_code, created_by)
             VALUES (?, ?, 'invoice', ?, ?, ?, ?, ?, 0, ?, 0, ?, 'issued', 0, '1', ?)"
        )->execute([
            $supplierB, (string) random_int(100000, 999999), $clientB,
            self::YEAR . '-06-10', self::YEAR . '-06-10', self::YEAR . '-06-10', $currencyB,
            50000.0, 50000.0, $this->userId,
        ]);

        $resA = $this->call(self::YEAR . '-12-31');
        self::assertCount(1, $resA['body']['receivables']);
        self::assertEqualsWithDelta(3000.0, (float) $resA['body']['totals']['receivables_czk'], 0.01, 'Supplier A nesmí vidět fakturu B (tenant leak).');

        $resB = $this->call(self::YEAR . '-12-31', $supplierB);
        self::assertCount(1, $resB['body']['receivables']);
        self::assertEqualsWithDelta(50000.0, (float) $resB['body']['totals']['receivables_czk'], 0.01);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $asOf, ?int $supplierId = null, string $direction = 'tax_to_accounting'): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/tax-evidence/transition-report')
            ->withQueryParams(['as_of' => $asOf, 'direction' => $direction])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly']);
        /** @var ResponseInterface $resp */
        $resp = $this->action->get($req, new Psr7Response());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
