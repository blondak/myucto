<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets\Strategy;

use MyInvoice\Service\Accounting\Assets\DepreciationContext;

/**
 * Účetní odpisy = daňové odpisy (`acc_method='by_tax'`). Zrcadlo
 * {@see TaxByAccountingStrategy}: tam je daňový plán kopií účetního, tady účetní
 * plán kopií daňového. Žádná doba použitelnosti, žádné měsíce — daňový odpis je
 * roční sazba z VC (§31/§32), měsíc zařazení do ní nevstupuje.
 *
 * PROČ to existuje: §28 ZoÚ velí, aby účetní odpisy vyjadřovaly skutečné opotřebení,
 * a rovnoměrný měsíční odpis je proto default. U malých účetních jednotek je ale
 * běžná a přípustná zjednodušující politika „účetní = daňový" — jednotka obětuje
 * vypovídací schopnost výkazů za to, že vede jedinou evidenci odpisů místo dvou.
 * Není to default, je to vědomá volba účetní jednotky.
 *
 * Zrcadlí se `full_amount`, ne `amount`: §30e (M1 > 2 mil.) krátí jen DAŇOVĚ
 * uznatelnou část odpisu, na výši účetního nákladu nemá vliv. Účetní odpis proto
 * kopíruje nekrácený odpis a rozdíl zůstane úpravou základu daně.
 */
final class AccountingByTaxStrategy implements AccountingDepreciationStrategyInterface
{
    public function __construct(
        private readonly ?TaxDepreciationStrategyInterface $tax,
    ) {}

    public function plan(DepreciationContext $ctx): array
    {
        $confirmed = [];
        foreach ($ctx->confirmedEntries as $entry) {
            if (($entry['kind'] ?? '') === 'accounting') {
                $confirmed[(int) $entry['fiscal_year']] = $entry;
            }
        }

        $rows = [];
        foreach ($this->tax?->plan($ctx) ?? [] as $tax) {
            $entry = $confirmed[$tax['fiscal_year']] ?? null;
            $rows[] = $this->mirror($tax, $entry);
        }

        return $rows;
    }

    public function yearRow(DepreciationContext $ctx, int $fiscalYear): ?array
    {
        $tax = $this->tax?->yearRow($ctx, $fiscalYear);

        return $tax !== null ? $this->mirror($tax, null) : null;
    }

    /**
     * @param array<string,mixed> $tax
     * @param array<string,mixed>|null $entry
     * @return array<string,mixed>
     */
    private function mirror(array $tax, ?array $entry): array
    {
        $amount = $entry !== null ? (float) $entry['amount'] : (float) $tax['full_amount'];

        return [
            'fiscal_year' => $tax['fiscal_year'],
            'amount' => $amount,
            'full_amount' => $amount,
            'residual_start' => $tax['residual_start'],
            'residual_end' => $tax['residual_end'],
            'is_half' => false,
            'is_paused' => false,
            'months_count' => null,
            'months' => null,
            'source' => $entry !== null ? 'confirmed' : 'computed',
            'note' => null,
        ];
    }
}
