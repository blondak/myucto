<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Účetní zápis porušuje podvojnost (Σ MD ≠ Σ D). Nese obě strany v haléřích
 * (int), aby se rozdíl nedal spočítat/porovnat přes float (viz PostingService).
 */
final class UnbalancedEntryException extends \RuntimeException
{
    public function __construct(
        public readonly int $debitCents,
        public readonly int $creditCents,
        string $message = '',
    ) {
        parent::__construct(
            $message !== '' ? $message : sprintf(
                'Účetní zápis není vyvážený: Σ MD = %.2f, Σ D = %.2f (rozdíl %.2f).',
                $debitCents / 100,
                $creditCents / 100,
                ($debitCents - $creditCents) / 100,
            )
        );
    }
}
