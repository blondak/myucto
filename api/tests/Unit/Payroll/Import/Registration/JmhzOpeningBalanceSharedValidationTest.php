<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Registration;

use MyInvoice\Service\Payroll\Import\Jmhz\JmhzBatchItem;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzOpeningBalancePlanner;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportFile;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportReader;
use MyInvoice\Service\Payroll\Import\OpeningBalance\OpeningBalanceMonthValidator;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use PHPUnit\Framework\TestCase;

/**
 * Věcnou kontrolu měsíce sdílejí VŠECHNY cesty do počátečních stavů.
 *
 * Do kumulací vede ruční mřížka, tabulkový import i import hlášení JMHZ.
 * Než vznikl {@see OpeningBalanceMonthValidator}, prošla cestou JMHZ tiše
 * i srážková daň bez základu srážkové daně — kontrola, která platí jen na
 * jedné cestě, je horší než žádná.
 */
final class JmhzOpeningBalanceSharedValidationTest extends TestCase
{
    /** Srážková daň bez základu. Týž vstup musí odmítnout i hlášení JMHZ. */
    public function testWithholdingTaxWithoutBaseIsRejectedOnTheJmhzPathToo(): void
    {
        $row = [
            'month' => 2,
            'withholding_base_minor_units' => 0,
            'withholding_tax_minor_units' => 70_000,
        ];
        $direct = OpeningBalanceMonthValidator::reject($row);
        self::assertNotNull($direct, 'Sdílená kontrola musí vadný měsíc odmítnout.');
        self::assertStringContainsString('Základ srážkové daně', $direct);

        $person = JmhzReportFixtures::person(['withholding' => ['base' => 0, 'tax' => 700]]);
        $result = JmhzOpeningBalancePlanner::monthRow(
            2,
            $this->items(JmhzReportFixtures::report([$person], 2026, 2)),
        );

        self::assertNull($result['row'], 'Hlášení s daní bez základu se nesmí převzít.');
        self::assertStringContainsString('Základ srážkové daně', (string) $result['reason']);
    }

    /** Popisek musí existovat ke každému sloupci — jinak hláška spadne na LogicException. */
    public function testEveryMonthColumnHasALabel(): void
    {
        self::assertSame(
            PayrollOpeningBalanceService::monthFields(),
            array_keys(OpeningBalanceMonthValidator::labels()),
        );
    }

    /** @return list<JmhzBatchItem> */
    private function items(string $xml): array
    {
        $file = $this->file($xml);
        $items = [];
        foreach ($file->forms as $form) {
            $items[] = new JmhzBatchItem(
                'aaaaaaaaaaaaaaaa:' . $form->position,
                $file,
                $form,
                'h.xml',
                str_repeat('a', 64),
                0,
            );
        }

        return $items;
    }

    private function file(string $xml): JmhzReportFile
    {
        return (new JmhzReportReader())->read($xml);
    }
}
