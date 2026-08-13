<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzControlId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException('ID kontroly JMHZ musí být kladné.');
        }
    }

    public function disErrorCode(): int
    {
        return 20_000 + $this->value;
    }

    public function cjmhzErrorCode(): int
    {
        return 40_000 + $this->value;
    }
}
