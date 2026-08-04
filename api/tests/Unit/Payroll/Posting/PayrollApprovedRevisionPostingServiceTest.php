<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Posting;

use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\PayrollAccountingDefaults;
use MyInvoice\Service\Payroll\Posting\PayrollApprovedRevisionPostingService;
use MyInvoice\Service\Payroll\Posting\PayrollPostingAdapter;
use MyInvoice\Service\Payroll\Posting\PayrollPostingPreview;
use PHPUnit\Framework\TestCase;

final class PayrollApprovedRevisionPostingServiceTest extends TestCase
{
    public function testUsesAccountsFrozenInApprovedSnapshot(): void
    {
        $statutory = $this->createStub(PayrollStatutoryResultRepository::class);
        $posting = $this->createMock(PayrollPostingAdapter::class);
        $accountingModes = $this->createStub(AccountingModeRepository::class);
        $accountingModes->method('forYear')->willReturn('double_entry');
        $statutory->method('find')->willReturn([
            'id' => 1,
            'result_status' => 'calculated',
        ]);
        $frozenAccounts = PayrollAccountingDefaults::codes();
        $snapshot = [
            'schema_version' => 'payroll-run-input.v2',
            'period_start' => '2026-06-01',
            'employer' => ['accounting_accounts' => $frozenAccounts],
        ];
        $posting->expects(self::once())
            ->method('post')
            ->with(
                10,
                77,
                $snapshot,
                [],
                self::anything(),
                $frozenAccounts,
                ['user_id' => 9],
            )
            ->willReturn([
                'batch_id' => 8,
                'journal_entry_id' => null,
                'status' => 'no_change',
                'idempotent' => false,
                'preview' => new PayrollPostingPreview(
                    [],
                    [],
                    hash('sha256', 'target'),
                    hash('sha256', 'delta'),
                    0,
                    0,
                ),
            ]);

        (new PayrollApprovedRevisionPostingService(
            $statutory,
            $posting,
            $accountingModes,
        ))->post(10, 77, $snapshot, [], 9);
    }

    public function testPostsApprovedRevisionFromImmutableResultsAndTenantAccounts(): void
    {
        $statutory = $this->createMock(PayrollStatutoryResultRepository::class);
        $posting = $this->createMock(PayrollPostingAdapter::class);
        $accountingModes = $this->createStub(AccountingModeRepository::class);
        $accountingModes->method('forYear')->willReturn('double_entry');
        $accounts = PayrollAccountingDefaults::codes();
        $snapshot = [
            'schema_version' => 'payroll-run-input.v2',
            'period_start' => '2026-06-01',
            'employer' => ['accounting_accounts' => $accounts],
        ];
        $result = ['schema_version' => 'payroll-run-result.v2'];
        $sets = [];
        foreach ([
            'social_insurance',
            'health_insurance',
            'income_tax',
            'net_pay',
        ] as $index => $kind) {
            $sets[$kind] = [
                'id' => $index + 1,
                'result_status' => 'calculated',
            ];
        }
        $statutory->expects(self::exactly(4))
            ->method('find')
            ->willReturnCallback(
                static fn (int $supplierId, int $revisionId, string $kind): array =>
                    $sets[$kind],
            );
        $preview = new PayrollPostingPreview(
            [],
            [],
            hash('sha256', 'target'),
            hash('sha256', 'delta'),
            0,
            0,
        );
        $expected = [
            'batch_id' => 8,
            'journal_entry_id' => null,
            'status' => 'no_change',
            'idempotent' => false,
            'preview' => $preview,
        ];
        $posting->expects(self::once())
            ->method('post')
            ->with(
                10,
                77,
                $snapshot,
                $result,
                $sets,
                $accounts,
                ['user_id' => 9],
            )
            ->willReturn($expected);

        self::assertSame(
            $expected,
            (new PayrollApprovedRevisionPostingService(
                $statutory,
                $posting,
                $accountingModes,
            ))->post(10, 77, $snapshot, $result, 9),
        );
    }

    public function testMissingStatutorySetFailsBeforePosting(): void
    {
        $statutory = $this->createStub(PayrollStatutoryResultRepository::class);
        $posting = $this->createMock(PayrollPostingAdapter::class);
        $accountingModes = $this->createStub(AccountingModeRepository::class);
        $accountingModes->method('forYear')->willReturn('double_entry');
        $statutory->method('find')->willReturn(null);
        $posting->expects(self::never())->method('post');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('social_insurance');
        (new PayrollApprovedRevisionPostingService(
            $statutory,
            $posting,
            $accountingModes,
        ))->post(
            10,
            77,
            [
                'schema_version' => 'payroll-run-input.v2',
                'period_start' => '2026-06-01',
            ],
            ['schema_version' => 'payroll-run-result.v2'],
            9,
        );
    }

    public function testTaxEvidenceSkipsAccountingWithoutLoadingStatutoryResults(): void
    {
        $statutory = $this->createMock(PayrollStatutoryResultRepository::class);
        $posting = $this->createMock(PayrollPostingAdapter::class);
        $accountingModes = $this->createStub(AccountingModeRepository::class);
        $accountingModes->method('forYear')->willReturn('tax_evidence');
        $statutory->expects(self::never())->method('find');
        $posting->expects(self::never())->method('post');

        self::assertNull((new PayrollApprovedRevisionPostingService(
            $statutory,
            $posting,
            $accountingModes,
        ))->post(
            10,
            77,
            [
                'schema_version' => 'payroll-run-input.v2',
                'period_start' => '2026-06-01',
            ],
            ['schema_version' => 'payroll-run-result.v2'],
            9,
        ));
    }
}
