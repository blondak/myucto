<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

final class AiDpaException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode = 'dpa_not_confirmed')
    {
        parent::__construct($errorCode);
    }
}
