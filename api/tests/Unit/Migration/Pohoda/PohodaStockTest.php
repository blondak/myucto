<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Action\Admin\Import\PohodaMigrationAction;
use MyInvoice\Service\Migration\Pohoda\PohodaImportJobService;
use MyInvoice\Service\Migration\Pohoda\PohodaStock;
use MyInvoice\Service\Migration\Shared\MigratedStockOpening;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaExport;
use PHPUnit\Framework\TestCase;

final class PohodaStockTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_stock_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0755, true);
        SyntheticPohodaExport::withStock($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/92_sklad.xml');
        @rmdir($this->dir);
    }

    public function testValueComesFromLastMovementAndFallsBackToAveragePrice(): void
    {
        $stock = PohodaStock::read($this->dir . '/92_sklad.xml');
        $cards = array_column($stock->cards, null, 'ID');
        // Průměrná cena 10,0444 × 120,5 = 1 210,35; ocenění POHODY po posledním pohybu je 1 210,37.
        self::assertSame(121037, $stock->valueCents($cards['101']));
        self::assertSame(33330, $stock->valueCents($cards['103']));
        self::assertSame('material', PohodaStock::kindOf($cards['103']));
        self::assertNull(PohodaStock::kindOf($cards['101']));
        self::assertSame(['Elektro', 'Kabely'], $stock->categoryPath($cards['101']));
        self::assertSame([], $stock->categoryPath($cards['102']));
        self::assertSame(2, $stock->components['105']);
        self::assertSame(
            [['Prodej', 0, 'CZK', false], ['B2B', 1, 'CZK', true], ['EUR', 2, 'EUR', false]],
            array_map(static fn (array $l): array => [$l['code'], $l['type'], $l['currency'], $l['vat_included']], $stock->priceLists),
        );
    }

    public function testStockDateIsExportDayWithinAgendaYear(): void
    {
        $stock = PohodaStock::read($this->dir . '/92_sklad.xml');
        self::assertSame('2026-06-30', $stock->stockDate(2026));
        // Starší agenda vede stav ke konci svého roku.
        self::assertSame('2025-12-31', $stock->stockDate(2025));
    }

    public function testSummaryPresetsConsignmentWarehouseToSkip(): void
    {
        $summary = PohodaStock::summary($this->dir . '/92_sklad.xml', 2026);
        self::assertSame(
            [['HL', 6, 3, 3, 'goods'], ['MAT', 2, 2, 1, 'goods'], ['KON', 1, 1, 1, 'skip']],
            array_map(static fn (array $w): array => [$w['code'], $w['cards'], $w['stocked'], $w['without_kind'], $w['suggested']], $summary['warehouses']),
        );
        self::assertSame([9, 2, '2026-06-30'], [$summary['cards'], $summary['price_lists'], $summary['date']]);
    }

    public function testUnitRatioAndEan(): void
    {
        self::assertSame([10, 1], PohodaStock::unitRatio('10'));
        self::assertSame([1, 2], PohodaStock::unitRatio('0.5'));
        self::assertSame([5, 8], PohodaStock::unitRatio('0.625'));
        self::assertNull(PohodaStock::unitRatio('0'));
        self::assertNull(PohodaStock::unitRatio('0.0001'));
        self::assertSame('4006381333931', PohodaStock::ean(' 4006381333931 '));
        self::assertNull(PohodaStock::ean('4006381333932'));
        self::assertNull(PohodaStock::ean('ABC'));
    }

    /** Řádek příjemky (zaokrouhlený součin + vedlejší náklady) dá přesně hodnotu zdroje. */
    public function testOpeningLineCostHitsSourceValueToTheCent(): void
    {
        mt_srand(7);
        for ($i = 0; $i < 2000; $i++) {
            $qtyT = mt_rand(1, 50_000_000);
            $valueC = mt_rand(0, 2_000_000_000);
            $cost = MigratedStockOpening::lineCost($qtyT, $valueC);
            self::assertGreaterThanOrEqual(0.0, (float) $cost['extra_cost']);
            $line = (int) round(round($qtyT / 1000 * (float) $cost['unit_cost'], 2) * 100) + (int) round((float) $cost['extra_cost'] * 100);
            self::assertSame($valueC, $line, "qty {$qtyT}/1000, value {$valueC}");
        }
    }

    public function testStockChoiceIsValidatedAndGoesOnlyToNewestAgenda(): void
    {
        self::assertNull(PohodaMigrationAction::stockChoice(null));
        self::assertSame(['warehouses' => ['HL' => 'goods', '7' => 'skip']], PohodaMigrationAction::stockChoice(['warehouses' => ['HL' => 'goods', 7 => 'skip']]));
        self::assertFalse(PohodaMigrationAction::stockChoice(['warehouses' => ['HL' => 'all']]));
        self::assertFalse(PohodaMigrationAction::stockChoice(['warehouses' => 'HL']));

        $meta = ['agendas' => [
            ['ico' => '12345678', 'year' => 2025, 'has_accounting' => true],
            ['ico' => '12345678', 'year' => 2026, 'has_accounting' => true],
            ['ico' => '12345678', 'year' => 2027, 'has_accounting' => false],
        ]];
        $choice = ['warehouses' => ['HL' => 'goods']];
        self::assertSame($choice, PohodaImportJobService::stockFor($meta, '12345678', 2026, $choice));
        self::assertNull(PohodaImportJobService::stockFor($meta, '12345678', 2025, $choice));
        self::assertNull(PohodaImportJobService::stockFor($meta, '12345678', 2026, null));
    }
}
