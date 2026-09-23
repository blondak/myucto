<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use MyInvoice\Service\Migration\Premier\TaxReturnImporter;
use MyInvoice\Service\Migration\Shared\FiledDppoInputs;
use PHPUnit\Framework\TestCase;

final class FiledDppoInputsTest extends TestCase
{
    private const TEXTS = [
        'line' => 'Z podání (ř. %s)',
        'line40' => 'Nedaňové z podání (ř. 40)',
        'line40_travel' => 'PHM k paušálu (ř. 40)',
        'travel' => 'Paušál na dopravu (ř. 112)',
    ];

    public function testTakesManualLinesAndOnlyTheUncomputedPartOfLine40(): void
    {
        $built = FiledDppoInputs::build(
            [10 => 500_000.0, 30 => 1_200.0, 40 => 10_000.0, 50 => 3_000.0, 62 => 800.0, 110 => 20_000.0, 150 => 4_000.0, 162 => 150.0, 230 => 90_000.0, 242 => 5_000.0, 260 => 2_500.0],
            7_500.0,
            false,
            self::TEXTS,
            12_000.0,
        );

        self::assertSame([
            'manual_increase_items' => [
                ['text' => 'Z podání (ř. 30)', 'amount' => 1200.0],
                ['text' => 'Z podání (ř. 62)', 'amount' => 800.0],
                ['text' => 'Nedaňové z podání (ř. 40)', 'amount' => 2500.0],
            ],
            'manual_decrease_items' => [
                ['text' => 'Z podání (ř. 110)', 'amount' => 20000.0],
                ['text' => 'Z podání (ř. 162)', 'amount' => 150.0],
            ],
            'loss_carryforward' => 90000.0,
            'tax_paid_advances' => 12000.0,
            'rnd_deduction' => 5000.0,
            'donations' => 2500.0,
        ], $built['inputs']);
        self::assertNull($built['line40_shortfall']);
    }

    public function testLine112IsGenericUnlessTheSourceUsesItForTravel(): void
    {
        $generic = FiledDppoInputs::build([40 => 100.0, 112 => 700.0], 0.0, false, self::TEXTS);
        self::assertSame([['text' => 'Z podání (ř. 112)', 'amount' => 700.0]], $generic['inputs']['manual_decrease_items']);
        self::assertSame([['text' => 'Nedaňové z podání (ř. 40)', 'amount' => 100.0]], $generic['inputs']['manual_increase_items']);

        $travel = FiledDppoInputs::build([40 => 100.0, 112 => 700.0], 0.0, true, self::TEXTS);
        self::assertSame([['text' => 'Paušál na dopravu (ř. 112)', 'amount' => 700.0, 'kind' => 'flat_rate_travel']], $travel['inputs']['manual_decrease_items']);
        self::assertSame([['text' => 'PHM k paušálu (ř. 40)', 'amount' => 100.0, 'kind' => 'flat_rate_travel']], $travel['inputs']['manual_increase_items']);
    }

    public function testComputedLine40AboveFiledIsReportedNotImported(): void
    {
        $built = FiledDppoInputs::build([40 => 1_000.0], 1_500.0, false, self::TEXTS);

        self::assertSame([], $built['inputs']);
        self::assertSame(['computed' => 1500.0, 'filed' => 1000.0], $built['line40_shortfall']);
    }

    public function testPremierColumnsGoThroughTheSharedRule(): void
    {
        $built = TaxReturnImporter::inputsFromPremier([
            'II_20_HODN' => 10, 'II_40_VYDA' => 900.456, 'II_112_LZE' => 45_000, 'II_160_UHR' => 70, 'II_230_ODE' => 1_000,
            'V_1_NA_ZAL' => 300, 'II_242_ODE' => 99, 'II_300_SLE' => 18_000,
        ], 400.0);

        self::assertSame([
            'manual_increase_items' => [
                ['text' => 'Úprava základu z přiznání v PREMIER (ř. 20)', 'amount' => 10.0],
                ['text' => 'Vrácení PHM do základu u paušálu na dopravu (ř. 40 z PREMIER)', 'amount' => 500.46, 'kind' => 'flat_rate_travel'],
            ],
            'manual_decrease_items' => [
                ['text' => 'Paušální výdaj na dopravu (§ 24 odst. 2 písm. zt), ř. 112 z PREMIER', 'amount' => 45000.0, 'kind' => 'flat_rate_travel'],
                ['text' => 'Úprava základu z přiznání v PREMIER (ř. 160)', 'amount' => 70.0],
            ],
            'loss_carryforward' => 1000.0,
            'tax_paid_advances' => 300.0,
        ], $built['inputs'], 'PREMIER ř. 242 a 300 nepřebírá (hlásí je k ručnímu doplnění)');
    }
}
