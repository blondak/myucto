<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojClaimDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojDiscountEligibility;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojEligibilityOutcome;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojIntentEvidence;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojIntentStatus;
use PHPUnit\Framework\TestCase;

/**
 * Kontrola 291 katalogu kontrol MH přehraná nad vlastní evidencí záměrů.
 *
 * Kontrola je u ČSSZ propustná: podání se přijme, sleva se ale neuzná a
 * zaměstnavatel se to dozví až z protokolu, kdy je pojistné odvedené ponížené.
 * Každý test tady popisuje jeden způsob, jak se to dá zjistit dřív.
 */
final class OzuspojDiscountEligibilityTest extends TestCase
{
    private function eligibility(): OzuspojDiscountEligibility
    {
        return new OzuspojDiscountEligibility(new OzuspojClaimDeadlinePolicy());
    }

    private function intent(
        string $from,
        ?string $to,
        ?string $acceptedOn,
        OzuspojIntentStatus $status = OzuspojIntentStatus::Accepted,
    ): OzuspojIntentEvidence {
        return new OzuspojIntentEvidence($status, $from, $to, $acceptedOn);
    }

    /** § 7a odst. 5: bez oznámeného záměru sleva prostě nenáleží. */
    public function testMissingIntentBlocksTheDiscount(): void
    {
        $verdict = $this->eligibility()->assess(
            null,
            '2026-06-01',
            '2026-06-30',
            '2025-01-01',
            null,
        );

        self::assertSame(
            OzuspojEligibilityOutcome::NotNotified,
            $verdict->outcome,
        );
        self::assertFalse($verdict->allowsDiscount());
    }

    /**
     * Vyplněný, ale ještě nepodaný nebo ČSSZ odmítnutý záměr nesmí projít.
     * Nárok zakládá DORUČENÍ, ne to, že někdo něco v aplikaci vyplnil.
     */
    public function testOnlyAcceptedOrEndedIntentsCount(): void
    {
        foreach ([
            OzuspojIntentStatus::Draft,
            OzuspojIntentStatus::Submitted,
            OzuspojIntentStatus::Rejected,
            OzuspojIntentStatus::Cancelled,
        ] as $status) {
            $verdict = $this->eligibility()->assess(
                $this->intent('2026-01-01', null, null, $status),
                '2026-06-01',
                '2026-06-30',
                '2025-01-01',
                null,
            );

            self::assertSame(
                OzuspojEligibilityOutcome::NotNotified,
                $verdict->outcome,
                $status->value,
            );
        }
    }

    /** Doložený a včas doručený záměr slevu pouští dál. */
    public function testAcceptedIntentCoveringThePeriodAllowsTheDiscount(): void
    {
        $verdict = $this->eligibility()->assess(
            $this->intent('2026-01-01', null, '2025-12-15'),
            '2026-06-01',
            '2026-06-30',
            '2025-01-01',
            null,
        );

        self::assertSame(
            OzuspojEligibilityOutcome::Evidenced,
            $verdict->outcome,
        );
        self::assertTrue($verdict->allowsDiscount());
        self::assertFalse($verdict->transitionalQ12026);
    }

    /**
     * Doručení až po splatnosti pojistného. § 7c odst. 2 slevu po tomhle dni
     * uplatnit nedovolí, takže pozdější záměr už nemá co doprovodit.
     * Splatnost za 06/2026 je 20. 7. 2026 (pondělí).
     */
    public function testIntentDeliveredAfterTheLevyDueDateIsTooLate(): void
    {
        $verdict = $this->eligibility()->assess(
            $this->intent('2026-06-01', null, '2026-07-21'),
            '2026-06-01',
            '2026-06-30',
            '2025-01-01',
            null,
        );

        self::assertSame(
            OzuspojEligibilityOutcome::NotifiedLate,
            $verdict->outcome,
        );
        self::assertSame('2026-07-20', $verdict->intentDeadlineOn);
    }

    /**
     * Kontrola 291 bod 2b: záměr doručený až PO přijetí měsíčního hlášení
     * nestihl „nejpozději s uplatněním slevy", i když do splatnosti zbývá čas.
     */
    public function testIntentDeliveredAfterTheClaimIsTooLate(): void
    {
        $verdict = $this->eligibility()->assess(
            $this->intent('2026-06-01', null, '2026-07-15'),
            '2026-06-01',
            '2026-06-30',
            '2025-01-01',
            null,
            '2026-07-10',
        );

        self::assertSame(
            OzuspojEligibilityOutcome::NotifiedLate,
            $verdict->outcome,
        );
    }

    /**
     * Splatnost se posouvá na nejbližší pracovní den: za 05/2026 vychází
     * 20. 6. 2026 na sobotu, takže lhůta končí až v pondělí 22. 6. 2026.
     */
    public function testTheIntentDeadlineShiftsToTheNextWorkingDay(): void
    {
        $verdict = $this->eligibility()->assess(
            $this->intent('2026-05-01', null, '2026-06-22'),
            '2026-05-01',
            '2026-05-31',
            '2025-01-01',
            null,
        );

        self::assertSame('2026-06-22', $verdict->intentDeadlineOn);
        self::assertSame(
            OzuspojEligibilityOutcome::Evidenced,
            $verdict->outcome,
        );
    }

    /**
     * Kontrola 291 bod 1: záměr začíná až uprostřed měsíce, takže trvání
     * zaměstnání v tom měsíci do něj celé nespadá.
     */
    public function testIntentStartingInsideThePeriodDoesNotCoverIt(): void
    {
        $verdict = $this->eligibility()->assess(
            $this->intent('2026-06-15', null, '2026-06-01'),
            '2026-06-01',
            '2026-06-30',
            '2025-01-01',
            null,
        );

        self::assertSame(
            OzuspojEligibilityOutcome::OutsideIntentPeriod,
            $verdict->outcome,
        );
    }

    /**
     * Ukončený záměr. § 7b odst. 4 žádá splnění podmínek po celou dobu trvání
     * poměru v kalendářním měsíci — záměr uzavřený k 15. 6. tedy slevu za
     * červen nezaloží, i když zaměstnání pokračuje.
     */
    public function testEndedIntentStopsCoveringTheMonthItEndedIn(): void
    {
        $verdict = $this->eligibility()->assess(
            $this->intent(
                '2026-01-01',
                '2026-06-15',
                '2025-12-15',
                OzuspojIntentStatus::Ended,
            ),
            '2026-06-01',
            '2026-06-30',
            '2025-01-01',
            null,
        );

        self::assertSame(
            OzuspojEligibilityOutcome::OutsideIntentPeriod,
            $verdict->outcome,
        );
    }

    /**
     * Ukončený záměr POKRÝVÁ měsíc, ve kterém zaměstnání skončilo dřív než
     * záměr — konec pokrytí se počítá k poslednímu dni trvání poměru.
     */
    public function testEndedIntentCoversAMonthTheEmploymentEndedIn(): void
    {
        $verdict = $this->eligibility()->assess(
            $this->intent(
                '2026-01-01',
                '2026-06-15',
                '2025-12-15',
                OzuspojIntentStatus::Ended,
            ),
            '2026-06-01',
            '2026-06-30',
            '2025-01-01',
            '2026-06-15',
        );

        self::assertSame(
            OzuspojEligibilityOutcome::Evidenced,
            $verdict->outcome,
        );
    }

    /**
     * Nástup uprostřed měsíce. Kontrola 291 bere `ZAMEST_OD` jako pozdější
     * z data nástupu a prvního dne měsíce, takže záměr od 15. 6. tenhle měsíc
     * pokrývá, i když měsíc začal dřív.
     */
    public function testIntentMatchingAMidMonthStartCoversThePeriod(): void
    {
        $verdict = $this->eligibility()->assess(
            $this->intent('2026-06-15', null, '2026-06-10'),
            '2026-06-01',
            '2026-06-30',
            '2026-06-15',
            null,
        );

        self::assertSame(
            OzuspojEligibilityOutcome::Evidenced,
            $verdict->outcome,
        );
    }

    /**
     * Přechodné pravidlo pro 01–03/2026. Kontroly 164, 290 a 333 vážou slevu
     * za tahle období na 30. 6. 2026 a všechny tři jsou u nás `NotEvaluable`,
     * takže tohle je jediné místo, kde se hranice dá vyhodnotit dopředu.
     */
    public function testTransitionalQ12026ClaimDeadlineIsTheThirtiethOfJune(): void
    {
        foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $periodStart) {
            $periodEnd = (new \DateTimeImmutable($periodStart))
                ->modify('last day of this month')
                ->format('Y-m-d');
            $verdict = $this->eligibility()->assess(
                $this->intent('2026-01-01', null, '2026-01-05'),
                $periodStart,
                $periodEnd,
                '2025-01-01',
                null,
                '2026-07-01',
            );

            self::assertTrue($verdict->transitionalQ12026, $periodStart);
            self::assertSame(
                '2026-06-30',
                $verdict->claimDeadlineOn,
                $periodStart,
            );
            self::assertSame(
                OzuspojEligibilityOutcome::ClaimWindowClosed,
                $verdict->outcome,
                $periodStart,
            );
        }
    }

    /**
     * Do 30. 6. 2026 se sleva za Q1/2026 pořád uzná — přechodné pravidlo je
     * mírnější než běžná splatnost, ne přísnější.
     */
    public function testTransitionalQ12026StillAllowsTheClaimUntilTheDeadline(): void
    {
        $verdict = $this->eligibility()->assess(
            $this->intent('2026-01-01', null, '2026-01-05'),
            '2026-01-01',
            '2026-01-31',
            '2025-01-01',
            null,
            '2026-06-30',
        );

        self::assertSame(
            OzuspojEligibilityOutcome::Evidenced,
            $verdict->outcome,
        );
    }

    /** Mimo přechodná období je hranicí uplatnění splatnost pojistného. */
    public function testOrdinaryPeriodsCloseTheClaimAtTheLevyDueDate(): void
    {
        $verdict = $this->eligibility()->assess(
            $this->intent('2026-04-01', null, '2026-04-01'),
            '2026-04-01',
            '2026-04-30',
            '2025-01-01',
            null,
            '2026-05-21',
        );

        self::assertFalse($verdict->transitionalQ12026);
        self::assertSame('2026-05-20', $verdict->claimDeadlineOn);
        self::assertSame(
            OzuspojEligibilityOutcome::ClaimWindowClosed,
            $verdict->outcome,
        );
    }
}
