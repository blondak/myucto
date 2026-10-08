<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Myucto;

final class MyuctoImportException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly array $context = [], int $status = 422)
    {
        parent::__construct($message, $status);
    }
}
