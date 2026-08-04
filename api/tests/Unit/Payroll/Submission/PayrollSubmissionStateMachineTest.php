<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollSubmissionStateMachineTest extends TestCase
{
    #[DataProvider('validTransitions')]
    public function testAllowsOnlyExplicitLegalTransitions(
        string $from,
        string $to,
    ): void {
        $machine = new PayrollSubmissionStateMachine();

        self::assertTrue($machine->canTransition($from, $to));
        $machine->assertTransition($from, $to);
    }

    /** @return iterable<string,array{string,string}> */
    public static function validTransitions(): iterable
    {
        yield 'prepared to submitted' => ['ready', 'submitted'];
        yield 'submitted is not accepted' => ['submitted', 'processing'];
        yield 'processing accepted' => ['processing', 'accepted'];
        yield 'processing rejected' => ['processing', 'rejected'];
        yield 'partial requires correction' => [
            'partially_accepted',
            'correction_required',
        ];
        yield 'rejected requires correction' => [
            'rejected',
            'correction_required',
        ];
        yield 'accepted can be superseded by correction' => [
            'accepted',
            'superseded',
        ];
    }

    #[DataProvider('invalidTransitions')]
    public function testRejectsShortcutsAndTerminalMutation(
        string $from,
        string $to,
    ): void {
        $machine = new PayrollSubmissionStateMachine();

        self::assertFalse($machine->canTransition($from, $to));
        $this->expectException(\DomainException::class);
        $machine->assertTransition($from, $to);
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalidTransitions(): iterable
    {
        yield 'ready is not accepted' => ['ready', 'accepted'];
        yield 'submitted is not automatically accepted' => [
            'submitted',
            'accepted',
        ];
        yield 'accepted cannot be rejected' => ['accepted', 'rejected'];
        yield 'cancelled is terminal' => ['cancelled_in_time', 'ready'];
        yield 'superseded is terminal' => ['superseded', 'submitted'];
        yield 'unknown state fails closed' => ['mystery', 'accepted'];
    }
}
