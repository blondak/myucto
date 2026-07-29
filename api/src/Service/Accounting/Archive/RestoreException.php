<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Archive;

/**
 * Obnovu archivu firmy nelze provést (poškozený/nevalidní archiv, chybějící řádek
 * supplier, nenamapovatelný povinný FK, porušená podvojnost po importu…). Nese
 * strojový kód + volitelnou příčinu; import je vždy atomický (rollback při chybě).
 */
final class RestoreException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
