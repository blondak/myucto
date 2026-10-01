<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Product;

/**
 * Ke kterým položkám vydané faktury patří slevový řádek z hlavičky (`item_kind = 'discount'`).
 * SSOT pro rozpad výnosu na účty ({@see \MyInvoice\Service\Accounting\PostingService})
 * i pro dimenze výnosových řádků ({@see \MyInvoice\Service\Accounting\Dimension\DimensionStamper}).
 *
 * Sleva v procentech se generuje po sazbě DPH a klasifikačním kódu
 * (InvoiceRepository::materializeDiscountLines), a zlevňuje tedy položky TÉŽE sazby a kódu.
 * Podíl = poměr jejich základu. Když taková položka není (ručně upravený doklad), nese
 * slevu poměrně každá kladná položka. Bez rozpuštění by sleva skončila na předkontaci
 * dokladu, zatímco zlevněné zboží na účtu produktu (604 D 1000 / 602 MD 100).
 *
 * Zrcadlo přijaté strany {@see \MyInvoice\Service\Accounting\Expense\PurchaseDiscountAllocation}.
 */
final class IssuedDiscountAllocation
{
    /**
     * @param list<array<string,mixed>> $items řádky s klíči id, item_kind, total_without_vat,
     *                                         vat_rate_id, vat_classification_code
     * @return array<int,array<int,float>> id slevového řádku => (id cílové položky => podíl 0..1)
     */
    public static function allocate(array $items): array
    {
        $positive = [];
        foreach ($items as $item) {
            if (($item['item_kind'] ?? 'standard') !== 'discount' && (float) $item['total_without_vat'] > 0.0) {
                $positive[(int) $item['id']] = $item;
            }
        }
        if ($positive === []) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (($item['item_kind'] ?? 'standard') !== 'discount') {
                continue;
            }
            $targets = array_filter(
                $positive,
                static fn (array $p): bool => (int) $p['vat_rate_id'] === (int) $item['vat_rate_id']
                    && (string) ($p['vat_classification_code'] ?? '') === (string) ($item['vat_classification_code'] ?? ''),
            );
            if ($targets === []) {
                $targets = $positive;
            }
            $sum = array_sum(array_map(static fn (array $p): float => (float) $p['total_without_vat'], $targets));
            $shares = [];
            foreach ($targets as $id => $p) {
                $shares[$id] = (float) $p['total_without_vat'] / $sum;
            }
            $out[(int) $item['id']] = $shares;
        }
        return $out;
    }
}
