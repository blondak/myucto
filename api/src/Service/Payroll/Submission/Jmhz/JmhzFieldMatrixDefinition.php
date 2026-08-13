<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzFieldMatrixDefinition
{
    public function __construct(
        public string $key,
        public JmhzMatrixKind $kind,
        public string $sourceSheet,
        public int $rowCount,
    ) {}
}
