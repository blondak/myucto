<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

/**
 * Chyba převodu z POHODY se strojovým kódem - rozhraní z něj staví hlášku, protokol ho
 * ukládá u kroku, na kterém převod skončil.
 */
final class PohodaException extends \RuntimeException
{
    /** @param array<string,mixed> $context */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
        int $httpStatus = 422,
    ) {
        parent::__construct($message, $httpStatus);
    }
}
