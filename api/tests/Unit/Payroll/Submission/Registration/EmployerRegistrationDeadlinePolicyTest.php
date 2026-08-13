<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\EmployerRegistrationDeadlinePolicy;
use PHPUnit\Framework\TestCase;

final class EmployerRegistrationDeadlinePolicyTest extends TestCase
{
    private EmployerRegistrationDeadlinePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new EmployerRegistrationDeadlinePolicy();
    }

    public function testCreatesWindowForFirstEmployeeRegistration(): void
    {
        $window = $this->policy->forFirstEmployeeStart('2026-07-20');

        self::assertSame('2026-07-05', $window->earliestRegistrationOn);
        self::assertSame('2026-07-16', $window->dueOn);
        self::assertSame('2026-07-05', $window->deemedEmployerFrom);
        self::assertSame('2026-07-28', $window->noShowNotificationDueOn);
        self::assertSame('czech_working_days', $window->calendarBasis);
        self::assertSame(
            'cz-jmhz-employer-registration-2026-07.v1',
            $window->rulesetId,
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $window->rulesetHash);
    }

    public function testCountsTwoWorkingDaysAcrossWeekendAndHoliday(): void
    {
        $window = $this->policy->forFirstEmployeeStart('2026-07-07');

        self::assertSame('2026-07-02', $window->dueOn);
    }

    public function testRulesetHashIsStable(): void
    {
        $first = $this->policy->forFirstEmployeeStart('2026-07-20');
        $second = $this->policy->forFirstEmployeeStart('2027-03-01');

        self::assertSame($first->rulesetHash, $second->rulesetHash);
    }

    public function testRejectsOldOrInvalidStartDate(): void
    {
        foreach (['2026-06-30', '2026-07-00', 'not-a-date'] as $date) {
            try {
                $this->policy->forFirstEmployeeStart($date);
                self::fail("Datum {$date} musí být odmítnuto.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
