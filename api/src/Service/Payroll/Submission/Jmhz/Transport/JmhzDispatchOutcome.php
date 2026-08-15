<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

/**
 * Výsledek jednoho kroku odesílací cesty. Nese vždy stav pokusu z ledgeru,
 * takže volající nemusí sahat do databáze podruhé, a `report` jen tehdy,
 * když ČSSZ opravdu vrátila protokol o zpracování.
 *
 * Potvrzení převzetí a protokol jsou schválně dvě různá pole: splynout je do
 * jednoho „je to hotové" je přesně ta chyba, kvůli které se podání hlásí jako
 * přijaté ve chvíli, kdy se teprve kontroluje.
 */
final readonly class JmhzDispatchOutcome
{
    /** @param array<string,mixed> $attempt */
    public function __construct(
        public array $attempt,
        public ?JmhzVrepAcknowledgement $acknowledgement = null,
        public ?JmhzProtocolReport $report = null,
    ) {}

    public function isSettled(): bool
    {
        return $this->report !== null;
    }
}
