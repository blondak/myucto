<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

use DomainException;

/**
 * Zamítnutý stavový přechod rulesetu. `$code` je strojový důvod, aby UI mohlo
 * u zablokované akce ukázat konkrétní překážku, ne jen obecné „nelze".
 */
final class PayrollRulesetGovernanceException extends DomainException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        /** @var array<string, mixed> */
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
