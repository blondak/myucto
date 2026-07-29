<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

/**
 * Součet ručních položek §23 (`manual_increase_items`/`manual_decrease_items`, tvar
 * `{text, amount}` — viz `TaxReturnService::items()`). Sdíleno {@see DppoReturnCalculator}
 * a {@see DpfoReturnDataProvider} (Fáze E nález N1 — FO s podvojným účetnictvím reuse
 * stejnou logiku jako PO, ne kopii).
 */
final class ManualItemsSum
{
    public static function sum(mixed $items): float
    {
        if (!is_array($items)) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($items as $item) {
            if (is_array($item)) {
                $sum += (float) ($item['amount'] ?? 0);
            } elseif (is_numeric($item)) {
                $sum += (float) $item;
            }
        }
        return round(max(0.0, $sum), 2);
    }
}
