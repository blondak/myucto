<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Garnishment;

use MyInvoice\Service\Payroll\Garnishment\GarnishmentAllocation;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentResult;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentStatus;
use PHPUnit\Framework\TestCase;

final class GarnishmentResultTest extends TestCase
{
    public function testAllocationRejectsNegativePool(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GarnishmentAllocation('claim-1', -1, 0);
    }

    public function testResultRejectsInconsistentAllocationTotal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GarnishmentResult(
            '2026-06',
            GarnishmentStatus::Supported,
            10_000,
            0,
            0,
            0,
            0,
            5_000,
            5_000,
            false,
            false,
            [new GarnishmentAllocation('claim-1', 4_000, 0)],
            [],
            [],
            'synthetic-ruleset',
            str_repeat('a', 64),
        );
    }
}
