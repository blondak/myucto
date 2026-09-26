<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\SocialInsurance;

use MyInvoice\Service\Payroll\SocialInsurance\PayrollExpectedParticipation;
use PHPUnit\Framework\TestCase;

/**
 * Účast na pojištění známá už při nástupu — podle ní se hlásí nástup ČSSZ
 * i zdravotní pojišťovně. DPČ se sjednanou odměnou nad rozhodným příjmem
 * pojistí výpočet vždy, takže nástup se hlásí hned, ne až po mzdovém běhu.
 */
final class PayrollExpectedParticipationTest extends TestCase
{
    private const THRESHOLD = 450_000;

    public function testExplicitParticipationWins(): void
    {
        self::assertTrue(PayrollExpectedParticipation::expected('included', 'dpp', null, self::THRESHOLD));
        self::assertFalse(PayrollExpectedParticipation::expected('excluded', 'employment', 5_000_000, self::THRESHOLD));
        self::assertFalse(PayrollExpectedParticipation::expected('foreign', 'employment', 5_000_000, self::THRESHOLD));
    }

    public function testEmploymentParticipatesWithoutWage(): void
    {
        self::assertTrue(PayrollExpectedParticipation::expected('automatic', 'employment', null, null));
        self::assertTrue(PayrollExpectedParticipation::expected(null, 'employment', null, null));
    }

    public function testAgreementDependsOnAgreedIncome(): void
    {
        self::assertTrue(PayrollExpectedParticipation::expected('automatic', 'dpc', 1_200_000, self::THRESHOLD));
        self::assertTrue(PayrollExpectedParticipation::expected('automatic', 'dpc', self::THRESHOLD, self::THRESHOLD));
        self::assertFalse(PayrollExpectedParticipation::expected('automatic', 'dpc', self::THRESHOLD - 1, self::THRESHOLD));
        self::assertFalse(PayrollExpectedParticipation::expected('automatic', 'dpc', null, self::THRESHOLD));
        self::assertFalse(PayrollExpectedParticipation::expected('automatic', 'dpc', 1_200_000, null), 'Bez pravidel roku se nehádá.');
        // DPP rozhoduje úhrn všech DPP za měsíc, ten při nástupu znát nejde.
        self::assertFalse(PayrollExpectedParticipation::expected('automatic', 'dpp', 5_000_000, self::THRESHOLD));
    }
}
