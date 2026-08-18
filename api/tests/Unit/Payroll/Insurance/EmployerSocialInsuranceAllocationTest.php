<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Insurance;

use MyInvoice\Service\Payroll\Insurance\EmployerSocialInsuranceAllocation;
use PHPUnit\Framework\TestCase;

final class EmployerSocialInsuranceAllocationTest extends TestCase
{
    /**
     * Rozdělení firemního pojistného POUZE uvnitř kategorie § 5a odst. 1.
     *
     * Osoba 1 má 40 000 Kč v písm. a) (24,8 % → 9 920 Kč), osoba 2 má 60 000 Kč
     * v písm. c) (27,8 % → 16 680 Kč). Rozpustit součet 26 600 Kč poměrem
     * základů 40:60 by dalo 10 640 a 15 960 — tedy by osobě s běžnou sazbou
     * přisoudilo 720 Kč pojistného, které vzniklo sazbou pro rizikové práce.
     */
    public function testEachCategoryIsAllocatedWithinItself(): void
    {
        $allocations = EmployerSocialInsuranceAllocation::allocateByCategory(
            [
                'ordinary' => [1 => 4_000_000, 2 => 0],
                'risk_employment' => [1 => 0, 2 => 6_000_000],
            ],
            ['ordinary' => 992_000, 'risk_employment' => 1_668_000],
            [1 => 0, 2 => 0],
            0,
        );

        self::assertSame([1 => 992_000, 2 => 1_668_000], $allocations);
        self::assertNotSame([1 => 1_064_000, 2 => 1_596_000], $allocations);
    }

    /**
     * Sleva podle § 7a se kategorií neřídí: § 7c odst. 1 ji odečítá z pojistného
     * stanoveného podle § 7 odst. 1 písm. a) až c) dohromady. Dostane ji proto
     * jen ten, kdo ji doloženě uplatnil, bez ohledu na svou sazbu.
     */
    public function testDiscountFollowsTheClaimNotTheCategory(): void
    {
        $allocations = EmployerSocialInsuranceAllocation::allocateByCategory(
            [
                'ordinary' => [1 => 2_000_000, 2 => 0],
                'risk_employment' => [1 => 0, 2 => 2_000_000],
            ],
            ['ordinary' => 496_000, 'risk_employment' => 556_000],
            [1 => 0, 2 => 2_000_000],
            100_000,
        );

        self::assertSame([1 => 496_000, 2 => 456_000], $allocations);
    }

    /**
     * Součet podílů musí sedět na korunu i tam, kde poměr nevychází celočíselně.
     * Zbytek dostává největší zbytek, při shodě nižší `employee_id`, takže
     * výsledek nezávisí na pořadí osob.
     */
    public function testRemaindersKeepTheCategoryTotalExact(): void
    {
        $allocations = EmployerSocialInsuranceAllocation::allocateByCategory(
            ['ordinary' => [1 => 100, 2 => 100, 3 => 100]],
            ['ordinary' => 100],
            [1 => 0, 2 => 0, 3 => 0],
            0,
        );

        self::assertSame(100, array_sum($allocations));
        self::assertSame([1 => 34, 2 => 33, 3 => 33], $allocations);
    }

    public function testCategoryWithoutItsAmountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EmployerSocialInsuranceAllocation::allocateByCategory(
            ['ordinary' => [1 => 100], 'risk_employment' => [1 => 100]],
            ['ordinary' => 100],
            [1 => 0],
            0,
        );
    }
}
