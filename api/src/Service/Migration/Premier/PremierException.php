<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

/**
 * Chyba převodu z PREMIER se strojovým kódem a textem pro uživatele. Kód HTTP (`code`)
 * použije akce průvodce, když chybu vrací přímo v odpovědi.
 */
final class PremierException extends \RuntimeException
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
