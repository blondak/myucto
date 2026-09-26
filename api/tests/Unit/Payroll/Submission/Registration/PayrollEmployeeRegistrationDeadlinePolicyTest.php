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

    /**
     * Odhlášení při skončení a nenastoupení mají vlastní odstavce § 19
     * zákona č. 323/2025 Sb. Dřív kód citoval u nenastoupení § 17 odst. 5
     * (povinnost zaměstnavatele, ne zaměstnance) a u A2 „paragraf k doložení".
     */
    public function testLegalBasisCitesTheEmployeeParagraphs(): void
    {
        $basis = PayrollEmployeeRegistrationDeadlinePolicy::LEGAL_BASIS;

        self::assertSame('§ 19 odst. 6 písm. a) zákona č. 323/2025 Sb.', $basis['termination']);
        self::assertSame('§ 19 odst. 4 zákona č. 323/2025 Sb.', $basis['no_show']);
        self::assertSame('§ 19 odst. 5 zákona č. 323/2025 Sb.', $basis['change']);
    }

    /**
     * Oprava citací nesmí pohnout otiskem rulesetu: nese ho už evidovaná
     * povinnost a jiný otisk by u ní vyrobil nový požadavek se stejným klíčem.
     */
    public function testCitationFixKeepsRulesetHashes(): void
    {
        self::assertSame(
            '7ff6c541a0f0ead9848e25bd1da926a0c2ad0bf1fe543aeae18ab2f0b0850fde',
            $this->policy->forEmploymentStart('2026-09-15')->rulesetHash,
        );
        self::assertSame(
            'fa0fb189eb3ecb9f581cc66845c6cfda3b1f50a31f4f78e45d00e88ae00b2c72',
            $this->policy->forNoShow('2026-09-15')->rulesetHash,
        );
        self::assertSame(
            'e71daa70b517a39e6f41ce21113bb7e686968ecdebabcbec5a081f3f0ad99216',
            $this->policy->forFollowUp(2, '2026-09-15')->rulesetHash,
        );
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
     * Před účinností povinnosti se lhůta neodvozuje. Vrátit nějaké datum
     * „aby to nespadlo" znamená tvrdit termín, který ze zákona neplyne.
     */
    public function testStartBeforeTheEffectiveDateIsRefused(): void
    {
        $this->expectException(PayrollRegistrationXmlException::class);
        $this->expectExceptionMessage('1. 7. 2026');

        $this->policy->forEmploymentStart('2026-06-30');
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
