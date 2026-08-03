<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use OverflowException;

final class TaxIntegerMath
{
    public static function add(int $left, int $right): int
    {
        if (
            ($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new OverflowException('Income tax monetary aggregation exceeds the integer range.');
        }

        return $left + $right;
    }
}
