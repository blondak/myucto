<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMeaning;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRules;
use PHPUnit\Framework\TestCase;

final class AttendanceRulesTest extends TestCase
{
    public function testConditionIsKeptOnlyWhenComplete(): void
    {
        $rules = AttendanceRules::validate([
            [
                'sheet' => null, 'header' => 'Odměny', 'meaning' => 'component', 'unit' => 'amount',
                'component_code' => 'mzda_ukolova', 'when_header' => ' oddělení ', 'when_value' => 'výroba',
            ],
            [
                'sheet' => null, 'header' => 'Odměny', 'meaning' => 'component', 'unit' => 'amount',
                'component_code' => 'ODMENA', 'when_header' => '', 'when_value' => null,
            ],
        ]);

        self::assertSame([
            'sheet' => null,
            'header' => 'Odměny',
            'meaning' => 'component',
            'unit' => 'amount',
            'component_code' => 'MZDA_UKOLOVA',
            'when_header' => 'oddělení',
            'when_value' => 'výroba',
        ], $rules[0]);
        // Pravidlo bez podmínky má dosavadní tvar — profily ani otisky dávek se nemění.
        self::assertSame(['sheet', 'header', 'meaning', 'unit', 'component_code'], array_keys($rules[1]));
        self::assertTrue(AttendanceRules::hasCondition($rules[0]));
        self::assertFalse(AttendanceRules::hasCondition($rules[1]));
    }

    public function testIncompleteConditionIsRejected(): void
    {
        $this->expectExceptionMessage('neúplnou podmínku');
        AttendanceRules::validate([
            ['sheet' => null, 'header' => 'Odměny', 'meaning' => 'ignore', 'unit' => null, 'component_code' => null, 'when_header' => 'oddělení'],
        ]);
    }

    public function testConditionNeedsAConcreteComponentCode(): void
    {
        $this->expectExceptionMessage('konkrétní kód');
        AttendanceRules::validate([[
            'sheet' => null, 'header' => 'Odměny', 'meaning' => 'component', 'unit' => 'amount',
            'component_code' => '*', 'when_header' => 'oddělení', 'when_value' => 'výroba',
        ]]);
    }

    public function testObstacleRateIsValidatedAndOnePerProfile(): void
    {
        $rule = static fn (array $extra): array => [
            'sheet' => null, 'header' => 'Doma za 80 %', 'meaning' => 'obstacle_employer_hours', 'unit' => 'hours', 'component_code' => null,
        ] + $extra;

        $rules = AttendanceRules::validate([$rule(['rate_percent' => 70])]);
        self::assertSame(70, $rules[0]['rate_percent']);
        self::assertSame(70, AttendanceRules::obstacleEmployerRate($rules));
        $withoutRate = AttendanceRules::validate([$rule([])]);
        self::assertArrayNotHasKey('rate_percent', $withoutRate[0]);
        self::assertNull(AttendanceRules::obstacleEmployerRate($withoutRate));

        foreach ([
            'pod zákonnou hranici' => [$rule(['rate_percent' => 50])],
            'text místo čísla' => [$rule(['rate_percent' => '80'])],
            'jiný význam' => [[
                'sheet' => null, 'header' => 'Dovolená', 'meaning' => 'vacation_hours', 'unit' => 'hours',
                'component_code' => null, 'rate_percent' => 90,
            ]],
            'dvě sazby v profilu' => [$rule(['rate_percent' => 60]), $rule([])],
        ] as $case => $invalid) {
            try {
                AttendanceRules::validate($invalid);
                self::fail("Neplatná sazba prošla: {$case}.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDeductionMeaningsAreMoneyWithAmountUnit(): void
    {
        self::assertTrue(AttendanceMeaning::isMoney('net_meal_deduction'));
        self::assertTrue(AttendanceMeaning::isDeduction('net_other_deduction'));
        self::assertFalse(AttendanceMeaning::isDeduction('component'));
        self::assertSame(['amount'], AttendanceMeaning::allowedUnits('net_meal_deduction'));
    }
}
