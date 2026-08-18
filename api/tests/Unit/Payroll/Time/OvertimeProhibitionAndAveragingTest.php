<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Time;

use MyInvoice\Service\Payroll\Time\Overtime\OvertimeCompensation;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeConsentWindow;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeEmploymentProfile;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimitAssessment;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimitEvaluator;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimitFinding;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeLimits;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeProtectionWindow;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeSegment;
use PHPUnit\Framework\TestCase;

/**
 * Vyrovnávací období podle § 93 odst. 4 a 5 a zákazy práce přesčas podle
 * § 78 odst. 1 písm. i), § 240 odst. 3 a § 245 odst. 1.
 *
 * Vyrovnávací okno se tady schválně zkracuje `employmentStart`em na čtyři celé
 * týdny (26. 1. – 22. 2. 2026), takže je strop rovných 4 × 8 h = 1920 minut
 * a hranici jde trefit na minutu. Přesčas je přitom krytý dohodou podle
 * § 93 odst. 3, aby do výsledku nemluvily limity nařízeného přesčasu podle
 * odst. 2 — odstavec 4 se týká CELKOVÉHO rozsahu, tedy i přesčasu dohodnutého.
 */
final class OvertimeProhibitionAndAveragingTest extends TestCase
{
    private const AVERAGING_WINDOW_LIMIT = 1_920;

    public function testAveragingWindowExactlyAtTheLimitIsSilent(): void
    {
        $assessment = $this->assess('2026-02-01', '2026-02-28', [
            ['2026-01-26', 480],
            ['2026-02-02', 480],
            ['2026-02-09', 480],
            ['2026-02-16', 480],
        ], consents: [new OvertimeConsentWindow('2026-01-01')], employmentStart: '2026-01-26');

        self::assertSame(self::AVERAGING_WINDOW_LIMIT, $assessment->averagingMinutes);
        self::assertSame(self::AVERAGING_WINDOW_LIMIT, $assessment->averagingLimitMinutes);
        self::assertSame(4, $assessment->averagingWeeks);
        self::assertSame([], $this->codes($assessment->findings));
    }

    public function testAveragingWindowOneMinuteAboveTheLimitWarns(): void
    {
        $assessment = $this->assess('2026-02-01', '2026-02-28', [
            ['2026-01-26', 481],
            ['2026-02-02', 480],
            ['2026-02-09', 480],
            ['2026-02-16', 480],
        ], consents: [new OvertimeConsentWindow('2026-01-01')], employmentStart: '2026-01-26');

        $findings = $this->of($assessment->findings, OvertimeLimitFinding::CODE_AVERAGING);
        self::assertCount(1, $findings);
        self::assertSame('warning', $findings[0]->severity);
        self::assertFalse($findings[0]->requiresOverride);
        self::assertSame(1_921, $findings[0]->actualMinutes);
        self::assertSame(self::AVERAGING_WINDOW_LIMIT, $findings[0]->limitMinutes);
        self::assertSame('2026-01-26', $findings[0]->scopeFrom);
        self::assertSame('2026-02-22', $findings[0]->scopeTo);
        self::assertSame('§ 93 odst. 4 zákoníku práce', $findings[0]->provision);
    }

    /**
     * § 93 odst. 5 — přesčas, za který bylo poskytnuto náhradní volno, se do
     * vyrovnávacího období nezahrnuje. Jediná kompenzovaná minuta proto vrátí
     * okno pod strop.
     */
    public function testCompensatedOvertimeDropsOutOfTheAveragingWindow(): void
    {
        $assessment = $this->assess('2026-02-01', '2026-02-28', [
            ['2026-01-26', 481],
            ['2026-02-02', 480],
            ['2026-02-09', 480],
            ['2026-02-16', 480],
        ], consents: [new OvertimeConsentWindow('2026-01-01')], employmentStart: '2026-01-26', compensations: [
            new OvertimeCompensation('2026-01-26', 1),
        ]);

        self::assertSame(1, $assessment->averagingCompensatedMinutes);
        self::assertSame(self::AVERAGING_WINDOW_LIMIT, $assessment->averagingMinutes);
        self::assertSame([], $this->codes($assessment->findings));
    }

    /**
     * Typický zdroj vady: náhradní volno zapsané ve větším rozsahu, než kolik
     * se ten den přesčasu odpracovalo, by z okna odečetlo hodiny, které nikdy
     * neexistovaly.
     */
    public function testCompensationIsCappedByTheOvertimeActuallyWorkedThatDay(): void
    {
        $assessment = $this->assess('2026-02-01', '2026-02-28', [
            ['2026-01-26', 481],
            ['2026-02-02', 480],
            ['2026-02-09', 480],
            ['2026-02-16', 480],
        ], consents: [new OvertimeConsentWindow('2026-01-01')], employmentStart: '2026-01-26', compensations: [
            new OvertimeCompensation('2026-02-02', 6_000),
        ]);

        self::assertSame(480, $assessment->averagingCompensatedMinutes);
        self::assertSame(1_441, $assessment->averagingMinutes);
    }

    /**
     * Vynětí podle odst. 5 je navázané VÝHRADNĚ na vyrovnávací období podle
     * odst. 4. Nařízený přesčas podle odst. 2 se náhradním volnem nesnižuje.
     */
    public function testCompensationDoesNotReduceTheOrderedWeeklyLimit(): void
    {
        $assessment = $this->assess('2026-02-01', '2026-02-28', [
            ['2026-02-09', 481],
        ], compensations: [new OvertimeCompensation('2026-02-09', 481)]);

        $findings = $this->of($assessment->findings, OvertimeLimitFinding::CODE_WEEKLY);
        self::assertCount(1, $findings);
        self::assertSame(481, $findings[0]->actualMinutes);
        self::assertSame(481, $assessment->orderedYearMinutes);
    }

    /**
     * § 350a — týdnem je kterýchkoli 7 po sobě následujících dnů. Přesčas
     * rozložený přes neděli a pondělí neporuší žádný kalendářní týden, a přesto
     * jde o 9 hodin v sedmi dnech.
     */
    public function testRollingSevenDayWindowCatchesWhatTheCalendarGridMisses(): void
    {
        $assessment = $this->assess('2026-02-01', '2026-02-28', [
            ['2026-02-15', 300],
            ['2026-02-16', 240],
        ]);

        self::assertNotContains(
            OvertimeLimitFinding::CODE_WEEKLY,
            $this->codes($assessment->findings),
        );
        $findings = $this->of($assessment->findings, OvertimeLimitFinding::CODE_ROLLING_WEEK);
        self::assertCount(1, $findings);
        self::assertSame('info', $findings[0]->severity);
        self::assertSame(540, $findings[0]->actualMinutes);
        self::assertSame('2026-02-15', $findings[0]->scopeFrom);
        self::assertSame('2026-02-21', $findings[0]->scopeTo);
    }

    /** Překročený kalendářní týden se nesmí ohlásit podruhé jako klouzavé okno. */
    public function testRollingWindowStaysQuietWhenTheCalendarWeekAlreadyWarned(): void
    {
        $assessment = $this->assess('2026-02-01', '2026-02-28', [
            ['2026-02-09', 300],
            ['2026-02-11', 300],
        ]);

        self::assertSame(
            [OvertimeLimitFinding::CODE_WEEKLY],
            $this->codes($assessment->findings),
        );
    }

    /**
     * § 245 odst. 1 — zákaz je absolutní, dohoda podle § 93 odst. 3 ho
     * neprolomí. Den po osmnáctých narozeninách už ale zákaz neplatí.
     */
    public function testJuvenileOvertimeIsProhibitedEvenWithConsent(): void
    {
        $assessment = $this->assess('2026-02-01', '2026-02-28', [
            ['2026-02-10', 120],
            ['2026-02-25', 180],
        ], consents: [new OvertimeConsentWindow('2026-01-01')], profile: new OvertimeEmploymentProfile('2008-02-20'));

        $findings = $this->of(
            $assessment->findings,
            OvertimeLimitFinding::CODE_PROHIBITED_JUVENILE,
        );
        self::assertCount(1, $findings);
        self::assertSame('warning', $findings[0]->severity);
        self::assertTrue($findings[0]->requiresOverride);
        self::assertSame(120, $findings[0]->actualMinutes);
        self::assertSame('2026-02-10', $findings[0]->scopeFrom);
        self::assertSame('§ 245 odst. 1 zákoníku práce', $findings[0]->provision);
        self::assertSame(['juvenile' => 120], $assessment->prohibitedMinutes);
    }

    public function testAdultEmployeeRaisesNoProhibition(): void
    {
        $assessment = $this->assess('2026-02-01', '2026-02-28', [
            ['2026-02-10', 120],
        ], profile: new OvertimeEmploymentProfile('1990-03-14'));

        self::assertSame([], $this->codes($assessment->findings));
        self::assertSame([], $assessment->prohibitedMinutes);
    }

    /** § 240 odst. 3 věta první — absolutní zákaz, dohoda ho neprolomí. */
    public function testPregnancyProhibitsEvenAgreedOvertime(): void
    {
        $assessment = $this->assess('2026-02-01', '2026-02-28', [
            ['2026-02-10', 120],
        ], consents: [new OvertimeConsentWindow('2026-01-01')], protections: [
            new OvertimeProtectionWindow(OvertimeProtectionWindow::PREGNANCY, '2026-01-15'),
        ], profile: new OvertimeEmploymentProfile('1990-03-14'));

        $findings = $this->of(
            $assessment->findings,
            OvertimeLimitFinding::CODE_PROHIBITED_PREGNANCY,
        );
        self::assertCount(1, $findings);
        self::assertTrue($findings[0]->requiresOverride);
        self::assertSame(120, $findings[0]->actualMinutes);
    }

    /**
     * § 240 odst. 3 věta druhá — zakázané je jen NAŘÍZENÍ. S evidovanou dohodou
     * podle § 93 odst. 3 je přesčas v pořádku, bez ní ne.
     */
    public function testChildCareProhibitsOrderedOvertimeButNotAgreedOne(): void
    {
        $protections = [new OvertimeProtectionWindow(
            OvertimeProtectionWindow::CHILD_UNDER_ONE,
            '2026-01-01',
            '2026-06-30',
        )];
        $profile = new OvertimeEmploymentProfile('1990-03-14');

        $ordered = $this->assess('2026-02-01', '2026-02-28', [
            ['2026-02-10', 120],
        ], protections: $protections, profile: $profile);
        $findings = $this->of(
            $ordered->findings,
            OvertimeLimitFinding::CODE_PROHIBITED_CHILD_CARE,
        );
        self::assertCount(1, $findings);
        self::assertTrue($findings[0]->requiresOverride);
        self::assertSame(
            '§ 240 odst. 3 věta druhá zákoníku práce',
            $findings[0]->provision,
        );

        $agreed = $this->assess('2026-02-01', '2026-02-28', [
            ['2026-02-10', 120],
        ], consents: [new OvertimeConsentWindow('2026-01-01')], protections: $protections, profile: $profile);
        self::assertSame([], $this->codes($agreed->findings));
    }

    /**
     * § 78 odst. 1 písm. i) věta druhá — zaměstnanci s kratší pracovní dobou
     * není možné přesčas nařídit. Úvazek se přitom mění uprostřed vyrovnávacího
     * období, takže se musí posuzovat ke dni přesčasu, ne k datu posouzení.
     */
    public function testPartTimeProhibitionFollowsTheWorkloadChange(): void
    {
        $profile = new OvertimeEmploymentProfile('1990-03-14', [
            ['from' => '2026-01-01', 'to' => '2026-01-31', 'basis_points' => 5_000],
            ['from' => '2026-02-01', 'to' => null, 'basis_points' => 10_000],
        ]);

        $assessment = $this->assess('2026-01-01', '2026-02-28', [
            ['2026-01-13', 120],
            ['2026-02-10', 120],
        ], profile: $profile);

        $findings = $this->of(
            $assessment->findings,
            OvertimeLimitFinding::CODE_PROHIBITED_PART_TIME,
        );
        self::assertCount(1, $findings);
        self::assertSame(120, $findings[0]->actualMinutes);
        self::assertTrue($findings[0]->requiresOverride);
        self::assertSame(['part_time' => 120], $assessment->prohibitedMinutes);
    }

    public function testMissingBirthDateIsReportedInsteadOfAssumingAdulthood(): void
    {
        $assessment = $this->assess('2026-02-01', '2026-02-28', [
            ['2026-02-10', 120],
        ], profile: new OvertimeEmploymentProfile(null));

        $findings = $this->of(
            $assessment->findings,
            OvertimeLimitFinding::CODE_BIRTH_DATE_MISSING,
        );
        self::assertCount(1, $findings);
        self::assertSame('info', $findings[0]->severity);
        self::assertFalse($findings[0]->requiresOverride);
    }

    /**
     * § 93 odst. 4 — období delší než 26 týdnů smí vymezit „jen kolektivní
     * smlouva". Nedoložených 52 týdnů nesmí vůbec vzniknout.
     */
    public function testAveragingPeriodOverTwentySixWeeksNeedsACollectiveAgreement(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OvertimeLimits(480, 9_000, 480, 52, 8_000, true);
    }

    public function testCollectiveAgreementAveragingPeriodNeedsAReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OvertimeLimits(
            480,
            9_000,
            480,
            52,
            8_000,
            true,
            OvertimeLimits::BASIS_COLLECTIVE_AGREEMENT,
            null,
        );
    }

    public function testCollectiveAgreementAveragingPeriodCannotExceedFiftyTwoWeeks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OvertimeLimits(
            480,
            9_000,
            480,
            53,
            8_000,
            true,
            OvertimeLimits::BASIS_COLLECTIVE_AGREEMENT,
            'KS/2026',
        );
    }

    public function testDocumentedCollectiveAgreementWidensTheWindowAndSaysWhy(): void
    {
        $limits = (new OvertimeLimits(480, 9_000, 480, 26, 8_000, true))
            ->withAveragingPeriod(52, OvertimeLimits::BASIS_COLLECTIVE_AGREEMENT, 'KS/2026 čl. 12');

        self::assertSame(52, $limits->averagingMaxWeeks);
        self::assertSame('KS/2026 čl. 12', $limits->averagingReference);

        $assessment = (new OvertimeLimitEvaluator())->assess(
            7,
            '2026-02-01',
            '2026-02-28',
            [new OvertimeSegment('2026-01-26', 600)],
            [new OvertimeConsentWindow('2026-01-01')],
            $limits,
            '2026-01-26',
        );

        self::assertSame(
            OvertimeLimits::BASIS_COLLECTIVE_AGREEMENT,
            $assessment->averagingBasis,
        );
        self::assertSame('KS/2026 čl. 12', $assessment->averagingReference);
    }

    /**
     * @param list<array{0:string,1:int}> $segments
     * @param list<OvertimeConsentWindow> $consents
     * @param list<OvertimeCompensation> $compensations
     * @param list<OvertimeProtectionWindow> $protections
     */
    private function assess(
        string $periodStart,
        string $periodEnd,
        array $segments,
        array $consents = [],
        ?string $employmentStart = null,
        array $compensations = [],
        array $protections = [],
        ?OvertimeEmploymentProfile $profile = null,
    ): OvertimeLimitAssessment {
        return (new OvertimeLimitEvaluator())->assess(
            7,
            $periodStart,
            $periodEnd,
            array_map(
                static fn (array $row): OvertimeSegment => new OvertimeSegment($row[0], $row[1]),
                $segments,
            ),
            $consents,
            new OvertimeLimits(480, 9_000, 480, 26, 8_000, true),
            $employmentStart,
            $compensations,
            $protections,
            $profile,
        );
    }

    /**
     * @param list<OvertimeLimitFinding> $findings
     * @return list<string>
     */
    private function codes(array $findings): array
    {
        return array_map(
            static fn (OvertimeLimitFinding $finding): string => $finding->code,
            $findings,
        );
    }

    /**
     * @param list<OvertimeLimitFinding> $findings
     * @return list<OvertimeLimitFinding>
     */
    private function of(array $findings, string $code): array
    {
        return array_values(array_filter(
            $findings,
            static fn (OvertimeLimitFinding $finding): bool => $finding->code === $code,
        ));
    }
}
