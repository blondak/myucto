<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Net;

use MyInvoice\Service\Payroll\Net\PayoutAllocationRequest;
use MyInvoice\Service\Payroll\Net\PayoutAllocationService;
use PHPUnit\Framework\TestCase;

final class PayoutAllocationServiceTest extends TestCase
{
    public function testAllocatesFixedPercentageAndExactRemainder(): void
    {
        $result = (new PayoutAllocationService())->allocate(333_333, [
            PayoutAllocationRequest::fixed(
                'account-a',
                'bank',
                'synthetic-account-a',
                50_000,
                1,
            ),
            PayoutAllocationRequest::percentage(
                'account-b',
                'bank',
                'synthetic-account-b',
                2500,
                2,
            ),
            PayoutAllocationRequest::remainder('cash', 'cash', null, 3),
        ]);

        self::assertSame(333_333, $result->netPayableMinorUnits);
        self::assertSame(50_000, $result->allocations[0]->amountMinorUnits);
        self::assertSame(83_333, $result->allocations[1]->amountMinorUnits);
        self::assertSame(200_000, $result->allocations[2]->amountMinorUnits);
        self::assertSame(
            ['synthetic-account-a', 'synthetic-account-b', null],
            array_map(
                static fn ($allocation): ?string => $allocation->destinationReference,
                $result->allocations,
            ),
        );
        self::assertSame(333_333, array_sum(array_map(
            static fn ($allocation): int => $allocation->amountMinorUnits,
            $result->allocations,
        )));
    }

    public function testRejectsAllocationWithoutExactlyOneRemainderDestination(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PayoutAllocationService())->allocate(100_000, [
            PayoutAllocationRequest::fixed(
                'account-a',
                'bank',
                'synthetic-account-a',
                100_000,
                1,
            ),
        ]);
    }

    public function testRejectsFixedAndPercentageAllocationsAboveNetPay(): void
    {
        $this->expectException(\DomainException::class);
        (new PayoutAllocationService())->allocate(100_000, [
            PayoutAllocationRequest::fixed(
                'account-a',
                'bank',
                'synthetic-account-a',
                80_000,
                1,
            ),
            PayoutAllocationRequest::percentage(
                'account-b',
                'bank',
                'synthetic-account-b',
                5000,
                2,
            ),
            PayoutAllocationRequest::remainder('cash', 'cash', null, 3),
        ]);
    }

    public function testProducesStableOrderIndependentOfInputOrder(): void
    {
        $result = (new PayoutAllocationService())->allocate(10_000, [
            PayoutAllocationRequest::remainder(
                'remainder',
                'bank',
                'synthetic-remainder',
                30,
            ),
            PayoutAllocationRequest::fixed(
                'second',
                'bank',
                'synthetic-second',
                2_000,
                20,
            ),
            PayoutAllocationRequest::fixed(
                'first',
                'bank',
                'synthetic-first',
                1_000,
                10,
            ),
        ]);

        self::assertSame(
            ['first', 'second', 'remainder'],
            array_map(
                static fn ($allocation): string => $allocation->allocationReference,
                $result->allocations,
            ),
        );
        self::assertSame([1_000, 2_000, 7_000], array_map(
            static fn ($allocation): int => $allocation->amountMinorUnits,
            $result->allocations,
        ));
    }
}
