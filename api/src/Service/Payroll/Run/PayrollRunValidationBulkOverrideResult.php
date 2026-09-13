<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Výsledek hromadného schválení výjimky u skupiny mzdových validací.
 */
final readonly class PayrollRunValidationBulkOverrideResult
{
    /**
     * @param array<string,mixed> $run
     * @param list<array<string,mixed>> $validations validace, u kterých tenhle
     *        příkaz výjimku schválil
     * @param int $skippedCount validace skupiny, které už schválené byly
     */
    public function __construct(
        public array $run,
        public array $validations,
        public int $grantedCount,
        public int $skippedCount,
        public bool $fourEyesMet,
        public bool $idempotentReplay,
    ) {}
}
