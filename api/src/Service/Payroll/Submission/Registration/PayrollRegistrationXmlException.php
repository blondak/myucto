<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

final class PayrollRegistrationXmlException extends \RuntimeException
{
    /**
     * @param list<array{field:string,label:string,message:string,panel:?string,target?:string}> $problems
     *   Všechny chybějící údaje najednou, každý s adresou pole pro proklik.
     *   Prázdné u výjimek, které se týkají jediné věci.
     */
    public function __construct(
        public readonly string $validationCode,
        string $message,
        public readonly array $problems = [],
    ) {
        parent::__construct($message);
    }
}
