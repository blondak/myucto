<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Posting;

final readonly class PayrollPostingPreview
{
    /**
     * @param list<array{
     *   allocation_key:string,
     *   account_code:string,
     *   signed_minor:int,
     *   description:string
     * }> $targetAllocations
     * @param list<array{
     *   account_code:string,
     *   side:'debit'|'credit',
     *   amount_minor:int,
     *   description:string,
     *   cost_center?:string
     * }> $lines
     */
    public function __construct(
        public array $targetAllocations,
        public array $lines,
        public string $targetHash,
        public string $deltaHash,
        public int $debitTotalMinor,
        public int $creditTotalMinor,
    ) {
        foreach ([$targetHash, $deltaHash] as $hash) {
            if (preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) {
                throw new \InvalidArgumentException(
                    'Účetní preview musí mít kanonické SHA-256 otisky.',
                );
            }
        }
        if ($debitTotalMinor < 0
            || $creditTotalMinor < 0
            || $debitTotalMinor !== $creditTotalMinor
        ) {
            throw new \InvalidArgumentException(
                'Účetní preview musí být vyrovnané v haléřích.',
            );
        }
    }
}
