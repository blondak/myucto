<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

interface PayrollReceiptVerifierInterface
{
    public function verify(
        string $bytes,
        string $channel,
        string $environment,
        ?string $expectedCorrelationReference,
    ): PayrollVerifiedReceipt;
}
