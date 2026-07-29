<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eshop;

use MyInvoice\Repository\ManufacturerRepository;
use MyInvoice\Service\Eshop\ProductImportService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Epic ESHOP F3 — import zboží z CSV: dry-run vs ostrý zápis, update, výrobce
 * resolution, all-or-nothing (chybný řádek zruší celý zápis).
 */
#[Group('integration')]
final class EshopImportTest extends StockTestCase
{
    private ProductImportService $import;
    private ManufacturerRepository $manufacturers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->import = $this->container->get(ProductImportService::class);
        $this->manufacturers = $this->container->get(ManufacturerRepository::class);
    }

    private function imp(int $sid, string $csv, bool $dryRun): array
    {
        return $this->import->import($sid, $this->userId, $csv, 'zbozi.csv', $dryRun);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $sid = $this->createSupplier();
        $csv = "sku;nazev;cena;skladem\nIMP-1;Zboží 1;199,90;ano\n";

        $report = $this->imp($sid, $csv, true);
        self::assertTrue($report['ok']);
        self::assertTrue($report['dry_run']);
        self::assertSame(1, $report['created']);
        self::assertNull($this->itemsRepo->findBySku($sid, 'IMP-1'), 'dry-run nesmí zapisovat');
    }

    public function testRealRunCreatesGoods(): void
    {
        $sid = $this->createSupplier();
        $csv = "sku;nazev;cena;skladem;export_eshop\nIMP-2;Zboží 2;1 234,50;ne;ano\n";

        $report = $this->imp($sid, $csv, false);
        self::assertTrue($report['ok']);
        self::assertSame(1, $report['created']);

        $item = $this->itemsRepo->findBySku($sid, 'IMP-2');
        self::assertNotNull($item);
        self::assertSame('goods', $item['item_type']);
        self::assertSame('1234.50', $item['sale_price_without_vat']);
        self::assertFalse($item['is_stocked']);
        self::assertTrue($item['export_eshop']);
    }

    public function testUpdateExistingBySku(): void
    {
        $sid = $this->createSupplier();
        $this->imp($sid, "sku;nazev;cena\nIMP-3;Původní;100\n", false);

        $report = $this->imp($sid, "sku;nazev;cena\nIMP-3;Nový název;150\n", false);
        self::assertSame(1, $report['updated']);
        $item = $this->itemsRepo->findBySku($sid, 'IMP-3');
        self::assertSame('Nový název', $item['name']);
        self::assertSame('150.00', $item['sale_price_without_vat']);
    }

    public function testManufacturerResolutionAndUnknownError(): void
    {
        $sid = $this->createSupplier();
        $this->manufacturers->insert($sid, ['code' => 'ACME', 'name' => 'Acme s.r.o.']);

        $ok = $this->imp($sid, "sku;nazev;vyrobce\nIMP-4;S výrobcem;ACME\n", false);
        self::assertSame(1, $ok['created']);
        $item = $this->itemsRepo->findBySku($sid, 'IMP-4');
        self::assertNotNull($item['manufacturer_id']);

        $bad = $this->imp($sid, "sku;nazev;vyrobce\nIMP-5;Neznámý výrobce;NOPE\n", false);
        self::assertFalse($bad['ok']);
        self::assertSame(1, $bad['failed']);
        self::assertNull($this->itemsRepo->findBySku($sid, 'IMP-5'));
    }

    public function testCaseInsensitiveDuplicateSkuRejected(): void
    {
        $sid = $this->createSupplier();
        $csv = "sku;nazev;cena\nDUP;První;100\ndup;Druhý;200\n";

        $report = $this->imp($sid, $csv, false);
        self::assertFalse($report['ok'], 'ABC/abc je v DB (CI collation) duplicita');
        self::assertSame(1, $report['failed']);
        self::assertNull($this->itemsRepo->findBySku($sid, 'DUP'));
    }

    public function testNegativePriceRejected(): void
    {
        $sid = $this->createSupplier();
        $report = $this->imp($sid, "sku;nazev;cena\nNEG-1;Záporná;-50\n", false);
        self::assertFalse($report['ok']);
        self::assertSame(1, $report['failed']);
        self::assertNull($this->itemsRepo->findBySku($sid, 'NEG-1'));
    }

    public function testAllOrNothingRollbackOnBadRow(): void
    {
        $sid = $this->createSupplier();
        // Řádek 1 validní (create), řádek 2 chybný (nové zboží bez názvu).
        $csv = "sku;nazev;cena\nIMP-OK;Dobrý;100\nIMP-BAD;;200\n";

        $report = $this->imp($sid, $csv, false);
        self::assertFalse($report['ok']);
        self::assertSame(1, $report['failed']);
        // All-or-nothing: ani validní řádek se nezapsal.
        self::assertNull($this->itemsRepo->findBySku($sid, 'IMP-OK'), 'chybný řádek ruší celý import');
    }
}
