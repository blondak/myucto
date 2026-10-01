<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/**
 * Zápis do měsíce, za který je mzda pracovního vztahu už uzavřená
 * ({@see PayrollClosedRunGuard}). Potomek `DomainException`, takže ho volající,
 * kteří chytají konflikt stavu, chytí dál; vlastní kód nese navíc, aby se
 * v odpovědi nezměnil na obecné „konflikt stavu".
 */
final class PayrollRunClosedException extends \DomainException
{
    public const ERROR_CODE = 'payroll_run_closed';

    public readonly string $errorCode;

    /** @param array{run_id:int,status:string,period:string} $run */
    public function __construct(public readonly array $run)
    {
        $this->errorCode = self::ERROR_CODE;
        parent::__construct(sprintf(
            'Mzdy za %s jsou uzavřené (mzdový běh je %s). Podklady za tento měsíc se už '
            . 'nemění; opravu vyžádejte u mzdového běhu tlačítkem „Vyžádat opravu".',
            PayrollClosedRunGuard::periodLabel($run['period']),
            PayrollClosedRunGuard::statusLabel($run['status']),
        ));
    }
}
