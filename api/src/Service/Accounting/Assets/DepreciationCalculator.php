<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets;

use MyInvoice\Service\Accounting\Assets\Strategy\AccountingByTaxStrategy;
use MyInvoice\Service\Accounting\Assets\Strategy\AccountingDepreciationStrategyInterface;
use MyInvoice\Service\Accounting\Assets\Strategy\AccountingStraightLineStrategy;
use MyInvoice\Service\Accounting\Assets\Strategy\TaxAcceleratedStrategy;
use MyInvoice\Service\Accounting\Assets\Strategy\TaxByAccountingStrategy;
use MyInvoice\Service\Accounting\Assets\Strategy\TaxDepreciationStrategyInterface;
use MyInvoice\Service\Accounting\Assets\Strategy\TaxExtraordinaryStrategy;
use MyInvoice\Service\Accounting\Assets\Strategy\TaxStraightLineStrategy;

/**
 * Fasáda výpočetního jádra odpisů (Epic F3, spec §2.2) — vybírá daňovou strategii
 * dle `tax_method` karty (`none` → prázdný daňový plán) a účetní strategii dle
 * `acc_method` (`straight_line` = rovnoměrně po měsících, `by_tax` = shodně
 * s daňovým odpisem).
 * Pozn. k rozhraní: `tax_method` ani `acc_method` nejsou součástí DepreciationContext
 * (spec §2.1), proto je metody přebírají jako explicitní parametry. `acc_method`
 * má default kvůli zpětné kompatibilitě volajících z doby před volbou metody.
 */
final class DepreciationCalculator
{
    public function __construct(
        private readonly TaxStraightLineStrategy $straightLine = new TaxStraightLineStrategy(),
        private readonly TaxAcceleratedStrategy $accelerated = new TaxAcceleratedStrategy(),
        private readonly TaxExtraordinaryStrategy $extraordinary = new TaxExtraordinaryStrategy(),
        private readonly TaxByAccountingStrategy $byAccounting = new TaxByAccountingStrategy(),
        private readonly AccountingStraightLineStrategy $accounting = new AccountingStraightLineStrategy(),
    ) {}

    /**
     * @return array{tax: list<array<string,mixed>>, accounting: list<array<string,mixed>>}
     */
    public function plan(DepreciationContext $ctx, string $taxMethod, string $accMethod = 'straight_line'): array
    {
        return [
            'tax' => $this->taxStrategy($taxMethod)?->plan($ctx) ?? [],
            'accounting' => $this->accountingStrategy($accMethod, $taxMethod)->plan($ctx),
        ];
    }

    /** @return array<string,mixed>|null */
    public function taxYearRow(DepreciationContext $ctx, string $taxMethod, int $fiscalYear): ?array
    {
        return $this->taxStrategy($taxMethod)?->yearRow($ctx, $fiscalYear);
    }

    /** @return array<string,mixed>|null */
    public function accountingYearRow(
        DepreciationContext $ctx,
        int $fiscalYear,
        string $accMethod = 'straight_line',
        string $taxMethod = 'none',
    ): ?array {
        return $this->accountingStrategy($accMethod, $taxMethod)->yearRow($ctx, $fiscalYear);
    }

    private function taxStrategy(string $taxMethod): ?TaxDepreciationStrategyInterface
    {
        return match ($taxMethod) {
            'straight' => $this->straightLine,
            'accelerated' => $this->accelerated,
            'extraordinary' => $this->extraordinary,
            'by_accounting' => $this->byAccounting,
            'none' => null,
            default => throw new \InvalidArgumentException('Neznámá metoda daňových odpisů: ' . $taxMethod),
        };
    }

    /**
     * `by_tax` deleguje na daňovou strategii karty — proto se skládá až tady, kde
     * je známý `tax_method`. `tax_method='none'` → prázdný účetní plán (validace
     * kombinaci by_tax+none nepovolí, ale výpočet ji nesmí shodit).
     */
    private function accountingStrategy(string $accMethod, string $taxMethod): AccountingDepreciationStrategyInterface
    {
        return match ($accMethod) {
            'straight_line' => $this->accounting,
            'by_tax' => new AccountingByTaxStrategy($this->taxStrategy($taxMethod)),
            default => throw new \InvalidArgumentException('Neznámá metoda účetních odpisů: ' . $accMethod),
        };
    }
}
