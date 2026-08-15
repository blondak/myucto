<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Submission\DefectConsequence;
use MyInvoice\Service\Submission\DefectGround;
use MyInvoice\Service\Submission\DefectNoticeAssessment;
use MyInvoice\Service\Submission\DefectNoticeAssessor;
use MyInvoice\Service\Submission\DefectNoticeOutcome;
use MyInvoice\Service\Submission\DefectNoticeStatus;
use MyInvoice\Service\Submission\SubmissionLegalRules;
use PHPUnit\Framework\TestCase;

/**
 * Výzva k odstranění vad podání — § 74 daňového řádu.
 *
 * ⚠️ Žádný test tady nesahá na síť.
 *
 * Co se hlídá:
 *   1. odstranění vady ve lhůtě zhojí podání (odst. 3), po lhůtě už ne,
 *   2. u vad podle písm. a) a b) je následkem NEÚČINNOST podání (odst. 4),
 *      u písm. c) a d) neúčinnost NENASTÁVÁ — to se nesmí splácat dohromady,
 *   3. neznámý údaj nikdy nevede na „v pořádku": bez lhůty je stav `unknown`
 *      a bez písmene je následek `unknown`,
 *   4. konec lhůty nepadne na sobotu, neděli ani svátek.
 */
final class DefectNoticeAssessorTest extends TestCase
{
    private DefectNoticeAssessor $assessor;

    protected function setUp(): void
    {
        $this->assessor = new DefectNoticeAssessor(
            new SubmissionLegalRules(CzechPayrollRulesets2026::provider()),
        );
    }

    /** § 74 odst. 3 — vada odstraněna ve lhůtě, na podání se hledí jako na řádné a včasné. */
    public function testDefectCuredWithinPeriodMakesTheSubmissionGoodAgain(): void
    {
        $result = $this->assess('2026-03-02', null, 15, DefectGround::NotProcessable, '2026-03-10', '2026-03-25');

        self::assertSame(DefectNoticeStatus::AnsweredInTime, $result->status);
        self::assertSame(DefectNoticeOutcome::Cured, $result->outcome);
        self::assertSame('2026-03-17', $result->respondBy?->format('Y-m-d'));
        self::assertSame('derived_from_days', $result->respondBySource);
        self::assertFalse($result->status->needsAttention());
        self::assertStringContainsString('§ 74 odst. 3', $result->sentence);
    }

    /** Po lhůtě už účinky odst. 3 nenastanou — a u písm. a) je podání neúčinné. */
    public function testAnswerAfterThePeriodDoesNotCureAnythingAndTheSubmissionBecomesIneffective(): void
    {
        $result = $this->assess('2026-03-02', null, 15, DefectGround::NotProcessable, '2026-03-20', '2026-03-25');

        self::assertSame(DefectNoticeStatus::AnsweredLate, $result->status);
        self::assertSame(DefectNoticeOutcome::Ineffective, $result->outcome);
        self::assertSame(DefectConsequence::Ineffective, $result->consequence);
        self::assertTrue($result->status->needsAttention());
    }

    /** Žádná odpověď a lhůta uplynula — § 74 odst. 4. */
    public function testNoAnswerAfterThePeriodMakesTheSubmissionIneffective(): void
    {
        $result = $this->assess('2026-03-02', null, 15, DefectGround::NoEffects, null, '2026-04-01');

        self::assertSame(DefectNoticeStatus::Missed, $result->status);
        self::assertSame(DefectNoticeOutcome::Ineffective, $result->outcome);
        self::assertStringContainsString('neúčinným', $result->sentence);
    }

    /**
     * Vady podle písm. c) a d) neúčinnost NEZPŮSOBUJÍ — § 74 odst. 4 mluví
     * výslovně jen o písmenech a) a b). Splynutí by u nich strašilo zánikem
     * podání, ke kterému nedojde.
     */
    public function testFormalDefectsDoNotMakeTheSubmissionIneffective(): void
    {
        foreach ([DefectGround::WrongWay, DefectGround::WrongFormat] as $ground) {
            $result = $this->assess('2026-03-02', null, 15, $ground, null, '2026-04-01');

            self::assertSame(DefectNoticeStatus::Missed, $result->status);
            self::assertSame(DefectConsequence::NoIneffectiveness, $result->consequence);
            self::assertSame(DefectNoticeOutcome::PenaltyRisk, $result->outcome);
            self::assertStringNotContainsString('stává neúčinným', $result->sentence);
        }
    }

    /**
     * Neznámé písmeno § 74 odst. 1 nesmí spadnout ani na „neúčinnost hrozí",
     * ani na „nehrozí". Nevíme je samostatná odpověď.
     */
    public function testUnknownGroundNeverClaimsAnythingAboutTheConsequence(): void
    {
        $result = $this->assess('2026-03-02', null, 15, DefectGround::Unknown, null, '2026-04-01');

        self::assertSame(DefectNoticeStatus::Missed, $result->status);
        self::assertSame(DefectConsequence::Unknown, $result->consequence);
        self::assertSame(DefectNoticeOutcome::Unknown, $result->outcome);
        self::assertStringContainsString('nelze říct', $result->sentence);
    }

    /**
     * NEJDŮLEŽITĚJŠÍ FAIL-CLOSED: bez lhůty se nesmí tvrdit nic. Ani „stihli
     * jsme to", ani „je čas". Aplikace lhůtu podle § 74 nedopočítává — zákon
     * žádnou délku nestanoví, určuje ji správce daně ve výzvě.
     */
    public function testWithoutADeadlineNothingIsClaimed(): void
    {
        $result = $this->assess('2026-03-02', null, null, DefectGround::NotProcessable, null, '2026-04-01');

        self::assertSame(DefectNoticeStatus::Unknown, $result->status);
        self::assertSame(DefectNoticeOutcome::Unknown, $result->outcome);
        self::assertNull($result->respondBy);
        self::assertSame('unknown', $result->respondBySource);
        self::assertNull($result->daysLeft);
        self::assertTrue(
            $result->status->needsAttention(),
            'Neznámá lhůta musí volat po pozornosti, ne mlčet.',
        );
    }

    /** Ani počet dnů bez dne doručení lhůtu neudělá — není od čeho počítat. */
    public function testPeriodWithoutADeliveryDateStillYieldsNoDeadline(): void
    {
        $result = $this->assess(null, null, 15, DefectGround::NotProcessable, null, '2026-04-01');

        self::assertSame(DefectNoticeStatus::Unknown, $result->status);
        self::assertNull($result->respondBy);
        self::assertStringContainsString('chybí den doručení', $result->sentence);
    }

    /** Datum napsané ve výzvě má přednost před dopočtem. */
    public function testDateStatedInTheNoticeWins(): void
    {
        $result = $this->assess('2026-03-02', '2026-03-31', 15, DefectGround::NotProcessable, null, '2026-03-20');

        self::assertSame('2026-03-31', $result->respondBy?->format('Y-m-d'));
        self::assertSame('stated_in_notice', $result->respondBySource);
        self::assertSame(DefectNoticeStatus::Open, $result->status);
        self::assertSame(11, $result->daysLeft);
    }

    /**
     * § 33 odst. 4 DŘ platí i na datum uvedené ve výzvě.
     * 14. 3. 2026 je sobota → pondělí 16. 3. 2026.
     */
    public function testDeadlineOnASaturdayMovesToTheNextWorkingDay(): void
    {
        $result = $this->assess('2026-03-02', '2026-03-14', null, DefectGround::NotProcessable, null, '2026-03-10');

        self::assertSame('2026-03-16', $result->respondBy?->format('Y-m-d'));
        self::assertTrue($result->respondByShifted);
    }

    /** A stejně tak na svátek — 6. 7. 2026 je Den upálení mistra Jana Husa. */
    public function testDeadlineOnAPublicHolidayMovesToTheNextWorkingDay(): void
    {
        $result = $this->assess('2026-06-25', '2026-07-06', null, DefectGround::NoEffects, null, '2026-07-01');

        self::assertSame('2026-07-07', $result->respondBy?->format('Y-m-d'));
        self::assertTrue($result->respondByShifted);
    }

    /**
     * § 32 odst. 2 DŘ — lhůta kratší než 8 dnů je zákonná, ale výjimečná.
     * Aplikace ji proto eviduje a jen upozorní; odmítnout ji by znamenalo
     * zahodit výzvu, která reálně přišla.
     */
    public function testSuspiciouslyShortPeriodIsFlaggedButAccepted(): void
    {
        $result = $this->assess('2026-03-02', null, 5, DefectGround::NotProcessable, null, '2026-03-04');

        self::assertTrue($result->suspiciouslyShortPeriod);
        self::assertSame(DefectNoticeStatus::Open, $result->status);
        self::assertNotNull($result->respondBy);
    }

    /** Vzatá zpět výzva neběží. */
    public function testWithdrawnNoticeStopsRunning(): void
    {
        $result = $this->assessor->assess(
            new \DateTimeImmutable('2026-03-02'),
            null,
            15,
            DefectGround::NotProcessable,
            null,
            new \DateTimeImmutable('2026-04-01'),
            true,
        );

        self::assertSame(DefectNoticeStatus::Withdrawn, $result->status);
        self::assertFalse($result->status->needsAttention());
    }

    /** Mapa písmen na následky musí zůstat úplná — nový případ ji nesmí obejít. */
    public function testEveryGroundHasAnExplicitConsequence(): void
    {
        $expected = [
            'a_not_processable' => DefectConsequence::Ineffective,
            'b_no_effects' => DefectConsequence::Ineffective,
            'c_wrong_way' => DefectConsequence::NoIneffectiveness,
            'd_wrong_format' => DefectConsequence::NoIneffectiveness,
            'unknown' => DefectConsequence::Unknown,
        ];

        self::assertSame(
            array_keys($expected),
            array_map(static fn (DefectGround $g): string => $g->value, DefectGround::cases()),
        );
        foreach (DefectGround::cases() as $ground) {
            self::assertSame($expected[$ground->value], $ground->consequence());
        }
    }

    private function assess(
        ?string $deliveredOn,
        ?string $statedRespondBy,
        ?int $statedPeriodDays,
        DefectGround $ground,
        ?string $respondedOn,
        string $today,
    ): DefectNoticeAssessment {
        $zone = new \DateTimeZone('Europe/Prague');

        return $this->assessor->assess(
            $deliveredOn !== null ? new \DateTimeImmutable($deliveredOn, $zone) : null,
            $statedRespondBy !== null ? new \DateTimeImmutable($statedRespondBy, $zone) : null,
            $statedPeriodDays,
            $ground,
            $respondedOn !== null ? new \DateTimeImmutable($respondedOn, $zone) : null,
            new \DateTimeImmutable($today . ' 10:00:00', $zone),
        );
    }
}
