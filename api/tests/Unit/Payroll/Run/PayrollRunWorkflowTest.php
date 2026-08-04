<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollRunCommand;
use MyInvoice\Service\Payroll\Run\PayrollRunStatus;
use MyInvoice\Service\Payroll\Run\PayrollRunTransitionContext;
use MyInvoice\Service\Payroll\Run\PayrollRunWorkflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollRunWorkflowTest extends TestCase
{
    private PayrollRunWorkflow $workflow;

    protected function setUp(): void
    {
        $this->workflow = new PayrollRunWorkflow();
    }

    /** @return iterable<string,array{PayrollRunStatus,PayrollRunCommand,PayrollRunStatus}> */
    public static function validTransitions(): iterable
    {
        yield 'lock inputs' => [
            PayrollRunStatus::DRAFT,
            PayrollRunCommand::LOCK_INPUTS,
            PayrollRunStatus::INPUTS_LOCKED,
        ];
        yield 'calculate' => [
            PayrollRunStatus::INPUTS_LOCKED,
            PayrollRunCommand::CALCULATE,
            PayrollRunStatus::CALCULATED,
        ];
        yield 'review' => [
            PayrollRunStatus::CALCULATED,
            PayrollRunCommand::REVIEW,
            PayrollRunStatus::REVIEWED,
        ];
        yield 'approve' => [
            PayrollRunStatus::REVIEWED,
            PayrollRunCommand::APPROVE,
            PayrollRunStatus::APPROVED,
        ];
        yield 'post' => [
            PayrollRunStatus::APPROVED,
            PayrollRunCommand::POST,
            PayrollRunStatus::POSTED,
        ];
        yield 'payments' => [
            PayrollRunStatus::POSTED,
            PayrollRunCommand::PREPARE_PAYMENTS,
            PayrollRunStatus::PAYMENT_READY,
        ];
        yield 'paid' => [
            PayrollRunStatus::PAYMENT_READY,
            PayrollRunCommand::MARK_PAID,
            PayrollRunStatus::PAID,
        ];
        yield 'close' => [
            PayrollRunStatus::PAID,
            PayrollRunCommand::CLOSE,
            PayrollRunStatus::CLOSED,
        ];
        yield 'correction' => [
            PayrollRunStatus::CLOSED,
            PayrollRunCommand::REQUEST_CORRECTION,
            PayrollRunStatus::CORRECTION_PENDING,
        ];
        yield 'reopen' => [
            PayrollRunStatus::CORRECTION_PENDING,
            PayrollRunCommand::REOPEN,
            PayrollRunStatus::REOPENED,
        ];
    }

    #[DataProvider('validTransitions')]
    public function testValidTransitions(
        PayrollRunStatus $from,
        PayrollRunCommand $command,
        PayrollRunStatus $to,
    ): void {
        $transition = $this->workflow->transition(
            $from,
            $command,
            $this->context(reason: 'Syntetický důvod'),
        );

        self::assertSame($from, $transition->from);
        self::assertSame($to, $transition->to);
        self::assertSame($command, $transition->command);
    }

    public function testTransitionMatrixRejectsSkippedApproval(): void
    {
        $this->expectException(\DomainException::class);
        $this->workflow->transition(
            PayrollRunStatus::CALCULATED,
            PayrollRunCommand::APPROVE,
            $this->context(),
        );
    }

    public function testFourEyesRejectsCalculatorAsReviewerOrApprover(): void
    {
        foreach ([PayrollRunCommand::REVIEW, PayrollRunCommand::APPROVE] as $command) {
            try {
                $this->workflow->transition(
                    $command === PayrollRunCommand::REVIEW
                        ? PayrollRunStatus::CALCULATED
                        : PayrollRunStatus::REVIEWED,
                    $command,
                    $this->context(actorUserId: 10, calculatedBy: 10),
                );
                self::fail($command->value);
            } catch (\DomainException $e) {
                self::assertStringContainsString('jiný uživatel', $e->getMessage());
            }
        }
    }

    public function testApprovalRejectsBlockersAndUnresolvedOverrides(): void
    {
        foreach ([
            $this->context(blockerCount: 1),
            $this->context(unresolvedOverrideCount: 1),
        ] as $context) {
            try {
                $this->workflow->transition(
                    PayrollRunStatus::REVIEWED,
                    PayrollRunCommand::APPROVE,
                    $context,
                );
                self::fail('Schválení nesmí obejít validace.');
            } catch (\DomainException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testImmutableArtifactsAreRequiredAtTheirBoundaries(): void
    {
        foreach ([
            [PayrollRunStatus::DRAFT, PayrollRunCommand::LOCK_INPUTS, $this->context(hasSnapshot: false)],
            [PayrollRunStatus::CALCULATED, PayrollRunCommand::REVIEW, $this->context(hasResult: false)],
            [PayrollRunStatus::APPROVED, PayrollRunCommand::POST, $this->context(hasPostingBatch: false)],
            [PayrollRunStatus::PAYMENT_READY, PayrollRunCommand::MARK_PAID, $this->context(hasPaymentBatch: false)],
        ] as [$from, $command, $context]) {
            try {
                $this->workflow->transition($from, $command, $context);
                self::fail($command->value);
            } catch (\DomainException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    private function context(
        int $actorUserId = 20,
        ?int $calculatedBy = 10,
        ?int $reviewedBy = 20,
        int $blockerCount = 0,
        int $unresolvedOverrideCount = 0,
        bool $hasSnapshot = true,
        bool $hasResult = true,
        bool $hasPostingBatch = true,
        bool $hasPaymentBatch = true,
        ?string $reason = null,
    ): PayrollRunTransitionContext {
        return new PayrollRunTransitionContext(
            actorUserId: $actorUserId,
            calculatedBy: $calculatedBy,
            reviewedBy: $reviewedBy,
            blockerCount: $blockerCount,
            unresolvedOverrideCount: $unresolvedOverrideCount,
            hasImmutableSnapshot: $hasSnapshot,
            hasCalculatedResult: $hasResult,
            hasPostingBatch: $hasPostingBatch,
            hasPaymentBatch: $hasPaymentBatch,
            reason: $reason,
        );
    }
}
