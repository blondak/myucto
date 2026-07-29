<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Zaúčtování nelze provést (uzavřené období, chybějící účet v osnově, nepostovatelný
 * doklad…). Nese strojový kód + HTTP status, aby ho navazující Action přeložil na
 * Json::error bez ztráty kontextu (stejný vzor jako Document\DocumentException).
 */
final class PostingException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
