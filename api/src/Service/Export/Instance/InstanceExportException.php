<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export\Instance;

use RuntimeException;

/**
 * Očekávaná chyba exportu (kvóta, zámek, plný disk, storno) — na rozdíl od
 * neočekávaného pádu se hlásí zákazníkovi srozumitelnou hláškou a stabilním kódem,
 * podle kterého se dá reagovat v UI.
 */
final class InstanceExportException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
    ) {
        parent::__construct($message);
    }
}
