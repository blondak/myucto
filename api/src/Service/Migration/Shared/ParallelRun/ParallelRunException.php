<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared\ParallelRun;

/**
 * Chyba kontroly souběhu se strojovým kódem (nečitelný soubor, neznámý zdroj, měsíc
 * mimo účetní období). HTTP status nese kód výjimky.
 */
final class ParallelRunException extends \RuntimeException
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
