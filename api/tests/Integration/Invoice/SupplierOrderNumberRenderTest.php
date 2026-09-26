<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Číslo objednávky (`invoices.supplier_order_number`) se tiskne v hlavičce dokladu (#99).
 *
 * Render jde přes reálnou šablonu nad syntetickým polem faktury, do DB se nic nezapisuje.
 */
#[Group('integration')]
final class SupplierOrderNumberRenderTest extends TestCase
{
    private InvoicePdfRenderer $renderer;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->renderer = Bootstrap::buildContainer()->get(InvoicePdfRenderer::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
    }

    public function testOrderNumberIsPrintedInInvoiceHeader(): void
    {
        $html = $this->renderer->renderHtml($this->invoice('OBJ-2026-042'), includeCss: false);

        self::assertMatchesRegularExpression('~Objednávka</td>\s*<td[^>]*>OBJ-2026-042</td>~u', $html);
    }

    public function testEnglishInvoiceUsesEnglishLabel(): void
    {
        $html = $this->renderer->renderHtml($this->invoice('OBJ-2026-042', language: 'en'), includeCss: false);

        self::assertMatchesRegularExpression('~Order no\.</td>\s*<td[^>]*>OBJ-2026-042</td>~u', $html);
    }

    public function testInvoiceWithoutOrderNumberHasNoOrderRow(): void
    {
        $html = $this->renderer->renderHtml($this->invoice(null), includeCss: false);

        self::assertStringNotContainsString('Objednávka', $html);
    }

    /** Doklad z prodejní objednávky nese číslo i v poznámce — podruhé se netiskne. */
    public function testOrderNumberAlreadyInNoteIsNotDuplicated(): void
    {
        $html = $this->renderer->renderHtml(
            $this->invoice('OBJ-2026-042', note: 'Objednávka: OBJ-2026-042'),
            includeCss: false,
        );

        self::assertSame(1, substr_count($html, 'OBJ-2026-042'));
    }

    /** @return array<string,mixed> */
    private function invoice(?string $orderNumber, string $language = 'cs', ?string $note = null): array
    {
        return [
            'id'                    => 0,
            'invoice_type'          => 'invoice',
            'status'                => 'issued',
            'language'              => $language,
            'currency'              => 'CZK',
            'currency_id'           => 0,
            'client_id'             => 0,
            'supplier_id'           => 0,
            'varsymbol'             => 'ORDTEST1',
            'supplier_order_number' => $orderNumber,
            'note_below_items'      => $note,
            'issue_date'            => '2026-07-01',
            'tax_date'              => '2026-07-01',
            'due_date'              => '2026-07-15',
            'paid_at'               => null,
            'amount_to_pay'         => 121.0,
            'paid_total'            => 0.0,
            'parent_invoice_id'     => null,
            'payment_method'        => 'bank_transfer',
            'prices_include_vat'    => false,
            'reverse_charge'        => false,
            'branding_profile_id'   => null,
            'advance_paid_amount'   => 0.0,
            'czk_recap'             => null,
            'supplier_snapshot'     => json_encode([
                'company_name' => 'Testovací dodavatel',
                'street'       => 'Zkušební 1',
                'city'         => 'Praha',
                'zip'          => '11000',
                'is_vat_payer' => true,
            ], JSON_UNESCAPED_UNICODE),
            'client_snapshot'       => json_encode([
                'company_name' => 'Testovací odběratel',
                'street'       => 'Testovací 2',
                'city'         => 'Brno',
                'zip'          => '60200',
                'country_iso2' => 'CZ',
            ], JSON_UNESCAPED_UNICODE),
            'bank_snapshot'         => null,
            'items'                 => [[
                'description'            => 'Testovací plnění',
                'quantity'               => 1.0,
                'unit'                   => 'ks',
                'unit_price_without_vat' => 100.0,
                'vat_rate_snapshot'      => 21.0,
                'total_without_vat'      => 100.0,
                'total_vat'              => 21.0,
                'total_with_vat'         => 121.0,
                'item_kind'              => 'standard',
                'oss_applicable'         => false,
                'oss_consumer_country'   => null,
            ]],
            'totals'                => ['without_vat' => 100.0, 'vat' => 21.0, 'with_vat' => 121.0],
            'vat_breakdown'         => [],
        ];
    }
}
