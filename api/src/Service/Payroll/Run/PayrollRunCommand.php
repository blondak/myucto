<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

enum PayrollRunCommand: string
{
    case LOCK_INPUTS = 'lock_inputs';
    case CALCULATE = 'calculate';
    case REVIEW = 'review';
    case APPROVE = 'approve';
    case POST = 'post';
    case PREPARE_PAYMENTS = 'prepare_payments';
    case MARK_PAID = 'mark_paid';
    case CLOSE = 'close';
    case REQUEST_CORRECTION = 'request_correction';
    case REOPEN = 'reopen';
    case CANCEL = 'cancel';
}
