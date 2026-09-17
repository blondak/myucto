<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Repository\Payroll\PayrollInputFilter;
use MyInvoice\Service\Payroll\Export\PayrollInputExportFormatter;
use PHPUnit\Framework\TestCase;

final class PayrollInputExportFormatterTest extends TestCase
{
    public function testRateIsAmountPerUnitComputedInMinorUnits(): void
    {
        // 180,98 Kč × 24,25 h: ve float Kč vychází 4388,76, správně je 4388,77.
        self::assertSame(180.98, PayrollInputExportFormatter::rate(438_877, 24_250));
        self::assertSame(250.0, PayrollInputExportFormatter::rate(200_000, 8_000));
        self::assertSame(-25.0, PayrollInputExportFormatter::rate(-5_000, 2_000));
        self::assertNull(PayrollInputExportFormatter::rate(100_000, null));
        self::assertNull(PayrollInputExportFormatter::rate(100_000, 0));
    }

    public function testQuantityAndMoneyText(): void
    {
        self::assertSame(8.0, PayrollInputExportFormatter::quantity(8_000));
        self::assertNull(PayrollInputExportFormatter::quantity(null));
        self::assertSame('8', PayrollInputExportFormatter::quantityText(8.0));
        self::assertSame('7,5', PayrollInputExportFormatter::quantityText(7.5));
        self::assertSame('0,125', PayrollInputExportFormatter::quantityText(0.125));
        self::assertSame('1 250', PayrollInputExportFormatter::quantityText(1250.0));
        self::assertSame('', PayrollInputExportFormatter::quantityText(null));
        self::assertSame('1 234,56', PayrollInputExportFormatter::money(123_456));
        self::assertSame('-0,05', PayrollInputExportFormatter::money(-5));
        self::assertSame('0,00', PayrollInputExportFormatter::money(0));
        self::assertSame('06/2026', PayrollInputExportFormatter::periodLabel('2026-06-01'));
    }

    /**
     * Rozsahový export se nesmí tvářit jako jeden měsíc — ani v hlavičce, ani
     * v názvu souboru. Osmiměsíční sestava označená „06/2026" je tvrzení
     * o obsahu, které v exportu nikdo nemá jak ověřit.
     */
    public function testPeriodRangeIsVisibleInTheLabelAndInTheFilename(): void
    {
        self::assertSame(
            '01/2026 – 08/2026',
            PayrollInputExportFormatter::periodLabel('2026-01-01', '2026-08-01'),
        );
        self::assertSame(
            '2026-01_2026-08',
            PayrollInputExportFormatter::periodSlug('2026-01-01', '2026-08-01'),
        );

        // Rozsah o jednom měsíci je pořád jeden měsíc; „06/2026 – 06/2026"
        // by jen mátlo.
        self::assertSame(
            '06/2026',
            PayrollInputExportFormatter::periodLabel('2026-06-01', '2026-06-01'),
        );
        self::assertSame(
            '2026-06',
            PayrollInputExportFormatter::periodSlug('2026-06-01', '2026-06-01'),
        );
        self::assertSame('2026-06', PayrollInputExportFormatter::periodSlug('2026-06-01'));
    }

    public function testFormulaLikeTextIsDetected(): void
    {
        foreach (['=A1', '+420', '-2', '@SUM(A1)', "\tx", "\rx"] as $value) {
            self::assertTrue(PayrollInputExportFormatter::isFormulaLike($value), json_encode($value, JSON_THROW_ON_ERROR));
        }
        foreach (['Syntetická osoba', '', ' =A1', 'SYN-1'] as $value) {
            self::assertFalse(PayrollInputExportFormatter::isFormulaLike($value), $value);
        }
    }

    public function testRowMapsLabelsUnitAndHiddenKeys(): void
    {
        $row = PayrollInputExportFormatter::row([
            'id' => 41,
            'employee_id' => 7,
            'row_version' => 3,
            'amount_minor' => 200_000,
            'quantity_milliunits' => 8_000,
            'status' => 'approved',
            'source_kind' => 'import',
            'import_id' => 9,
            'import_name' => 'dochazka-syntetika.csv',
            'employee_name' => 'Syntetická osoba',
            'employment_code' => 'SYN-1',
            'relation_type' => 'dpp',
            'component_code' => 'SYN_HOD',
            'component_name' => 'Syntetická hodinová mzda',
            'component_kind' => 'hourly_wage',
        ]);

        self::assertSame(7, $row['employee_id']);
        self::assertSame('SYN-1', $row['personal_number']);
        self::assertSame('Dohoda o provedení práce', $row['relation']);
        self::assertSame('h', $row['unit']);
        self::assertSame(8.0, $row['quantity']);
        self::assertSame(250.0, $row['rate']);
        self::assertSame(2000.0, $row['amount']);
        self::assertSame(200_000, $row['amount_minor']);
        self::assertSame('Schválený', $row['status']);
        self::assertSame('Import', $row['source']);
        self::assertSame('#9 dochazka-syntetika.csv', $row['import']);
        self::assertSame(41, $row['row_key']);
        self::assertSame(3, $row['row_version']);

        $plain = PayrollInputExportFormatter::row([
            'id' => 42,
            'amount_minor' => 50_000,
            'quantity_milliunits' => null,
            'status' => 'draft',
            'source_kind' => 'manual',
            'import_id' => null,
            'component_kind' => 'hourly_wage',
        ]);
        self::assertSame('', $plain['unit'], 'Bez množství není jednotka.');
        self::assertNull($plain['quantity']);
        self::assertNull($plain['rate']);
        self::assertSame('', $plain['import']);
        self::assertSame('Koncept', $plain['status']);
        self::assertSame('Ruční vstup', $plain['source']);
    }

    public function testFilterLinesAreReadable(): void
    {
        self::assertSame(
            [['label' => 'Filtr', 'value' => 'bez filtru, všechny vstupy období kromě zrušených']],
            PayrollInputExportFormatter::filterLines(
                new PayrollInputFilter('2026-06-01'),
                ['employee' => null, 'employment' => null, 'components' => [], 'import' => null],
            ),
        );

        $filter = PayrollInputFilter::fromArray('2026-06-01', [
            'q' => 'Novák',
            'employee_id' => '8',
            'component_id' => '5,6',
            'status' => 'draft,approved',
            'source_kind' => 'import',
            'import_id' => '9',
        ]);
        self::assertSame(
            [
                ['label' => 'Hledaný text', 'value' => 'Novák'],
                ['label' => 'Zaměstnanec', 'value' => '#8'],
                ['label' => 'Složky', 'value' => 'SYN_A Syntetická A, #6'],
                ['label' => 'Stav', 'value' => 'Koncept, Schválený'],
                ['label' => 'Zdroj', 'value' => 'Import'],
                ['label' => 'Importní dávka', 'value' => '#9 dochazka.csv'],
            ],
            PayrollInputExportFormatter::filterLines($filter, [
                'employee' => null,
                'employment' => null,
                'components' => [5 => 'SYN_A Syntetická A'],
                'import' => 'dochazka.csv',
            ]),
        );
    }
}
