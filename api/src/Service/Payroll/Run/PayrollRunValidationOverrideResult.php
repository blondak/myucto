<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Výsledek schválení nebo odvolání výjimky u mzdové validace.
 *
 * @phpstan-type RunRow array<string,mixed>
 * @phpstan-type ValidationRow array<string,mixed>
 */
final readonly class PayrollRunValidationOverrideResult
{
    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $validation
     * @param bool $granted true = výjimka byla schválena, false = odvolána
     * @param bool $fourEyesMet false, když výjimku odklepl tentýž člověk,
     *        který revizi počítal (politika, ne blokace — viz service)
     */
    public function __construct(
        public array $run,
        public array $validation,
        public bool $granted,
        public bool $fourEyesMet,
        public bool $idempotentReplay,
    ) {}
}
