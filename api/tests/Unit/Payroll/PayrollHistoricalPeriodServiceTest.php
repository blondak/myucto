<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use PHPUnit\Framework\TestCase;

/**
 * Hranice mezi historií a prací.
 *
 * `start_period` je PRVNÍ měsíc, který MyÚčto počítá, ne poslední historický.
 * Porovnání proto musí být ostré — stejně jako v
 * {@see \MyInvoice\Service\Payroll\Run\PayrollRunCommandService::assertModuleAvailable()},
 * která běh za starší období odmítne. Kdyby se sem vloudilo `<=`, označil by se
 * jako historický právě ten měsíc, kterým vedení mezd začíná, a účetní by u něj
 * přestala vidět docházku a vstupy jako práci, kterou má udělat.
 */
final class PayrollHistoricalPeriodServiceTest extends TestCase
{
    public function testTheFirstPayrollMonthItselfIsWorkNotHistory(): void
    {
        self::assertTrue(PayrollHistoricalPeriodService::precedesStart('2026-06', '2026-05'));
        self::assertTrue(PayrollHistoricalPeriodService::precedesStart('2026-06-01', '2026-05-01'));
        self::assertTrue(PayrollHistoricalPeriodService::precedesStart('2026-01', '2025-12'));

        self::assertFalse(PayrollHistoricalPeriodService::precedesStart('2026-06', '2026-06'));
        self::assertFalse(PayrollHistoricalPeriodService::precedesStart('2026-06', '2026-06-01'));
        self::assertFalse(PayrollHistoricalPeriodService::precedesStart('2026-06-01', '2026-06'));
        self::assertFalse(PayrollHistoricalPeriodService::precedesStart('2026-06', '2026-07'));
    }

    /**
     * Bez nastaveného začátku není podle čeho historii poznat, takže se
     * neoznačuje nic. Nesmyslný vstup se chová stejně: označit měsíc kvůli
     * nečitelné hranici by z něj udělalo historii bez důvodu.
     */
    public function testWithoutAStartPeriodNothingIsMarked(): void
    {
        self::assertFalse(PayrollHistoricalPeriodService::precedesStart(null, '2026-05'));
        self::assertFalse(PayrollHistoricalPeriodService::precedesStart('', '2026-05'));
        self::assertFalse(PayrollHistoricalPeriodService::precedesStart('   ', '2026-05'));
        self::assertFalse(PayrollHistoricalPeriodService::precedesStart('nesmysl', '2026-05'));
        self::assertFalse(PayrollHistoricalPeriodService::precedesStart('2026-06', ''));
        self::assertFalse(PayrollHistoricalPeriodService::precedesStart('2026-06', 'nesmysl'));
    }

    /** Tvar odpovědi, ze kterého výpisy kreslí značku „historické". */
    public function testDescribeReportsTheBoundaryAlongWithTheVerdict(): void
    {
        $service = $this->serviceWithStartPeriod('2026-06');

        self::assertSame(
            ['payroll_start_period' => '2026-06', 'historical' => true],
            $service->describe(7, '2026-05'),
        );
        self::assertSame(
            ['payroll_start_period' => '2026-06', 'historical' => false],
            $service->describe(7, '2026-06'),
        );
        self::assertTrue($service->isHistorical(7, '2026-04-01'));
        self::assertFalse($service->isHistorical(7, '2026-09'));

        $unset = $this->serviceWithStartPeriod(null);
        self::assertSame(
            ['payroll_start_period' => null, 'historical' => false],
            $unset->describe(7, '1999-01'),
        );
    }

    /**
     * Instance s předvyplněnou hranicí — cache se plní podle firmy, takže
     * jednotkový test nepotřebuje databázi ani repozitář.
     */
    private function serviceWithStartPeriod(?string $startPeriod): PayrollHistoricalPeriodService
    {
        $reflection = new \ReflectionClass(PayrollHistoricalPeriodService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('startPeriods');
        $property->setValue($service, [7 => $startPeriod]);

        return $service;
    }
}
