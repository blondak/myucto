<?php

declare(strict_types=1);

namespace MyInvoice\Service\Shoptet;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver;
use PDO;

/**
 * Feed zásob a cen pro automatický import produktů Shoptetu.
 *
 * Specifikace: developers.shoptet.com/shoptet-tools/shoptet-xml-specification/
 * (Relax NG products-supplier-v10.rng), podpora.shoptet.cz/automaticke-importy-produktu/.
 * Kořen SHOP, SHOPITEM s CODE (případně EAN), PRICE_VAT a STOCK/AMOUNT; produkt
 * s variantami jako SHOPITEM/VARIANTS/VARIANT s povinnými PARAMETERS. Shoptet páruje
 * podle kódu nebo EAN a v aktualizačním importu přepisuje jen existující produkty.
 *
 * Záměrně se neposílá název ani popis jednoduchého produktu: úplný import (jednou
 * denně) smí měnit všechna pole a katalog v Shoptetu tu MyÚčto nevlastní. Název
 * zůstává jen u produktu s variantami, kde podle Shoptetu slouží k jejich seskupení.
 *
 * Obsah je stabilní (řazení podle kódu, žádné časové razítko), protože Shoptet
 * od 10. 2. 2025 zpracuje feed jen tehdy, když se od minula změnil.
 *
 * Množství = fyzický stav ve zvoleném skladu (nebo součet prodejných skladů) minus
 * aktivní rezervace objednávek z JINÝCH kanálů než Shoptet. Vlastní nároky Shoptet
 * odečítá sám, jejich odečtení tady by zásobu snížilo dvakrát.
 *
 * Ruční CSV pro import produktů (podpora.shoptet.cz/import-produktu/) obsahuje jen
 * produkty bez variant: varianty se v CSV párují přes `pairCode` Shoptetu, který
 * MyÚčto nezná, a chybný párovací kód by variantu v e-shopu přeskupil.
 */
final class ShoptetFeedService
{
    private const MAX_CODE_LENGTH = 64;
    private const MAX_ITEMS = 20000;

    public function __construct(
        private readonly Connection $db,
        private readonly EffectivePriceResolver $prices,
    ) {}

    /**
     * @param array<string,mixed> $settings řádek shoptet_settings
     * @return array{xml:string, etag:string, items:int, skipped:list<string>}
     */
    public function xml(int $supplierId, array $settings): array
    {
        $data = $this->rows($supplierId, $settings);
        $w = new \XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('SHOP');
        $count = 0;
        foreach ($data['groups'] as $group) {
            $w->startElement('SHOPITEM');
            if ($group['master_id'] === null) {
                $this->writeDetail($w, $group['items'][0]);
                $count++;
            } else {
                $w->writeElement('NAME', mb_substr((string) $group['name'], 0, 250));
                $w->startElement('VARIANTS');
                foreach ($group['items'] as $item) {
                    $w->startElement('VARIANT');
                    $this->writeDetail($w, $item);
                    $w->startElement('PARAMETERS');
                    foreach ($item['parameters'] as $parameter) {
                        $w->startElement('PARAMETER');
                        $w->writeElement('NAME', mb_substr($parameter['name'], 0, 250));
                        $w->writeElement('VALUE', mb_substr($parameter['value'], 0, 128));
                        $w->endElement();
                    }
                    $w->endElement();
                    $w->endElement();
                    $count++;
                }
                $w->endElement();
            }
            $w->endElement();
        }
        $w->endElement();
        $w->endDocument();
        $xml = $w->outputMemory();

        return ['xml' => $xml, 'etag' => hash('sha256', $xml), 'items' => $count, 'skipped' => $data['skipped']];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{csv:string, items:int, skipped:list<string>}
     */
    public function csv(int $supplierId, array $settings): array
    {
        $data = $this->rows($supplierId, $settings);
        $withPrice = (bool) ($settings['feed_include_price'] ?? true);
        $header = ['code', 'pairCode', 'stock'];
        if ($withPrice) {
            array_push($header, 'price', 'includingVat');
        }
        $lines = [implode(';', $header)];
        $count = 0;
        $skipped = $data['skipped'];
        foreach ($data['groups'] as $group) {
            if ($group['master_id'] !== null) {
                foreach ($group['items'] as $item) {
                    $skipped[] = sprintf('%s: varianta — v CSV se nepřenáší, použijte XML feed', $item['sku']);
                }
                continue;
            }
            $item = $group['items'][0];
            // Kód, který začíná znakem vzorce, by tabulkový procesor spustil. Apostrof,
            // který to zneškodní, se ale stane součástí kódu a Shoptet by podle něj produkt
            // nespároval. Takový produkt proto do CSV nedáváme vůbec (XML feed ho nese).
            $sku = (string) $item['sku'];
            if ($sku !== '' && in_array($sku[0], ['=', '+', '-', '@'], true)) {
                $skipped[] = sprintf('%s: kód začíná znakem „%s", v CSV by se nespároval — použijte XML feed', $sku, $sku[0]);
                continue;
            }
            $row = [self::csvCell($sku), '', $item['stock'] ?? ''];
            if ($withPrice) {
                $row[] = $item['price_vat'] ?? '';
                $row[] = $item['price_vat'] !== null ? '1' : '';
            }
            $lines[] = implode(';', $row);
            $count++;
        }

        return ['csv' => implode("\r\n", $lines) . "\r\n", 'items' => $count, 'skipped' => $skipped];
    }

    /** @param array<string,mixed> $item */
    private function writeDetail(\XMLWriter $w, array $item): void
    {
        $w->writeElement('CODE', $item['sku']);
        if ($item['ean'] !== null) {
            $w->writeElement('EAN', $item['ean']);
        }
        if ($item['price_vat'] !== null) {
            $w->writeElement('PRICE_VAT', $item['price_vat']);
        }
        if ($item['stock'] !== null) {
            $w->startElement('STOCK');
            $w->writeElement('AMOUNT', $item['stock']);
            $w->endElement();
        }
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{groups:list<array{master_id:?int,name:?string,items:list<array<string,mixed>>}>, skipped:list<string>}
     */
    private function rows(int $supplierId, array $settings): array
    {
        $scope = (string) ($settings['feed_scope'] ?? 'eshop') === 'active' ? '' : ' AND si.export_eshop = 1';
        $stmt = $this->db->pdo()->prepare(
            "SELECT si.id, si.sku, si.ean, si.is_stocked, v.rate_percent, pv.master_id, pm.name AS master_name
               FROM stock_items si
          LEFT JOIN vat_rates v ON v.id = si.vat_rate_id
          LEFT JOIN product_variants pv ON pv.stock_item_id = si.id AND pv.supplier_id = si.supplier_id
          LEFT JOIN product_masters pm ON pm.id = pv.master_id AND pm.supplier_id = si.supplier_id AND pm.status = 'active'
              WHERE si.supplier_id = ? AND si.is_active = 1 AND si.item_type IN ('goods', 'product')" . $scope . '
           ORDER BY si.sku, si.id
              LIMIT ' . (self::MAX_ITEMS + 1)
        );
        $stmt->execute([$supplierId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $skipped = [];
        if (count($items) > self::MAX_ITEMS) {
            array_pop($items);
            $skipped[] = sprintf('Feed je omezený na %d produktů (limit Shoptetu).', self::MAX_ITEMS);
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $items);
        $stock = $this->stock($supplierId, $ids, isset($settings['feed_warehouse_id']) ? (int) $settings['feed_warehouse_id'] : null);
        $prices = (bool) ($settings['feed_include_price'] ?? true) && $ids !== []
            ? $this->prices->resolveMany($supplierId, $ids, 'CZK')
            : [];
        $parameters = $this->parameters($supplierId, $ids);

        $groups = [];
        foreach ($items as $row) {
            $sku = trim((string) $row['sku']);
            if ($sku === '' || mb_strlen($sku) > self::MAX_CODE_LENGTH) {
                $skipped[] = sprintf('%s: kód je prázdný nebo delší než %d znaků', $sku, self::MAX_CODE_LENGTH);
                continue;
            }
            $id = (int) $row['id'];
            $net = $prices[$id]['unit_price'] ?? null;
            $priceVat = null;
            if ($net !== null && $row['rate_percent'] !== null && is_numeric((string) $net)) {
                $priceVat = number_format(max(0.0, round((float) $net * (1 + (float) $row['rate_percent'] / 100), 2)), 2, '.', '');
            }
            $masterId = $row['master_id'] !== null && $row['master_name'] !== null ? (int) $row['master_id'] : null;
            $item = [
                'sku' => $sku,
                'ean' => ($ean = trim((string) ($row['ean'] ?? ''))) !== '' ? $ean : null,
                'price_vat' => $priceVat,
                'stock' => (int) $row['is_stocked'] === 1
                    ? number_format(max(0.0, min(9999999.0, $stock[$id] ?? 0.0)), 3, '.', '')
                    : null,
                'parameters' => $parameters[$id] ?? [['name' => 'Varianta', 'value' => mb_substr($sku, 0, 128)]],
            ];
            $key = $masterId !== null ? 'm' . $masterId : 'i' . $id;
            $groups[$key] ??= ['master_id' => $masterId, 'name' => $row['master_name'], 'items' => []];
            $groups[$key]['items'][] = $item;
        }

        return ['groups' => array_values($groups), 'skipped' => $skipped];
    }

    /**
     * Prodejná zásoba: fyzický stav minus aktivní rezervace z jiných kanálů.
     *
     * @param list<int> $ids
     * @return array<int,float>
     */
    private function stock(int $supplierId, array $ids, ?int $warehouseId): array
    {
        if ($ids === []) {
            return [];
        }
        $sellable = $this->db->hasColumn('warehouses', 'is_sellable') ? ' AND w.is_sellable = 1' : '';
        $whFilter = $warehouseId !== null ? ' AND w.id = ' . $warehouseId : $sellable;
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $levels = $this->db->pdo()->prepare(
                "SELECT l.stock_item_id, SUM(l.qty) AS qty
                   FROM stock_levels l
                   JOIN warehouses w ON w.id = l.warehouse_id AND w.supplier_id = l.supplier_id AND w.is_active = 1{$whFilter}
                  WHERE l.supplier_id = ? AND l.stock_item_id IN ({$in})
               GROUP BY l.stock_item_id"
            );
            $levels->execute([$supplierId]);
            foreach ($levels->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out[(int) $r['stock_item_id']] = (float) $r['qty'];
            }
            $reserved = $this->db->pdo()->prepare(
                "SELECT r.stock_item_id, SUM(GREATEST(r.qty_reserved - r.qty_consumed - r.qty_released, 0)) AS qty
                   FROM sales_order_reservations r
                   JOIN sales_orders o ON o.id = r.order_id AND o.supplier_id = r.supplier_id
                   JOIN warehouses w ON w.id = r.warehouse_id AND w.supplier_id = r.supplier_id AND w.is_active = 1{$whFilter}
                  WHERE r.supplier_id = ? AND r.status = 'active' AND r.stock_item_id IN ({$in})
                    AND (o.external_source IS NULL OR o.external_source <> 'shoptet')
               GROUP BY r.stock_item_id"
            );
            $reserved->execute([$supplierId]);
            foreach ($reserved->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $id = (int) $r['stock_item_id'];
                $out[$id] = ($out[$id] ?? 0.0) - (float) $r['qty'];
            }
        }

        return $out;
    }

    /**
     * Parametry variant (atribut → volba) pro povinné PARAMETERS varianty.
     *
     * @param list<int> $ids
     * @return array<int,list<array{name:string,value:string}>>
     */
    private function parameters(int $supplierId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $stmt = $this->db->pdo()->prepare(
                "SELECT o.stock_item_id, a.name, ao.label
                   FROM product_variant_options o
                   JOIN stock_attributes a ON a.id = o.attribute_id AND a.supplier_id = o.supplier_id
                   JOIN stock_attribute_options ao ON ao.id = o.option_id AND ao.supplier_id = o.supplier_id
                  WHERE o.supplier_id = ? AND o.stock_item_id IN ({$in})
               ORDER BY o.stock_item_id, o.display_order, a.display_order, a.id"
            );
            $stmt->execute([$supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $out[(int) $r['stock_item_id']][] = ['name' => (string) $r['name'], 'value' => (string) $r['label']];
            }
        }

        return $out;
    }

    /** Buňka CSV bez rizika vzorce v tabulkovém procesoru a bez rozbití oddělovače. */
    private static function csvCell(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            $value = "'" . $value;
        }

        return str_contains($value, ';') || str_contains($value, '"')
            ? '"' . str_replace('"', '""', $value) . '"'
            : $value;
    }
}
