<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\SocialInsurance;

use MyInvoice\Service\Payroll\SocialInsurance\SocialPartTimeDiscountAgeCondition as Age;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPartTimeDiscountReason;
use PHPUnit\Framework\TestCase;

/**
 * Věková podmínka důvodu slevy podle § 7a odst. 1 písm. a), d), g) a § 7b odst. 4
 * zákona č. 589/1992 Sb. Všechna data jsou syntetická.
 */
final class SocialPartTimeDiscountAgeConditionTest extends TestCase
{
    public function testFiftyFivePlusNeedsTheBirthdayBeforeTheFirstCoveredDay(): void
    {
        $reason = SocialPartTimeDiscountReason::Age55Plus;

        self::assertSame(Age::MET, Age::assess($reason, '1971-05-01', '2026-05-01', '2026-05-31'));
        self::assertSame(Age::MET, Age::assess($reason, '1970-01-15', '2026-05-01', '2026-05-31'));
        // 55. narozeniny uprostřed měsíce: podmínka neplatí po celou dobu zaměstnání v měsíci
        self::assertSame(Age::NOT_MET, Age::assess($reason, '1971-05-15', '2026-05-01', '2026-05-31'));
        self::assertSame(Age::NOT_MET, Age::assess($reason, '1985-05-01', '2026-05-01', '2026-05-31'));
    }

    public function testNewHireInTheBirthdayMonthIsCoveredFromTheStartDay(): void
    {
        $reason = SocialPartTimeDiscountReason::Age55Plus;

        self::assertSame(Age::MET, Age::assess($reason, '1971-05-10', '2026-05-12', '2026-05-31'));
    }

    public function testUnderTwentySixHoldsUntilTheLastCoveredDay(): void
    {
        $reason = SocialPartTimeDiscountReason::StudyUnder26;

        self::assertSame(Age::MET, Age::assess($reason, '2000-06-01', '2026-05-01', '2026-05-31'));
        // 26. narozeniny 31. 5.: poslední den už není mladší 26 let
        self::assertSame(Age::NOT_MET, Age::assess($reason, '2000-05-31', '2026-05-01', '2026-05-31'));
        self::assertSame(Age::NOT_MET, Age::assess($reason, '1999-01-01', '2026-05-01', '2026-05-31'));
    }

    public function testUnderTwentyOneEndsOnTheBirthday(): void
    {
        $reason = SocialPartTimeDiscountReason::Under21;

        self::assertSame(Age::MET, Age::assess($reason, '2005-06-01', '2026-05-01', '2026-05-31'));
        self::assertSame(Age::NOT_MET, Age::assess($reason, '2005-05-20', '2026-05-01', '2026-05-31'));
        // zaměstnání skončilo před narozeninami
        self::assertSame(Age::MET, Age::assess($reason, '2005-05-20', '2026-05-01', '2026-05-19'));
    }

    public function testMissingOrInvalidBirthDateCannotBeVerified(): void
    {
        self::assertSame(Age::UNKNOWN, Age::assess(SocialPartTimeDiscountReason::Age55Plus, null, '2026-05-01', '2026-05-31'));
        self::assertSame(Age::UNKNOWN, Age::assess(SocialPartTimeDiscountReason::Under21, '2005-02-30', '2026-05-01', '2026-05-31'));
    }

    public function testReasonsWithoutAnAgeBoundaryAreNotApplicable(): void
    {
        self::assertSame(Age::NOT_APPLICABLE, Age::assess(SocialPartTimeDiscountReason::ChildCareUnder10, null, '2026-05-01', '2026-05-31'));
        self::assertSame(Age::NOT_APPLICABLE, Age::assess(null, '1960-01-01', '2026-05-01', '2026-05-31'));
    }

    public function testLeapDayBirthdayFallsOnTheLastDayOfFebruary(): void
    {
        // narozen 29. 2. 2004: 21. narozeniny v nepřestupném roce 2025 připadají na 28. 2.
        self::assertSame(
            Age::NOT_MET,
            Age::assess(SocialPartTimeDiscountReason::Under21, '2004-02-29', '2025-02-01', '2025-02-28'),
        );
        self::assertSame(
            Age::MET,
            Age::assess(SocialPartTimeDiscountReason::Under21, '2004-02-29', '2025-02-01', '2025-02-27'),
        );
    }

    public function testMonthHelperUsesTheEmploymentCoverage(): void
    {
        self::assertSame(
            Age::MET,
            Age::forMonth('age_55_plus', '1971-05-10', '2026-05-01', '2026-05-31', '2026-05-12', null),
        );
        self::assertSame(
            Age::NOT_MET,
            Age::forMonth('age_55_plus', '1971-05-10', '2026-05-01', '2026-05-31', '2026-01-01', null),
        );
        self::assertSame(Age::NOT_APPLICABLE, Age::forMonth('none', '1971-05-10', '2026-05-01', '2026-05-31', null, null));
    }
}
