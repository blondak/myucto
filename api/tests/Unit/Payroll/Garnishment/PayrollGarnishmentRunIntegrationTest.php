<?php

declare(strict_types=1);

namespace Tests\Unit\Payroll\Garnishment;

use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthRequest;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentResult;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentStatus;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentPort;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentCalculation;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentRunIntegration;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentSnapshotWriter;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeResult;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentInput;
use MyInvoice\Service\Payroll\Garnishment\InsolvencyInstruction;
use MyInvoice\Service\Payroll\Garnishment\PensionEvidence;
use PHPUnit\Framework\TestCase;

final class PayrollGarnishmentRunIntegrationTest extends TestCase
{
    public function testCalculationIsPersistedWithRevisionAndIdempotencyKey(): void
    {
        $request = new EnforcementPersonMonthRequest(
            11,
            22,
            '2026-06',
            '2026-07-15',
            [],
            true,
        );
        $result = new GarnishmentResult(
            '2026-06',
            GarnishmentStatus::ManualReview,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            false,
            false,
            [],
            ['missing_evidence'],
            [],
            'ruleset',
            str_repeat('a', 64),
        );
        $input = new GarnishmentInput(
            '2026-06',
            '2026-07-15',
            new GarnishableIncomeResult(GarnishmentStatus::ManualReview, 0, 0, [], []),
            [],
            0,
            false,
            false,
            false,
            PensionEvidence::Unknown,
            false,
            null,
            InsolvencyInstruction::none(),
            false,
            false,
        );
        $calculation = new PayrollGarnishmentCalculation(11, 22, $input, $result);
        $port = new class($calculation) implements PayrollGarnishmentPort {
            public function __construct(
                private readonly PayrollGarnishmentCalculation $calculation,
            ) {}

            public function calculate(
                EnforcementPersonMonthRequest $request,
            ): PayrollGarnishmentCalculation
            {
                return $this->calculation;
            }
        };
        $writer = new class implements PayrollGarnishmentSnapshotWriter {
            /** @var array<string,mixed> */
            public array $call = [];

            public function store(
                EnforcementPersonMonthRequest $request,
                PayrollGarnishmentCalculation $calculation,
                ?int $revisionId,
                string $idempotencyKey,
            ): int {
                $this->call = compact(
                    'request',
                    'calculation',
                    'revisionId',
                    'idempotencyKey',
                );
                return 71;
            }
        };

        $run = (new PayrollGarnishmentRunIntegration($port, $writer))
            ->calculateAndStore($request, 33, 'run-33-employee-22');

        self::assertSame(71, $run->snapshotId);
        self::assertSame($result, $run->calculation);
        self::assertSame(33, $writer->call['revisionId']);
        self::assertSame('run-33-employee-22', $writer->call['idempotencyKey']);
    }

    public function testBlankIdempotencyKeyIsRejectedBeforeCalculation(): void
    {
        $port = $this->createMock(PayrollGarnishmentPort::class);
        $writer = $this->createMock(PayrollGarnishmentSnapshotWriter::class);
        $port->expects(self::never())->method('calculate');
        $writer->expects(self::never())->method('store');

        $this->expectException(\InvalidArgumentException::class);
        (new PayrollGarnishmentRunIntegration($port, $writer))->calculateAndStore(
            new EnforcementPersonMonthRequest(
                1,
                2,
                '2026-06',
                '2026-07-15',
                [],
                true,
            ),
            null,
            ' ',
        );
    }
}
