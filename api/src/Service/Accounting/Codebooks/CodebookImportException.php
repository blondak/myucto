<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Codebooks;

/**
 * Chyba importu číselníku, kterou nelze reprezentovat jako řádkový status
 * (bad_file 415, too_many_rows 422). Action ji mapuje na Json::error se
 * strojovým kódem a HTTP statusem (§4.6).
 */
final class CodebookImportException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
