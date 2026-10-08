<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Eldp\EldpDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpValidationException;
use MyInvoice\Service\Payroll\Submission\Eldp\PensionRequestDeadlinePolicy;
use PHPUnit\Framework\TestCase;

/**
 * Lhůty povinností důchodového pojištění vyvolaných výzvou nebo žádostí
 * (zákon č. 582/1991 Sb. § 37 odst. 2, § 38a, § 42; čl. V zák. č. 360/2025 Sb.).
 */
final class PensionRequestDeadlinePolicyTest extends TestCase
{
    private PensionRequestDeadlinePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PensionRequestDeadlinePolicy();
    }

    /** § 38a odst. 1: oprava údajů měsíčním hlášením do 8 dnů od doručení výzvy. */
    public function testJmhCorrectionIsDueEightDaysAfterDelivery(): void
    {
        $deadline = $this->policy->forRequest('jmh_correction', 'ossz', '2026-10-05');

        self::assertSame('2026-10-13', $deadline['due_on']);
        self::assertSame('jmh_correction_within_8_days', $deadline['rule']);
        self::assertStringContainsString('§ 38a odst. 1', $deadline['legal_basis']);
    }

    /** Konec lhůty v sobotu se posouvá na pondělí, stejně jako u evidenčního listu. */
    public function testDeadlineFallingOnAWeekendMovesToTheNextWorkingDay(): void
    {
        // 2. 10. 2026 je pátek, + 8 dnů = sobota 10. 10.
        $deadline = $this->policy->forRequest('jmh_correction', 'cssz', '2026-10-02');

        self::assertSame('2026-10-12', $deadline['due_on']);
    }

    /** § 42: potvrzení o době důchodového pojištění do 8 dnů od obdržení žádosti. */
    public function testInsurancePeriodConfirmationIsDueEightDaysAfterTheRequest(): void
    {
        $deadline = $this->policy->forRequest('insurance_period_confirmation', 'former_employee', '2026-09-01', 2025);

        self::assertSame('2026-09-09', $deadline['due_on']);
        self::assertStringContainsString('§ 42', $deadline['legal_basis']);
    }

    /** § 37 odst. 2: potvrzení o náhradách za ztrátu na výdělku do 30 kalendářních dnů. */
    public function testCompensationConfirmationIsDueThirtyDaysAfterDelivery(): void
    {
        $deadline = $this->policy->forRequest('compensation_confirmation', 'employee', '2026-09-01');

        self::assertSame('2026-10-01', $deadline['due_on']);
        self::assertStringContainsString('§ 37 odst. 2', $deadline['legal_basis']);
    }

    /** Čl. V body 2 až 4 zák. č. 360/2025 Sb.: potvrzení podle starého znění do 30 dnů. */
    public function testLegacyConfirmationsAreDueThirtyDaysAfterTheRequest(): void
    {
        foreach (['excluded_periods', 'deep_mining', 'risky_work', 'rescuer'] as $legacy) {
            $deadline = $this->policy->forRequest('legacy_confirmation', 'employee', '2026-09-01', 2024, legacyKind: $legacy);

            self::assertSame('2026-10-01', $deadline['due_on'], $legacy);
            self::assertStringContainsString('360/2025', $deadline['legal_basis'], $legacy);
        }
    }

    /** Potvrzení podle starého znění se vydává jen za období před 1. 1. 2026. */
    public function testLegacyConfirmationForTwentyTwentySixIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy->forRequest('legacy_confirmation', 'employee', '2026-09-01', 2026, legacyKind: 'risky_work');
    }

    /** Evidenční list na výzvu: lhůtu počítá jediné místo, EldpDeadlinePolicy. */
    public function testEldpRequestUsesTheEldpDeadlinePolicy(): void
    {
        $eldp = new EldpDeadlinePolicy();

        $transition = $this->policy->forRequest('eldp', 'cssz', '2026-08-20', 2026);
        $old = $this->policy->forRequest('eldp', 'ossz', '2026-08-20', 2025);
        $stated = $this->policy->forRequest('eldp', 'cssz', '2027-03-01', 2027, '2027-03-31');

        self::assertSame($eldp->forAuthorityRequest('2026-08-20', 2026)->dueOn, $transition['due_on']);
        self::assertSame('2026-08-28', $transition['due_on']);
        self::assertSame(EldpDeadlinePolicy::AUTHORITY_REQUEST_PRE_2026_RULESET, $old['rule']);
        self::assertSame('2027-03-31', $stated['due_on']);
    }

    /** Za rok od 2027 lhůtu listu na výzvu určuje výzva; bez ní se nevymýšlí. */
    public function testEldpRequestFromTwentyTwentySevenNeedsTheStatedDueDate(): void
    {
        $this->expectException(EldpValidationException::class);
        $this->policy->forRequest('eldp', 'cssz', '2027-03-01', 2027);
    }

    /** List po úmrtí oznámeném pozůstalým: do 3 měsíců od úmrtí. */
    public function testSurvivorNoticeUsesTheDeathDeadline(): void
    {
        $deadline = $this->policy->forRequest('eldp', 'survivor', '2025-06-01', 2025, deathOn: '2025-05-20');

        self::assertSame('2025-08-20', $deadline['due_on']);
        self::assertSame(EldpDeadlinePolicy::DEATH_RULESET, $deadline['rule']);
    }

    /** Žadatel musí k druhu patřit: o opravu hlášení žádá jen úřad. */
    public function testRequesterMustMatchTheKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy->forRequest('jmh_correction', 'employee', '2026-10-05');
    }

    /** Lhůtu z výzvy lze převzít jen u evidenčního listu; jinde ji určuje zákon. */
    public function testStatedDueDateIsRefusedOutsideTheEldp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy->forRequest('insurance_period_confirmation', 'employee', '2026-10-05', 2025, '2026-12-31');
    }
}
