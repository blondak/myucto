<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Garnishment;

use DomainException;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseCommand;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseLifecycle;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseStatus;
use MyInvoice\Service\Payroll\Garnishment\EnforcementTransitionContext;
use PHPUnit\Framework\TestCase;

/**
 * Ukončení případu u plátce po skončení poměru (§ 295 odst. 2 o. s. ř.)
 * a vydání depozita insolvenčnímu správci. Dřív šlo případ ukončit jen
 * příkazem `stop`, který vyžaduje soudní rozhodnutí o zastavení exekuce.
 */
final class EnforcementCaseExitLifecycleTest extends TestCase
{
    public function testSettledExitEndsTheCaseAtThisPayerWithoutCourtDecision(): void
    {
        $status = (new EnforcementCaseLifecycle())->transition(
            EnforcementCaseStatus::Remit,
            EnforcementCaseCommand::EndAtPayer,
            new EnforcementTransitionContext(true, true, 250_000, false, null, 0, true),
        );

        self::assertSame(EnforcementCaseStatus::EndedAtPayer, $status);
        self::assertTrue($status->isTerminal());
    }

    public function testUnsettledExitCannotEndTheCase(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('oznámení');
        (new EnforcementCaseLifecycle())->transition(
            EnforcementCaseStatus::WithholdAndHold,
            EnforcementCaseCommand::EndAtPayer,
            new EnforcementTransitionContext(true, true, 250_000, false, null, 0, false),
        );
    }

    public function testDepositMustBeDisposedBeforeEndingTheCase(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('depozitu');
        (new EnforcementCaseLifecycle())->transition(
            EnforcementCaseStatus::WithholdAndHold,
            EnforcementCaseCommand::EndAtPayer,
            new EnforcementTransitionContext(true, true, 250_000, false, null, 10_000, true),
        );
    }

    public function testDepositHandoverDefersTheCaseAndNeedsDecisionAndReason(): void
    {
        $lifecycle = new EnforcementCaseLifecycle();
        self::assertSame(
            EnforcementCaseStatus::DeferredNoWithholding,
            $lifecycle->transition(
                EnforcementCaseStatus::Remit,
                EnforcementCaseCommand::ReleaseToAdministrator,
                new EnforcementTransitionContext(true, true, 250_000, true, 'Schválené oddlužení', 38_120),
            ),
        );

        $this->expectException(DomainException::class);
        $lifecycle->transition(
            EnforcementCaseStatus::Remit,
            EnforcementCaseCommand::ReleaseToAdministrator,
            new EnforcementTransitionContext(true, true, 250_000, false, null, 38_120),
        );
    }

    public function testHandoverWithoutDepositIsRejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('žádné depozitum');
        (new EnforcementCaseLifecycle())->transition(
            EnforcementCaseStatus::WithholdAndHold,
            EnforcementCaseCommand::ReleaseToAdministrator,
            new EnforcementTransitionContext(true, true, 250_000, true, 'Schválené oddlužení', 0),
        );
    }
}
