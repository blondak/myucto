<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

enum JmhzScenarioSelectionKind: string
{
    case ActivityRaw = 'activity_raw';
    case ManualRaw = 'manual_raw';
}
