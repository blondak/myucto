<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets\Strategy;

use MyInvoice\Service\Accounting\Assets\DepreciationContext;

/**
 * Daňové odpisy DNM = účetní odpisy (§24 odst. 2 písm. v) ZDP, od 2021; Epic F3,
 * spec §2.6). Zrcadlo účetního plánu: daňový odpis roku = Σ účetních odpisů roku,
 * ZC sleduje účetní. Žádná odpisová skupina, žádný půlodpis §26/7, žádné §30e,
 * přerušení se nepoužije.
 */
final class TaxByAccountingStrategy implements TaxDepreciationStrategyInterface
{
    public function __construct(
        private readonly AccountingStraightLineStrategy $accounting = new AccountingStraightLineStrategy(),
    ) {}

    public function plan(DepreciationContext $ctx): array
    {
        $confirmed = [];
        foreach ($ctx->confirmedEntries as $entry) {
            if (($entry['kind'] ?? '') === 'tax') {
                $confirmed[(int) $entry['fiscal_year']] = $entry;
            }
        }

        $rows = [];
        foreach ($this->accounting->plan($ctx) as $acc) {
            $entry = $confirmed[$acc['fiscal_year']] ?? null;
            $rows[] = $this->mirror($acc, $entry);
        }

        return $rows;
    }

    public function yearRow(DepreciationContext $ctx, int $fiscalYear): ?array
    {
        $acc = $this->accounting->yearRow($ctx, $fiscalYear);

        return $acc !== null ? $this->mirror($acc, null) : null;
    }

    /**
     * @param array<string,mixed> $acc
     * @param array<string,mixed>|null $entry
     * @return array<string,mixed>
     */
    private function mirror(array $acc, ?array $entry): array
    {
        $amount = $entry !== null ? (float) $entry['amount'] : (float) $acc['amount'];
        $full = $entry !== null ? (float) $entry['full_amount'] : (float) $acc['full_amount'];

        return [
            'fiscal_year' => $acc['fiscal_year'],
            'amount' => $amount,
            'full_amount' => $full,
            'residual_start' => $acc['residual_start'],
            'residual_end' => $acc['residual_end'],
            'is_half' => false,
            'is_paused' => false,
            'months_count' => $acc['months_count'],
            'months' => null,
            'source' => $entry !== null ? 'confirmed' : 'computed',
            'note' => null,
        ];
    }
}
