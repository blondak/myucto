<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Lhůta registrační povinnosti U ZAMĚSTNANCE.
 *
 * Vědomě je to jiný typ než `EmployerRegistrationDeadlineWindow`: lhůta
 * zaměstnavatele (přihláška do evidence, § 17) a lhůta zaměstnance
 * (přihláška pracovního vztahu, § 19) se počítají jinak, běží proti jinému
 * subjektu a plete si je i metodika. Jeden sdílený typ by je nechal splynout
 * a `deemedEmployerFrom` u zaměstnance nedává smysl.
 *
 * `derived = false` znamená, že lhůta podle dnešních pravidel neexistuje
 * (událost před 1. 7. 2026) — `dueOn` je pak jen den události, podání se
 * hlásí jako opožděné a `notice` říká proč.
 */
final readonly class PayrollEmployeeRegistrationDeadlineWindow
{
    public function __construct(
        public string $earliestRegistrationOn,
        public string $dueOn,
        public string $calendarBasis,
        public string $rulesetId,
        public string $rulesetHash,
        public bool $derived = true,
        public ?string $notice = null,
    ) {}
}
