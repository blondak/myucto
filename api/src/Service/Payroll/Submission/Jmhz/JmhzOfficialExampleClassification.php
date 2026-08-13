<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

enum JmhzOfficialExampleClassification: string
{
    case ValidAgainstPinnedXsd = 'valid_against_pinned_xsd';
    case DifferentVersion = 'different_version';
    case Fragment = 'fragment';
    case IntentionallyInvalid = 'intentionally_invalid';
    case Unresolved = 'unresolved';
}
