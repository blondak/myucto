<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Vytěžený přijatý doklad nese období plnění položky (časové rozlišení 381): hodnotu
 * z modelu, a když ji model nevrátí, období rozpoznané z popisu položky.
 */
#[Group('integration')]
final class AiExtractionAccrualPeriodTest extends StockTestCase
{
    public function testModelPeriodTextFallbackAndInvalidValues(): void
    {
        $id = $this->createDraft([
            ['description' => 'Licence podle smlouvy', 'quantity' => 1, 'unit_price_without_vat' => 1200, 'vat_rate' => 21,
             'accrual_from' => '2026-10-01', 'accrual_to' => '2027-09-30'],
            ['description' => 'Pojištění, období 28. 9. 2026 – 27. 9. 2027', 'quantity' => 1, 'unit_price_without_vat' => 2400, 'vat_rate' => 21,
             'accrual_from' => null, 'accrual_to' => null],
            ['description' => 'Hosting 01/2027 - 12/2027', 'quantity' => 1, 'unit_price_without_vat' => 600, 'vat_rate' => 21,
             'accrual_from' => '2027-12-31', 'accrual_to' => '2027-01-01'],
            ['description' => 'Konzultace, DUZP 28. 9. 2026', 'quantity' => 1, 'unit_price_without_vat' => 800, 'vat_rate' => 21],
        ], 5000.0);

        self::assertSame([
            ['2026-10-01', '2027-09-30'],
            ['2026-09-28', '2027-09-27'],
            ['2027-01-01', '2027-12-31'],
            [null, null],
        ], $this->periods($id));
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    private function createDraft(array $items, float $total): int
    {
        $sid = $this->createSupplier();
        $vendor = $this->client($sid, 'Fixture accrual vendor');
        $extractor = $this->container->get(AiPdfExtractor::class);
        return (new \ReflectionMethod($extractor, 'createDraft'))->invoke($extractor, [
            'document_kind' => 'invoice', 'vendor_invoice_number' => 'FIXTURE-ACCRUAL-' . count($items),
            'currency' => 'CZK', 'issue_date' => '2026-09-28', 'tax_date' => '2026-09-28', 'due_date' => '2026-10-12',
            'items' => $items,
            'total_without_vat' => $total, 'total_with_vat' => round($total * 1.21, 2), 'unit_prices_include_vat' => false,
        ], $sid, $this->userId, $vendor, true);
    }

    /** @return list<array{?string,?string}> */
    private function periods(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT accrual_from, accrual_to FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY order_index'
        );
        $stmt->execute([$invoiceId]);
        return array_map(
            static fn (array $r): array => [$r['accrual_from'], $r['accrual_to']],
            $stmt->fetchAll(\PDO::FETCH_ASSOC),
        );
    }
}
