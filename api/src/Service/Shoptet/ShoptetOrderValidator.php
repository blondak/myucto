<?php

declare(strict_types=1);

namespace MyInvoice\Service\Shoptet;

/**
 * Povinné údaje normalizované objednávky — společné pro XML i CSV cestu, ať obě
 * odmítají totéž a stejnou hláškou.
 */
final class ShoptetOrderValidator
{
    /** @param array<string,mixed> $order */
    public static function problem(array $order): ?string
    {
        if (trim((string) ($order['code'] ?? '')) === '') {
            return 'Objednávka nemá kód (element CODE, sloupec code). Bez něj ji nejde spárovat při dalším importu.';
        }
        if (!preg_match('/^[\p{L}\p{N}_\-\/.]{1,100}$/u', (string) $order['code'])) {
            return 'Kód objednávky obsahuje nepovolené znaky.';
        }
        $items = $order['items'] ?? [];
        if (!is_array($items) || $items === []) {
            return 'Objednávka nemá žádnou položku (ORDER_ITEMS). V šabloně exportu zapněte položky objednávky.';
        }
        foreach ($items as $index => $item) {
            $label = sprintf('Položka %d%s', $index + 1, !empty($item['name']) ? ' („' . $item['name'] . '")' : '');
            $qty = $item['quantity'] ?? null;
            if ($qty === null || (float) $qty <= 0.0) {
                return $label . ' nemá kladné množství (AMOUNT).';
            }
            if (($item['unit_price_with_vat'] ?? null) === null
                && ($item['total_with_vat'] ?? null) === null
                && ($item['unit_price_without_vat'] ?? null) === null) {
                return $label . ' nemá cenu (UNIT_PRICE nebo TOTAL_PRICE).';
            }
            if (($item['vat_rate'] ?? null) === null) {
                return $label . ' nemá sazbu DPH (VAT_RATE). Sazba se nedomýšlí, doplňte ji do šablony exportu.';
            }
            if ((float) $item['vat_rate'] < 0.0 || (float) $item['vat_rate'] > 100.0) {
                return $label . ' má nesmyslnou sazbu DPH.';
            }
        }

        return null;
    }
}
