<?php

declare(strict_types=1);

namespace MyInvoice\Service\Intrastat;

final class IntrastatInputException extends \InvalidArgumentException
{
    /** @param list<array{severity:string,code:string,message:string}> $issues */
    public function __construct(public readonly array $issues)
    {
        parent::__construct($issues[0]['message'] ?? 'Neplatné parametry exportu Intrastat.');
    }
}
