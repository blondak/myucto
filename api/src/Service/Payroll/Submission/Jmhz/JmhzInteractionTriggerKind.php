<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

enum JmhzInteractionTriggerKind: string
{
    case AttributeRaw = 'attribute_raw';
    case VirtualRaw = 'virtual_raw';
    case CompoundRaw = 'compound_raw';
    case MonthRaw = 'month_raw';
}
