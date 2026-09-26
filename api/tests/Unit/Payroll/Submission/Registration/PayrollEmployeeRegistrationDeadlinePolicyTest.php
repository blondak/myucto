<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\EmployerRegistrationDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollEmployeeRegistrationDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlException;
use PHPUnit\Framework\TestCase;

final class PayrollEmployeeRegistrationDeadlinePolicyTest extends TestCase
{
    private PayrollEmployeeRegistrationDeadlinePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PayrollEmployeeRegistrationDeadlinePolicy();
    }

    public function testRegistrationWindowOpensEightCalendarDaysBeforeStart(): void
    {
        $window = $this->policy->forEmploymentStart('2026-09-15');

        self::assertSame('2026-09-07', $window->earliestRegistrationOn);
        self::assertSame('2026-09-15', $window->dueOn);
        self::assertSame('calendar_days', $window->calendarBasis);
        self::assertSame(
            'cz-employee-registration-2026-07.v1',
            $window->rulesetId,
        );
    }

    /**
     * Osm dnů se počítá KALENDÁŘNĚ, i když okno protne víkend a svátek.
     * Nástup v úterý 6. 7. 2027 (6. 7. je státní svátek, 3.–4. 7. víkend):
     * okno se nesmí o svátek posunout, jinak by šlo podat dřív, než zákon
     * připouští.
     */
    public function testWindowDoesNotShiftAroundWeekendsOrHolidays(): void
    {
        $window = $this->policy->forEmploymentStart('2027-07-06');

        self::assertSame('2027-06-28', $window->earliestRegistrationOn);
        self::assertSame('2027-07-06', $window->dueOn);
    }

    /**
     * Kontrast s lhůtou ZAMĚSTNAVATELE, která naopak pracovní dny počítá —
     * záměna těch dvou je doložená chyba metodiky a nesmí se stát v kódu.
     */
    public function testEmployeeDeadlineDiffersFromTheEmployerDeadline(): void
    {
        $employee = $this->policy->forEmploymentStart('2026-09-15');
        $employer = (new EmployerRegistrationDeadlinePolicy())
            ->forFirstEmployeeStart('2026-09-15');

        self::assertSame('2026-09-15', $employee->dueOn);
        // Zaměstnavatel: dva PRACOVNÍ dny předem (pátek 11. 9.), nejdříve 15 dnů.
        self::assertSame('2026-09-11', $employer->dueOn);
        self::assertSame('2026-08-31', $employer->earliestRegistrationOn);
        self::assertNotSame($employee->dueOn, $employer->dueOn);
        self::assertNotSame(
            $employee->calendarBasis,
            $employer->calendarBasis,
        );
    }

    public function testNoShowWindowRunsEightDaysFromTheExpectedStart(): void
    {
        $window = $this->policy->forNoShow('2026-09-15');

        self::assertSame('2026-09-15', $window->earliestRegistrationOn);
        self::assertSame('2026-09-23', $window->dueOn);
        self::assertSame(
            'cz-employee-registration-no-show-2026-07.v1',
            $window->rulesetId,
        );
    }

    /**
     * Otisk rulesetu je stabilní a pro obě lhůty různý — jinak by se
     * v registru povinností nedalo doložit, podle jakého pravidla termín
     * vznikl.
     */
    public function testRulesetHashesAreStableAndDistinct(): void
    {
        $registration = $this->policy->forEmploymentStart('2026-09-15');
        $noShow = $this->policy->forNoShow('2026-09-15');

        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $registration->rulesetHash,
        );
        self::assertSame(
            $registration->rulesetHash,
            $this->policy->forEmploymentStart('2027-01-02')->rulesetHash,
        );
        self::assertNotSame($registration->rulesetHash, $noShow->rulesetHash);
    }

    /**
     * Před účinností povinnosti se lhůta neodvozuje, podání se ale NEBLOKUJE.
     *
     * Podle Pravidel pro REGZEC se událost do 31. 3. 2026 neohlášená do té
     * doby hlásí od 1. 4. 2026 jen přes REGZEC; cizí programy přihlášky
     * s nástupem od ledna 2026 podávaly a ČSSZ je přijala. Termínem je den
     * nástupu (podání je po lhůtě) a okno nese vysvětlení.
     */
    public function testStartBeforeTheEffectiveDateIsFiledLateWithoutDerivedDeadline(): void
    {
        $window = $this->policy->forEmploymentStart('2026-02-15');

        self::assertFalse($window->derived);
        self::assertSame('2026-02-15', $window->dueOn);
        self::assertSame('2026-02-07', $window->earliestRegistrationOn);
        self::assertStringContainsString('1. 7. 2026', (string) $window->notice);
        // Ruleset zůstává přihláškový — podle něj se v doručence poznává
        // přijatá registrace a přebírá OIČ a ID PPV.
        self::assertSame(
            PayrollEmployeeRegistrationDeadlinePolicy::REGISTRATION_RULESET_ID,
            $window->rulesetId,
        );
        self::assertNotSame(
            $this->policy->forEmploymentStart('2026-07-01')->rulesetHash,
            $window->rulesetHash,
        );
        self::assertTrue($this->policy->forEmploymentStart('2026-07-01')->derived);
        self::assertFalse(
            $this->policy->forFullRegistrationAfterPreRegistration('2025-11-03')->derived,
        );
    }

    public function testNoShowBeforeTheEffectiveDateIsStillRefused(): void
    {
        $this->expectException(PayrollRegistrationXmlException::class);
        $this->expectExceptionMessage('1. 7. 2026');

        $this->policy->forNoShow('2026-06-30');
    }

    /**
     * Doplnění plné registrace po předregistraci má osm dnů PO nástupu.
     *
     * Dokud to spadalo do lhůty přihlášky, byl termínem den nástupu: aplikace
     * hlásila zpoždění, které nenastalo, a tlačila účetní podat dřív, než
     * musí — a to zrovna u případu, kde předregistrace existuje právě proto,
     * že údaje ještě nejsou pohromadě.
     */
    public function testPlnaRegistracePoPredregistraciMaOsmDnuPoNastupu(): void
    {
        $window = $this->policy->forFullRegistrationAfterPreRegistration(
            '2026-09-10',
        );

        self::assertSame('2026-09-18', $window->dueOn);
        // Doplnit údaje jde i dřív, jakmile je zaměstnavatel má.
        self::assertSame('2026-09-02', $window->earliestRegistrationOn);
        self::assertSame('calendar_days', $window->calendarBasis);
        self::assertNotSame(
            $this->policy->forEmploymentStart('2026-09-10')->rulesetId,
            $window->rulesetId,
            'Vlastní lhůta musí mít vlastní ruleset, ne recyklovat přihlášku.',
        );
    }

    public function testMalformedStartDateIsRefusedDeterministically(): void
    {
        foreach (['2026-13-01', '15.9.2026', '', '2026-09-31'] as $value) {
            try {
                $this->policy->forEmploymentStart($value);
                self::fail("Datum {$value} mělo být odmítnuto.");
            } catch (PayrollRegistrationXmlException $exception) {
                self::assertSame(
                    'registration_deadline_start_date_invalid',
                    $exception->validationCode,
                );
            }
        }
    }
}
