<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Předplatné SaaS rozepisuje v ceně zahrnuté kvóty jako řádky za $0.00. Do dokladu
 * nepatří: základ ani DPH nemění a každý z nich přidával odrážku do hlášení
 * o druhu nákladu. Sleva (záporný řádek) zůstává.
 */
#[Group('integration')]
final class AiExtractionZeroLinesTest extends StockTestCase
{
    public function testZeroAmountLinesAreNotImported(): void
    {
        $id = $this->createDraft([
            ['description' => 'Subscription to Team', 'quantity' => 1, 'unit_price_without_vat' => 29.00, 'vat_rate' => 21],
            ['description' => '50,000 reserved errors', 'quantity' => 1, 'unit_price_without_vat' => 0, 'vat_rate' => 21],
            ['description' => '5 GB reserved logs', 'quantity' => 1, 'unit_price_without_vat' => 0.0, 'line_total_without_vat' => 0, 'vat_rate' => 21],
            ['description' => '149 pay-as-you-go replays', 'quantity' => 149, 'unit_price_without_vat' => 0.0037584, 'line_total_without_vat' => 0.56, 'vat_rate' => 21],
            ['description' => 'Promotional Discount', 'quantity' => 1, 'unit_price_without_vat' => -14.78, 'vat_rate' => 21],
        ], 14.78);

        $rows = $this->items($id);
        self::assertSame(
            ['Subscription to Team', '149 pay-as-you-go replays', 'Promotional Discount'],
            array_column($rows, 'description'),
        );
        self::assertSame([0, 1, 2], array_map('intval', array_column($rows, 'order_index')));

        $warning = (string) $this->db->pdo()->query('SELECT extraction_warning FROM purchase_invoices WHERE id = ' . $id)->fetchColumn();
        self::assertStringNotContainsString('reserved errors', $warning);
        self::assertStringNotContainsString('reserved logs', $warning);
    }

    public function testDocumentWithOnlyZeroLinesKeepsThem(): void
    {
        $id = $this->createDraft([
            ['description' => 'Free plan', 'quantity' => 1, 'unit_price_without_vat' => 0, 'vat_rate' => 0],
            ['description' => 'Free support', 'quantity' => 1, 'unit_price_without_vat' => 0, 'vat_rate' => 0],
        ], 0.0);

        self::assertCount(2, $this->items($id));
    }

    public function testZeroAmountLineDetection(): void
    {
        self::assertTrue(AiPdfExtractor::isZeroAmountLine(['quantity' => 1, 'unit_price_without_vat' => 0]));
        self::assertTrue(AiPdfExtractor::isZeroAmountLine(['quantity' => 0, 'unit_price_without_vat' => 120]));
        self::assertTrue(AiPdfExtractor::isZeroAmountLine(['quantity' => 1, 'unit_price_without_vat' => 0.001]));
        self::assertFalse(AiPdfExtractor::isZeroAmountLine(['quantity' => 1, 'unit_price_without_vat' => -5]));
        self::assertFalse(AiPdfExtractor::isZeroAmountLine(['quantity' => 1, 'unit_price_without_vat' => 0, 'line_total_without_vat' => 250]));
    }

    /** @param list<array<string,mixed>> $items */
    private function createDraft(array $items, float $total): int
    {
        $sid = $this->createSupplier();
        $vendor = $this->client($sid, 'Fixture SaaS vendor');
        $extractor = $this->container->get(AiPdfExtractor::class);
        return (new \ReflectionMethod($extractor, 'createDraft'))->invoke($extractor, [
            'document_kind' => 'invoice', 'vendor_invoice_number' => 'FIXTURE-ZERO-' . count($items),
            'currency' => 'CZK', 'issue_date' => '2026-08-31', 'tax_date' => '2026-08-31', 'due_date' => '2026-09-15',
            'items' => $items,
            'total_without_vat' => $total, 'total_with_vat' => round($total * 1.21, 2), 'unit_prices_include_vat' => false,
        ], $sid, $this->userId, $vendor, true);
    }

    /** @return list<array<string,mixed>> */
    private function items(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT description, order_index FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY order_index');
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
