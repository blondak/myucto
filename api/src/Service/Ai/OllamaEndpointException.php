<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

/** Nese strojový kód (`ollama_*`); hlášku pro uživatele skládá {@see \MyInvoice\Service\Import\OllamaClient::message()}. */
final class OllamaEndpointException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
