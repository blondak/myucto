<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\IncomeTax;

use MyInvoice\Service\Payroll\IncomeTax\ChildCreditClaimWindow;
use MyInvoice\Service\Payroll\PayrollDependantValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChildCreditClaimWindowTest extends TestCase
{
    /** @return iterable<string,array{string,string,?string,string}> */
    public static function starts(): iterable
    {
        yield 'narození 15. 5.' => ['2026-05-15', '2026-05-15', null, '2026-05-01'];
        yield 'narození 1. 5.' => ['2026-05-01', '2026-05-01', null, '2026-05-01'];
        yield 'narození 31. 5.' => ['2026-05-31', '2026-05-31', 'own_household', '2026-05-01'];
        yield 'vyživování od prvního dne' => ['2015-02-02', '2026-05-01', 'own_household', '2026-05-01'];
        yield 'přestěhování v průběhu měsíce' => ['2015-02-02', '2026-05-15', 'own_household', '2026-06-01'];
        yield 'bez důvodu' => ['2015-02-02', '2026-12-15', null, '2027-01-01'];
        yield 'osvojení' => ['2015-02-02', '2026-05-15', 'adoption', '2026-05-01'];
        yield 'péče nahrazující péči rodičů' => ['2015-02-02', '2026-05-15', 'foster_care', '2026-05-01'];
        yield 'zahájení studia' => ['2007-03-03', '2026-10-20', 'study_start', '2026-10-01'];
        yield 'pokračující studium není zahájení' => ['2007-03-03', '2026-10-20', 'study_continues', '2026-11-01'];
    }

    public function testZtpPDoubleStartsInTheFirstMonthTheCardHeldAtItsStart(): void
    {
        self::assertSame('2026-05-01', ChildCreditClaimWindow::ztpPEarliestFrom('2026-05-01'));
        self::assertSame('2026-06-01', ChildCreditClaimWindow::ztpPEarliestFrom('2026-05-02'));
        self::assertSame('2027-01-01', ChildCreditClaimWindow::ztpPEarliestFrom('2026-12-31'));
    }

    public function testRelationGivesTheStartEventReason(): void
    {
        self::assertSame('adoption', ChildCreditClaimWindow::reasonForRelation('child_adopted'));
        self::assertSame('foster_care', ChildCreditClaimWindow::reasonForRelation('child_in_care'));
        self::assertNull(ChildCreditClaimWindow::reasonForRelation('child_own'));
        self::assertNull(ChildCreditClaimWindow::reasonForRelation('child_of_spouse'));
        foreach (['child_adopted', 'child_in_care'] as $relation) {
            self::assertContains(
                ChildCreditClaimWindow::reasonForRelation($relation),
                ChildCreditClaimWindow::START_EVENT_REASONS,
            );
            self::assertContains($relation, PayrollDependantValidator::CHILD_RELATIONS);
        }
    }

    #[DataProvider('starts')]
    public function testEarliestClaimStart(
        string $birthDate,
        string $existenceFrom,
        ?string $reason,
        string $expected,
    ): void {
        self::assertSame(
            $expected,
            ChildCreditClaimWindow::earliestFrom($birthDate, $existenceFrom, $reason),
        );
    }

    public function testEndMonthOfSupportBelongsToTheClaim(): void
    {
        self::assertNull(ChildCreditClaimWindow::latestTo(null));
        self::assertSame('2026-06-30', ChildCreditClaimWindow::latestTo('2026-06-15'));
        self::assertSame('2026-02-28', ChildCreditClaimWindow::latestTo('2026-02-01'));
        self::assertSame('2026-12-31', ChildCreditClaimWindow::latestTo('2026-12-31'));
    }

    public function testContainsCombinesBothEnds(): void
    {
        $birth = '2026-05-15';
        self::assertTrue(ChildCreditClaimWindow::contains('2026-05-01', '2026-08-31', $birth, $birth, '2026-08-10', null));
        self::assertFalse(ChildCreditClaimWindow::contains('2026-04-01', '2026-08-31', $birth, $birth, '2026-08-10', null));
        self::assertFalse(ChildCreditClaimWindow::contains('2026-05-01', '2026-09-30', $birth, $birth, '2026-08-10', null));
        self::assertFalse(ChildCreditClaimWindow::contains('2026-05-01', null, $birth, $birth, '2026-08-10', null));
        self::assertTrue(ChildCreditClaimWindow::contains('2026-05-01', null, $birth, $birth, null, null));
    }

    public function testStartEventReasonsAreClaimReasons(): void
    {
        self::assertSame(
            [],
            array_diff(
                ChildCreditClaimWindow::START_EVENT_REASONS,
                PayrollDependantValidator::CLAIM_REASONS,
            ),
        );
    }
}
