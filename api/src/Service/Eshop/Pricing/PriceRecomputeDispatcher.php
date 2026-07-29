<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Repository\StockItemPriceRepository;

/**
 * Spouštění přepočtu prodejních cen (Epic ESHOP, §3.3).
 *
 * On-demand při uložení karty/ceny; dávkově po příjemce (změna weighted_avg) a
 * po denním FX importu (markup v cizí měně). Trigger žije v aplikaci, ne v DB.
 * Tenká vrstva nad PriceCalculationService — jediné místo, kam se hooky napojí.
 */
final class PriceRecomputeDispatcher
{
    public function __construct(
        private readonly PriceCalculationService $calc,
        private readonly StockItemPriceRepository $prices,
    ) {}

    /** Přepočte jednu kartu. @return list<array<string,mixed>> */
    public function recomputeItem(int $supplierId, int $stockItemId): array
    {
        return $this->calc->recompute($supplierId, $stockItemId);
    }

    /**
     * Přepočte zadané karty (po příjemce — změna vážené nákupní ceny).
     * @param list<int> $itemIds
     */
    public function recomputeItems(int $supplierId, array $itemIds): void
    {
        foreach (array_unique(array_map('intval', $itemIds)) as $id) {
            if ($id > 0) {
                $this->calc->recompute($supplierId, $id);
            }
        }
    }

    /**
     * Přepočte všechny karty firmy s cenovými řádky (po denním FX importu —
     * markup v cizí měně). Vrací počet zpracovaných karet.
     */
    public function recomputeAllForSupplier(int $supplierId): int
    {
        $ids = $this->prices->itemIdsWithPrices($supplierId);
        foreach ($ids as $id) {
            $this->calc->recompute($supplierId, $id);
        }
        return count($ids);
    }
}
