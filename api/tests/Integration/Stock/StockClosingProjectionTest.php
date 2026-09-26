<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Tax\Return\DppoReturnDataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Nezaúčtovaný krok „Zásoby" (způsob B) je v projekci uzávěrky náhledu DPPO; po jeho
 * zaúčtování už je ve VH, projekce ho nesmí přičíst znovu a projektovaný VH se nemění.
 */
#[Group('integration')]
final class StockClosingProjectionTest extends StockTestCase
{
    private const YEAR = 2097;

    public function testUnpostedStockClosingIsProjectedOnceAndNotAfterPosting(): void
    {
        /** @var ClosingService $closing */
        $closing = $this->container->get(ClosingService::class);
        /** @var AccountingPeriodRepository $periods */
        $periods = $this->container->get(AccountingPeriodRepository::class);
        /** @var DppoReturnDataProvider $dppo */
        $dppo = $this->container->get(DppoReturnDataProvider::class);

        $supplierId = $this->createSupplier('double_entry');
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $whId = $this->warehouse($supplierId);
        $periodId = $periods->create($supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->receiveStock($supplierId, $whId, $this->item($supplierId, 'MAT-P', 'material'), '10.000', 10.0, self::YEAR . '-03-01');
        $this->receiveStock($supplierId, $whId, $this->item($supplierId, 'GDS-P', 'goods'), '5.000', 20.0, self::YEAR . '-03-02');

        $before = $dppo->gather($supplierId, self::YEAR)['closing_projection'];
        $items = array_column($before['items'], null, 'key');
        self::assertArrayHasKey('stock_closing', $items);
        self::assertSame([200.0, 1, false], [$items['stock_closing']['amount'], $items['stock_closing']['sign'], $items['stock_closing']['optional']]);
        self::assertTrue($before['is_projection']);

        $meta = ['user_id' => $this->userId, 'posted_by' => $this->userId];
        $closing->start($supplierId, $periodId, (int) $periods->findById($supplierId, $periodId)['row_version'], $meta);
        $closing->runStockValuation($supplierId, $periodId, (int) $periods->findById($supplierId, $periodId)['row_version'], $meta);

        $after = $dppo->gather($supplierId, self::YEAR)['closing_projection'];
        self::assertArrayNotHasKey('stock_closing', array_column($after['items'], null, 'key'), 'Zaúčtovaný krok se znovu neprojektuje.');
        self::assertSame(round($before['vh_posted'] + 200.0, 2), $after['vh_posted'], 'Po zaúčtování je stav zásob ve VH právě jednou.');
        self::assertSame($before['vh_projected'], $after['vh_projected']);
    }
}
