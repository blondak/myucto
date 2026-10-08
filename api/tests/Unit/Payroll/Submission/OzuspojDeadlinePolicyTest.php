<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojDeadlinePolicy;
use PHPUnit\Framework\TestCase;

/**
 * Lhůta oznámení skončení uplatňování slevy (§ 23e odst. 2, § 23 odst. 1 a 3
 * zákona č. 589/1992 Sb.). Všechna data jsou syntetická.
 */
final class OzuspojDeadlinePolicyTest extends TestCase
{
    /** 8. 8. 2026 je sobota, lhůta se posune na pondělí 10. 8. */
    public function testEndDeadlineFallingOnSaturdayMovesToMonday(): void
    {
        $window = (new OzuspojDeadlinePolicy())->forIntentEnd('2026-07-31');

        self::assertSame('2026-08-10', $window->dueOn);
        self::assertSame('2026-07-31', $window->earliestNotificationOn);
    }

    public function testEndOnWorkingDayStaysOnEighthDay(): void
    {
        $window = (new OzuspojDeadlinePolicy())->forIntentEnd('2026-08-31');

        self::assertSame('2026-09-08', $window->dueOn);
    }

    /**
     * Záměr končící uprostřed měsíce slevu za ten měsíc nezaloží (kontrola 291),
     * posledním měsícem uplatnění je tedy měsíc předchozí.
     */
    public function testMidMonthEndCountsFromThePreviousMonth(): void
    {
        $window = (new OzuspojDeadlinePolicy())->forIntentEnd('2026-09-15');

        self::assertSame('2026-09-08', $window->dueOn);
        self::assertSame('2026-09-08', $window->earliestNotificationOn);
    }

    public function testMidMonthEndWithEarlierDayKeepsTheEndDayAsEarliest(): void
    {
        $window = (new OzuspojDeadlinePolicy())->forIntentEnd('2026-09-05');

        self::assertSame('2026-09-05', $window->earliestNotificationOn);
        self::assertSame('2026-09-08', $window->dueOn);
        self::assertLessThanOrEqual($window->dueOn, $window->earliestNotificationOn);
    }

    public function testMidMonthEndWithWeekendDeadlineShiftsToWorkingDay(): void
    {
        $window = (new OzuspojDeadlinePolicy())->forIntentEnd('2026-08-20');

        self::assertSame('2026-08-10', $window->dueOn);
        self::assertSame('2026-08-10', $window->earliestNotificationOn);
    }
}
