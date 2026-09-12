<?php

declare(strict_types=1);

namespace MyInvoice\Service\Shoptet;

/**
 * CSV export objednávek Shoptetu (Objednávky → Export, vlastní šablona CSV).
 *
 * Specifikace: podpora.shoptet.cz/export-objednavek/. Oddělovač i hlavičku si volí
 * obchodník v šabloně, kódování Shoptet u importů uvádí UTF-8 i Windows-1250
 * (podpora.shoptet.cz/import-produktu/). Parser proto:
 *   - vyžaduje řádek hlavičky a oddělovač pozná sám (středník, čárka, tabulátor),
 *   - neplatné UTF-8 převede z Windows-1250,
 *   - sloupce páruje podle názvu bez ohledu na diakritiku a velikost písmen; přijímá
 *     názvy placeholderů Shoptetu (`code`, `billCompanyId`, `orderItemCode` …)
 *     i české popisky,
 *   - čte šablonu „jeden řádek = jedna položka" a řádky se stejným kódem skládá do
 *     jedné objednávky (volba „Exportovat jednotlivé položky objednávky").
 * Neznámé sloupce ignoruje.
 */
final class ShoptetOrderCsvParser
{
    /** @var array<string,list<string>> */
    private const COLUMNS = [
        'code' => ['code', 'orderCode', 'kod objednavky', 'cislo objednavky', 'objednavka'],
        'date' => ['date', 'datum', 'datum objednavky', 'creationTime'],
        'status' => ['status', 'statusName', 'stav', 'stav objednavky'],
        'currency' => ['currency', 'currencyCode', 'mena'],
        'exchange_rate' => ['exchangeRate', 'currencyExchangeRate', 'kurz'],
        'email' => ['email', 'e-mail'],
        'phone' => ['phone', 'telefon'],
        'vat_mode' => ['vatMode', 'taxMode', 'rezim dph'],
        'bill_name' => ['billFullName', 'billName', 'fakturacni jmeno', 'jmeno a prijmeni'],
        'bill_company' => ['billCompany', 'firma', 'spolecnost'],
        'bill_street' => ['billStreet', 'ulice'],
        'bill_house_number' => ['billHouseNumber', 'cislo popisne'],
        'bill_city' => ['billCity', 'mesto'],
        'bill_zip' => ['billZip', 'psc'],
        'bill_country' => ['billCountryCode', 'kod zeme', 'zeme'],
        'bill_company_id' => ['billCompanyId', 'companyId', 'ico', 'ic'],
        'bill_vat_id' => ['billVatId', 'vatId', 'dic'],
        'delivery_name' => ['deliveryFullName', 'deliveryName', 'dorucovaci jmeno'],
        'delivery_company' => ['deliveryCompany'],
        'delivery_street' => ['deliveryStreet'],
        'delivery_house_number' => ['deliveryHouseNumber'],
        'delivery_city' => ['deliveryCity'],
        'delivery_zip' => ['deliveryZip'],
        'delivery_country' => ['deliveryCountryCode', 'zeme doruceni'],
        'total_with_vat' => ['totalPriceWithVat', 'celkem s dph', 'cena celkem s dph'],
        'total_without_vat' => ['totalPriceWithoutVat', 'celkem bez dph'],
        'total_vat' => ['totalPriceVat'],
        'rounding' => ['totalPriceRounding', 'zaokrouhleni'],
        'to_pay' => ['totalPriceToPay', 'k uhrade'],
        'paid' => ['paid', 'zaplaceno'],
        'item_type' => ['orderItemType', 'itemType', 'typ polozky'],
        'item_code' => ['orderItemCode', 'itemCode', 'kod polozky', 'kod produktu'],
        'item_ean' => ['orderItemEan', 'itemEan', 'ean'],
        'item_name' => ['orderItemName', 'itemName', 'nazev polozky', 'polozka'],
        'item_variant' => ['orderItemVariantName', 'variantName', 'varianta'],
        'item_amount' => ['orderItemAmount', 'itemAmount', 'mnozstvi'],
        'item_unit' => ['orderItemUnit', 'itemUnit', 'jednotka'],
        'item_unit_price_with_vat' => ['orderItemUnitPriceWithVat', 'itemUnitPriceWithVat', 'jednotkova cena s dph'],
        'item_unit_price_without_vat' => ['orderItemUnitPriceWithoutVat', 'itemUnitPriceWithoutVat', 'jednotkova cena bez dph'],
        'item_vat_rate' => ['orderItemVatRate', 'itemVatRate', 'sazba dph'],
        'item_total_with_vat' => ['orderItemTotalPriceWithVat', 'itemTotalPriceWithVat', 'cena polozky s dph'],
        'item_total_without_vat' => ['orderItemTotalPriceWithoutVat', 'itemTotalPriceWithoutVat', 'cena polozky bez dph'],
        'item_discount' => ['orderItemDiscountPercent', 'itemDiscountPercent', 'sleva'],
    ];

    /**
     * @return array{orders:list<array<string,mixed>>, errors:list<array{ref:string,message:string}>}
     */
    public function parse(string $content): array
    {
        $content = self::toUtf8($content);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $header = null;
        $delimiter = ';';
        $map = [];
        $groups = [];
        $order = [];
        $errors = [];
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new ShoptetImportException('shoptet_csv_invalid', 'CSV nejde načíst.');
        }
        fwrite($handle, implode("\n", $lines));
        rewind($handle);
        $first = $lines[0] ?? '';
        $delimiter = self::detectDelimiter($first);
        $rowNo = 0;
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rowNo++;
            if ($row === [null] || $row === ['']) {
                continue;
            }
            if ($header === null) {
                $header = $row;
                $map = self::mapHeader($header);
                if (!isset($map['code'])) {
                    fclose($handle);
                    throw new ShoptetImportException(
                        'shoptet_csv_no_code',
                        'CSV nemá sloupec s kódem objednávky (code). Soubor musí mít řádek hlavičky.'
                    );
                }
                if (!isset($map['item_amount']) && !isset($map['item_name']) && !isset($map['item_code'])) {
                    fclose($handle);
                    throw new ShoptetImportException(
                        'shoptet_csv_no_items',
                        'CSV neobsahuje položky objednávek. V šabloně exportu zapněte export jednotlivých položek.'
                    );
                }
                continue;
            }
            $get = static function (string $field) use ($map, $row): ?string {
                if (!isset($map[$field])) {
                    return null;
                }
                $value = $row[$map[$field]] ?? null;
                $value = is_string($value) ? trim($value) : null;

                return $value !== '' ? $value : null;
            };
            $code = ShoptetValues::text($get('code'), 100);
            if ($code === null) {
                $errors[] = ['ref' => 'Řádek ' . $rowNo, 'message' => 'Řádek nemá kód objednávky.'];
                continue;
            }
            if (!isset($groups[$code])) {
                $groups[$code] = [
                    'code' => $code,
                    'ref' => 'Řádek ' . $rowNo,
                    'date' => ShoptetValues::dateTime($get('date')),
                    'status' => ShoptetValues::text($get('status'), 120),
                    'currency' => strtoupper((string) ($get('currency') ?? '')) ?: 'CZK',
                    'exchange_rate' => ShoptetValues::decimal($get('exchange_rate')),
                    'email' => ShoptetValues::text($get('email'), 190),
                    'phone' => ShoptetValues::text($get('phone'), 60),
                    'vat_mode' => ShoptetValues::text($get('vat_mode'), 40),
                    'billing' => [
                        'name' => ShoptetValues::text($get('bill_name'), 190),
                        'company' => ShoptetValues::text($get('bill_company'), 190),
                        'street' => ShoptetValues::text(trim(($get('bill_street') ?? '') . ' ' . ($get('bill_house_number') ?? '')), 190),
                        'city' => ShoptetValues::text($get('bill_city'), 120),
                        'zip' => ShoptetValues::text($get('bill_zip'), 20),
                        'country' => ShoptetValues::country($get('bill_country')),
                        'company_id' => ShoptetValues::text($get('bill_company_id'), 20),
                        'vat_id' => ShoptetValues::text($get('bill_vat_id'), 30),
                    ],
                    'delivery' => [
                        'name' => ShoptetValues::text($get('delivery_name'), 190),
                        'company' => ShoptetValues::text($get('delivery_company'), 190),
                        'street' => ShoptetValues::text(trim(($get('delivery_street') ?? '') . ' ' . ($get('delivery_house_number') ?? '')), 190),
                        'city' => ShoptetValues::text($get('delivery_city'), 120),
                        'zip' => ShoptetValues::text($get('delivery_zip'), 20),
                        'country' => ShoptetValues::country($get('delivery_country')),
                    ],
                    'items' => [],
                    'totals' => [
                        'with_vat' => ShoptetValues::decimal($get('total_with_vat')),
                        'without_vat' => ShoptetValues::decimal($get('total_without_vat')),
                        'vat' => ShoptetValues::decimal($get('total_vat')),
                        'rounding' => ShoptetValues::decimal($get('rounding')),
                        'to_pay' => ShoptetValues::decimal($get('to_pay')),
                    ],
                    'paid' => ShoptetValues::bool($get('paid')),
                ];
                $order[] = $code;
            }
            $type = ShoptetValues::text($get('item_type'), 40);
            $groups[$code]['items'][] = [
                'kind' => ShoptetValues::itemKind($type),
                'type' => $type,
                'code' => ShoptetValues::text($get('item_code'), 80),
                'ean' => ShoptetValues::text($get('item_ean'), 20),
                'name' => ShoptetValues::text($get('item_name'), 400),
                'variant' => ShoptetValues::text($get('item_variant'), 200),
                'quantity' => ShoptetValues::decimal($get('item_amount')),
                'unit' => ShoptetValues::text($get('item_unit'), 20),
                'unit_price_with_vat' => ShoptetValues::decimal($get('item_unit_price_with_vat')),
                'unit_price_without_vat' => ShoptetValues::decimal($get('item_unit_price_without_vat')),
                'vat_rate' => ShoptetValues::decimal($get('item_vat_rate')),
                'total_with_vat' => ShoptetValues::decimal($get('item_total_with_vat')),
                'total_without_vat' => ShoptetValues::decimal($get('item_total_without_vat')),
                'discount_percent' => ShoptetValues::decimal($get('item_discount')),
            ];
        }
        fclose($handle);
        if ($header === null) {
            throw new ShoptetImportException('shoptet_csv_empty', 'CSV soubor je prázdný.');
        }

        $orders = [];
        foreach ($order as $code) {
            $problem = ShoptetOrderValidator::problem($groups[$code]);
            if ($problem !== null) {
                $errors[] = ['ref' => $code, 'message' => $problem];
                continue;
            }
            $orders[] = $groups[$code];
        }

        return ['orders' => $orders, 'errors' => $errors];
    }

    /** @param list<?string> $header @return array<string,int> */
    private static function mapHeader(array $header): array
    {
        $lookup = [];
        foreach (self::COLUMNS as $field => $aliases) {
            foreach ($aliases as $alias) {
                $lookup[ShoptetValues::key($alias)] ??= $field;
            }
        }
        $map = [];
        foreach ($header as $index => $name) {
            $key = ShoptetValues::key((string) $name);
            if ($key !== '' && isset($lookup[$key]) && !isset($map[$lookup[$key]])) {
                $map[$lookup[$key]] = (int) $index;
            }
        }

        return $map;
    }

    private static function detectDelimiter(string $line): string
    {
        $counts = [';' => substr_count($line, ';'), "\t" => substr_count($line, "\t"), ',' => substr_count($line, ',')];
        arsort($counts);
        $best = (string) array_key_first($counts);

        return $counts[$best] > 0 ? $best : ';';
    }

    private static function toUtf8(string $content): string
    {
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }
        $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $content);

        return is_string($converted) ? $converted : $content;
    }
}
