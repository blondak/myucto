<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets;

/**
 * Operace nad majetkovou kartou nelze provést (validační matice §3.3, zámky R13,
 * lifecycle mimo povolený stav…). Nese strojový kód + HTTP status, aby ji navazující
 * Action přeložila na Json::error bez ztráty kontextu (stejný vzor jako
 * Reports\ReportException a Accounting\PostingException).
 */
final class AssetException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
