<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

/** K dávce je doložená úhrada, zahodit ani přeplánovat ji nejde. */
final class PayrollPaymentBatchSettledException extends \DomainException
{
    public function __construct()
    {
        parent::__construct(
            'K dávce je doložená úhrada (spárovaná platba z výpisu), zahodit ani přeplánovat ji proto nejde.'
            . ' Pokud byla úhrada spárovaná omylem, nejdřív ji v záložce Úhrady stornujte.',
        );
    }
}
