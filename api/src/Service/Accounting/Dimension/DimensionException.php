<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

/** Chyba práce s dimenzemi se strojovým kódem a HTTP statusem (vzor ReportException). */
final class DimensionException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
