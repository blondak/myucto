<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

enum PayrollRunStatus: string
{
    case DRAFT = 'draft';
    case INPUTS_LOCKED = 'inputs_locked';
    case CALCULATED = 'calculated';
    case REVIEWED = 'reviewed';
    case APPROVED = 'approved';
    case POSTED = 'posted';
    case PAYMENT_READY = 'payment_ready';
    case PAID = 'paid';
    case CLOSED = 'closed';
    case CORRECTION_PENDING = 'correction_pending';
    case REOPENED = 'reopened';
    case CANCELLED = 'cancelled';
}
