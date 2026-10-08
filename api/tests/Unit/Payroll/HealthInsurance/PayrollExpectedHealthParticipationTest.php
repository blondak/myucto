<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\HealthInsurance;

use MyInvoice\Service\Payroll\HealthInsurance\PayrollExpectedHealthParticipation;
use PHPUnit\Framework\TestCase;

/**
 * ZP-01: účast pro oznámení zdravotní pojišťovně plyne z pravidel zdravotního
 * pojištění (§ 5 písm. a) z. 48/1997 Sb.), ne z pravidel ČSSZ. Příjmový práh
 * mají jen dohody; pracovní poměr, zaměstnání malého rozsahu a člen orgánu
 * s odměnou jsou zaměstnáním bez ohledu na výši příjmu.
 */
final class PayrollExpectedHealthParticipationTest extends TestCase
{
    private const DPC_THRESHOLD = 450_000;

    public function testExplicitParticipationWins(): void
    {
        self::assertTrue(PayrollExpectedHealthParticipation::expected('included', 'dpp', null, self::DPC_THRESHOLD));
        self::assertFalse(PayrollExpectedHealthParticipation::expected('excluded', 'employment', 5_000_000, self::DPC_THRESHOLD));
        self::assertFalse(PayrollExpectedHealthParticipation::expected('foreign', 'small_scale_employment', 5_000_000, self::DPC_THRESHOLD));
    }

    /** Zaměstnání malého rozsahu se 3 000 Kč: pro ČSSZ pod prahem, pro ZP zaměstnání. */
    public function testSmallScaleEmploymentParticipatesBelowTheSocialThreshold(): void
    {
        self::assertTrue(PayrollExpectedHealthParticipation::expected('automatic', 'small_scale_employment', 300_000, self::DPC_THRESHOLD));
        self::assertTrue(PayrollExpectedHealthParticipation::expected(null, 'small_scale_employment', null, null));
        self::assertTrue(PayrollExpectedHealthParticipation::expected('automatic', 'employment', null, null));
    }

    /** Jednatel s odměnou pod limitem ano; bez sjednané odměny se netvrdí. */
    public function testCorporateBodyParticipatesWithAgreedRewardOnly(): void
    {
        self::assertTrue(PayrollExpectedHealthParticipation::expected('automatic', 'statutory_body', 300_000, self::DPC_THRESHOLD));
        self::assertTrue(PayrollExpectedHealthParticipation::expected('automatic', 'partner_dependent', 100, self::DPC_THRESHOLD));
        self::assertFalse(PayrollExpectedHealthParticipation::expected('automatic', 'statutory_body', null, self::DPC_THRESHOLD));
        self::assertFalse(PayrollExpectedHealthParticipation::expected('automatic', 'statutory_body', 0, self::DPC_THRESHOLD));
    }

    /** DPP rozhoduje až schválený běh; sjednaná odměna sama nestačí. */
    public function testAgreementToPerformWorkIsDecidedByTheRunOnly(): void
    {
        self::assertFalse(PayrollExpectedHealthParticipation::expected('automatic', 'dpp', 1_500_000, self::DPC_THRESHOLD));
        self::assertTrue(PayrollExpectedHealthParticipation::expected('automatic', 'dpp', null, self::DPC_THRESHOLD, true));
        self::assertTrue(PayrollExpectedHealthParticipation::decidedByMonthlyIncome('dpp'));
        self::assertTrue(PayrollExpectedHealthParticipation::decidedByMonthlyIncome('dpc'));
        self::assertFalse(PayrollExpectedHealthParticipation::decidedByMonthlyIncome('small_scale_employment'));
    }

    public function testAgreementOnWorkingActivityByAgreedIncomeOrRun(): void
    {
        self::assertTrue(PayrollExpectedHealthParticipation::expected('automatic', 'dpc', self::DPC_THRESHOLD, self::DPC_THRESHOLD));
        self::assertFalse(PayrollExpectedHealthParticipation::expected('automatic', 'dpc', self::DPC_THRESHOLD - 1, self::DPC_THRESHOLD));
        self::assertTrue(PayrollExpectedHealthParticipation::expected('automatic', 'dpc', self::DPC_THRESHOLD - 1, self::DPC_THRESHOLD, true));
        self::assertFalse(PayrollExpectedHealthParticipation::expected('automatic', 'dpc', 1_200_000, null), 'Bez pravidel roku se nehádá.');
    }

    /**
     * Člen družstva nebo SVJ (§ 5 písm. a) body 4 a 5) se pojišťovně hlásí
     * jako DPČ: při sjednané odměně alespoň započitatelného příjmu, jinak až
     * podle schváleného běhu. Bez příznaku je člen orgánu zaměstnancem vždy.
     */
    public function testAssociationMemberIsReportedOnlyWithCountingIncome(): void
    {
        self::assertFalse(PayrollExpectedHealthParticipation::expected(
            'automatic', 'statutory_body', 300_000, self::DPC_THRESHOLD, associationMember: true,
        ));
        self::assertTrue(PayrollExpectedHealthParticipation::expected(
            'automatic', 'statutory_body', self::DPC_THRESHOLD, self::DPC_THRESHOLD, associationMember: true,
        ));
        self::assertTrue(PayrollExpectedHealthParticipation::expected(
            'automatic', 'employment', 300_000, self::DPC_THRESHOLD, true, true,
        ));
        self::assertTrue(PayrollExpectedHealthParticipation::decidedByMonthlyIncome('employment', true));
        self::assertTrue(PayrollExpectedHealthParticipation::expected(
            'automatic', 'statutory_body', 300_000, self::DPC_THRESHOLD,
        ));
    }

    public function testUnknownRelationTypeDoesNotParticipate(): void
    {
        self::assertFalse(PayrollExpectedHealthParticipation::expected('automatic', 'neznamy', 5_000_000, self::DPC_THRESHOLD, true));
    }
}
