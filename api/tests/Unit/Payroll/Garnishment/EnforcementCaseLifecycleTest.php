<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Garnishment;

use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseCommand;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseLifecycle;
use MyInvoice\Service\Payroll\Garnishment\EnforcementCaseStatus;
use MyInvoice\Service\Payroll\Garnishment\EnforcementTransitionContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnforcementCaseLifecycleTest extends TestCase
{
    /** @return iterable<string, array{EnforcementCaseStatus,EnforcementCaseCommand,EnforcementCaseStatus}> */
    public static function supportedTransitions(): iterable
    {
        yield 'verified order starts in escrow' => [
            EnforcementCaseStatus::Received,
            EnforcementCaseCommand::MarkFinal,
            EnforcementCaseStatus::WithholdAndHold,
        ];
        yield 'remittance instruction releases escrow' => [
            EnforcementCaseStatus::WithholdAndHold,
            EnforcementCaseCommand::AuthorizeRemittance,
            EnforcementCaseStatus::Remit,
        ];
        yield 'defer but keep withholding' => [
            EnforcementCaseStatus::Remit,
            EnforcementCaseCommand::DeferHold,
            EnforcementCaseStatus::DeferredHold,
        ];
        yield 'defer without withholding' => [
            EnforcementCaseStatus::WithholdAndHold,
            EnforcementCaseCommand::DeferNoWithholding,
            EnforcementCaseStatus::DeferredNoWithholding,
        ];
        yield 'resume into escrow' => [
            EnforcementCaseStatus::DeferredNoWithholding,
            EnforcementCaseCommand::ResumeHolding,
            EnforcementCaseStatus::WithholdAndHold,
        ];
        yield 'resume remittance' => [
            EnforcementCaseStatus::DeferredHold,
            EnforcementCaseCommand::ResumeRemittance,
            EnforcementCaseStatus::Remit,
        ];
        yield 'settled claim becomes paid' => [
            EnforcementCaseStatus::Remit,
            EnforcementCaseCommand::MarkPaid,
            EnforcementCaseStatus::Paid,
        ];
        yield 'verified stop decision closes case' => [
            EnforcementCaseStatus::Received,
            EnforcementCaseCommand::Stop,
            EnforcementCaseStatus::Stopped,
        ];
    }

    #[DataProvider('supportedTransitions')]
    public function testAcceptsOnlyExplicitTransitions(
        EnforcementCaseStatus $from,
        EnforcementCaseCommand $command,
        EnforcementCaseStatus $expected,
    ): void {
        $context = new EnforcementTransitionContext(
            evidenceComplete: true,
            recipientVerified: true,
            outstandingMinorUnits: 0,
            decisionVerified: true,
            reason: 'Syntetický právní podklad',
        );

        self::assertSame(
            $expected,
            (new EnforcementCaseLifecycle())->transition($from, $command, $context),
        );
    }

    public function testRejectsFinalizationWithIncompleteEvidence(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('úplné a ověřené podklady');

        (new EnforcementCaseLifecycle())->transition(
            EnforcementCaseStatus::Received,
            EnforcementCaseCommand::MarkFinal,
            new EnforcementTransitionContext(false, false, 100_000, false, null),
        );
    }

    public function testActivationAndHoldingResumptionRequireDecisionDocuments(): void
    {
        self::assertTrue(EnforcementCaseCommand::MarkFinal->requiresDecisionDocument());
        self::assertSame(
            'initial_order',
            EnforcementCaseCommand::MarkFinal->evidenceKind(),
        );
        self::assertTrue(EnforcementCaseCommand::ResumeHolding->requiresDecisionDocument());
        self::assertSame(
            'resumption',
            EnforcementCaseCommand::ResumeHolding->evidenceKind(),
        );
    }

    public function testRejectsRemittanceWithoutVerifiedRecipient(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ověřeného příjemce');

        (new EnforcementCaseLifecycle())->transition(
            EnforcementCaseStatus::WithholdAndHold,
            EnforcementCaseCommand::AuthorizeRemittance,
            new EnforcementTransitionContext(true, false, 100_000, true, 'Syntetická instrukce'),
        );
    }

    public function testRejectsRemittanceAfterEvidenceWasRevoked(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('úplné a ověřené podklady');

        (new EnforcementCaseLifecycle())->transition(
            EnforcementCaseStatus::WithholdAndHold,
            EnforcementCaseCommand::AuthorizeRemittance,
            new EnforcementTransitionContext(false, true, 100_000, true, null),
        );
    }

    public function testRejectsRemittanceWithoutVerifiedDecisionDocument(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ověřené rozhodnutí');

        (new EnforcementCaseLifecycle())->transition(
            EnforcementCaseStatus::WithholdAndHold,
            EnforcementCaseCommand::AuthorizeRemittance,
            new EnforcementTransitionContext(
                true,
                true,
                100_000,
                false,
                'Syntetická instrukce',
            ),
        );
    }

    public function testRejectsPaidWhileAnyBalanceRemains(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nenulovým zůstatkem');

        (new EnforcementCaseLifecycle())->transition(
            EnforcementCaseStatus::Remit,
            EnforcementCaseCommand::MarkPaid,
            new EnforcementTransitionContext(true, true, 1, true, 'Syntetické vyrovnání'),
        );
    }

    public function testRejectsProcessCommandWithoutVerifiedDecisionAndReason(): void
    {
        $lifecycle = new EnforcementCaseLifecycle();
        foreach ([
            EnforcementCaseCommand::DeferHold,
            EnforcementCaseCommand::DeferNoWithholding,
            EnforcementCaseCommand::Stop,
        ] as $command) {
            try {
                $lifecycle->transition(
                    EnforcementCaseStatus::Remit,
                    $command,
                    new EnforcementTransitionContext(true, true, 100_000, false, null),
                );
                self::fail("Příkaz {$command->value} měl být odmítnut.");
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTerminalStateCannotBeReopenedByLifecycleShortcut(): void
    {
        $this->expectException(\DomainException::class);

        (new EnforcementCaseLifecycle())->transition(
            EnforcementCaseStatus::Paid,
            EnforcementCaseCommand::ResumeRemittance,
            new EnforcementTransitionContext(true, true, 0, true, 'Syntetický pokus'),
        );
    }
}
