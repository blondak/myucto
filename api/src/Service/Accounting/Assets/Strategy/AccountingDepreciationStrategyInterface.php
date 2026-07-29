<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets\Strategy;

use MyInvoice\Service\Accounting\Assets\DepreciationContext;

/**
 * Strategie účetních odpisů — tvar řádků shodný s {@see TaxDepreciationStrategyInterface}
 * (plán je pak jedna tabulka se dvěma sloupci). Volbu strategie drží `assets.acc_method`.
 */
interface AccountingDepreciationStrategyInterface
{
    /**
     * Kompletní průběh účetních odpisů: minulost z confirmedEntries (kind='accounting'),
     * budoucnost dopočtená do plného odepsání (nebo do měsíce/roku vyřazení).
     * @return list<array<string,mixed>> tvar dle TaxDepreciationStrategyInterface::plan()
     */
    public function plan(DepreciationContext $ctx): array;

    /** Řádek jednoho roku (pro book/dispose) — rok se počítá vždy čerstvě. */
    public function yearRow(DepreciationContext $ctx, int $fiscalYear): ?array;
}
