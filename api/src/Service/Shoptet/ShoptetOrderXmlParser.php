<?php

declare(strict_types=1);

namespace MyInvoice\Service\Shoptet;

/**
 * XML export objednávek Shoptetu (Objednávky → Export, šablona typu XML).
 *
 * Specifikace: podpora.shoptet.cz/export-objednavek/ popisuje export a jeho volby,
 * strukturu elementů Shoptet veřejně nevydává (šablona se skládá z placeholderů
 * `#code#`, `#billCompanyId#` apod.). Názvy elementů níže vychází ze systémové
 * šablony „Shoptet - XML", jak ji používají integrátoři (ORDERS/ORDER, CUSTOMER/
 * BILLING_ADDRESS, TOTAL_PRICE, ORDER_ITEMS/ITEM s TYPE, UNIT_PRICE a TOTAL_PRICE).
 *
 * Protože šablonu může obchodník upravit, parser je tolerantní: elementy hledá bez
 * ohledu na velikost písmen a pod více jmény, neznámé elementy ignoruje a chybějící
 * povinný údaj hlásí jako chybu jedné objednávky, ne celého souboru.
 */
final class ShoptetOrderXmlParser
{
    /**
     * @return array{orders:list<array<string,mixed>>, errors:list<array{ref:string,message:string}>}
     */
    public function parse(string $xml): array
    {
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            throw new ShoptetImportException('shoptet_xml_doctype', 'XML export obsahuje DOCTYPE, což není povoleno.');
        }
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded || $dom->documentElement === null) {
            throw new ShoptetImportException('shoptet_xml_invalid', 'Soubor není čitelné XML.');
        }
        // Kontrola nad surovými bajty nevidí DOCTYPE v UTF-16 (nulové bajty mezi znaky),
        // libxml ho přesto načte. Rozhoduje proto i načtený dokument.
        if ($dom->doctype !== null) {
            throw new ShoptetImportException('shoptet_xml_doctype', 'XML export obsahuje DOCTYPE, což není povoleno.');
        }

        $orderEls = [];
        $root = $dom->documentElement;
        if (ShoptetValues::key($root->localName) === 'order') {
            $orderEls[] = $root;
        } else {
            foreach ($dom->getElementsByTagName('*') as $el) {
                if ($el instanceof \DOMElement && ShoptetValues::key($el->localName) === 'order'
                    && $el->parentNode instanceof \DOMElement
                    && ShoptetValues::key($el->parentNode->localName) === 'orders') {
                    $orderEls[] = $el;
                }
            }
        }
        if ($orderEls === []) {
            throw new ShoptetImportException(
                'shoptet_xml_no_orders',
                'V souboru není žádná objednávka (očekává se kořen ORDERS s elementy ORDER).'
            );
        }

        $orders = [];
        $errors = [];
        foreach ($orderEls as $index => $orderEl) {
            $ref = 'Objednávka č. ' . ($index + 1);
            try {
                $order = $this->order($orderEl, $ref);
                $problem = ShoptetOrderValidator::problem($order);
                if ($problem !== null) {
                    $errors[] = ['ref' => $order['code'] !== '' ? $order['code'] : $ref, 'message' => $problem];
                    continue;
                }
                $orders[] = $order;
            } catch (\Throwable $e) {
                $errors[] = ['ref' => $ref, 'message' => $e->getMessage()];
            }
        }

        return ['orders' => $orders, 'errors' => $errors];
    }

    /** @return array<string,mixed> */
    private function order(\DOMElement $el, string $ref): array
    {
        $customer = $this->child($el, ['CUSTOMER']);
        $billing = $this->child($customer ?? $el, ['BILLING_ADDRESS', 'BILLING', 'INVOICE_ADDRESS']);
        $delivery = $this->child($customer ?? $el, ['SHIPPING_ADDRESS', 'DELIVERY_ADDRESS', 'DELIVERY']);
        $currency = $this->child($el, ['CURRENCY']);
        $totals = $this->child($el, ['TOTAL_PRICE', 'TOTAL', 'PRICE']);

        $currencyCode = $currency !== null
            ? ($this->text($currency, ['CODE', 'CURRENCY_CODE']) ?? trim($this->ownText($currency)))
            : null;

        $items = [];
        $itemsEl = $this->child($el, ['ORDER_ITEMS', 'ITEMS']);
        if ($itemsEl !== null) {
            foreach ($itemsEl->childNodes as $itemEl) {
                if ($itemEl instanceof \DOMElement && ShoptetValues::key($itemEl->localName) === 'item') {
                    $items[] = $this->item($itemEl);
                }
            }
        }

        return [
            'code' => (string) (ShoptetValues::text($this->text($el, ['CODE', 'ORDER_CODE', 'NUMBER']), 100) ?? ''),
            'ref' => $ref,
            'date' => ShoptetValues::dateTime($this->text($el, ['DATE', 'CREATION_TIME', 'CREATED'])),
            'status' => ShoptetValues::text($this->text($el, ['STATUS', 'STATUS_NAME']), 120),
            'currency' => strtoupper(trim((string) ($currencyCode ?? ''))) ?: 'CZK',
            'exchange_rate' => $currency !== null
                ? ShoptetValues::decimal($this->text($currency, ['EXCHANGE_RATE', 'RATE']))
                : ShoptetValues::decimal($this->text($el, ['EXCHANGE_RATE'])),
            'email' => ShoptetValues::text($this->text($customer ?? $el, ['EMAIL']), 190),
            'phone' => ShoptetValues::text($this->text($customer ?? $el, ['PHONE']), 60),
            'billing' => $this->address($billing, true),
            'delivery' => $this->address($delivery, false),
            'vat_mode' => ShoptetValues::text(
                $this->text($el, ['VAT_MODE', 'TAX_MODE', 'VATMODE'])
                    ?? ($customer !== null ? $this->text($customer, ['VAT_MODE', 'TAX_MODE']) : null),
                40,
            ),
            'items' => $items,
            'totals' => [
                'with_vat' => $totals !== null ? ShoptetValues::decimal($this->text($totals, ['WITH_VAT'])) : null,
                'without_vat' => $totals !== null ? ShoptetValues::decimal($this->text($totals, ['WITHOUT_VAT'])) : null,
                'vat' => $totals !== null ? ShoptetValues::decimal($this->text($totals, ['VAT'])) : null,
                'rounding' => $totals !== null ? ShoptetValues::decimal($this->text($totals, ['ROUNDING'])) : null,
                'to_pay' => $totals !== null ? ShoptetValues::decimal($this->text($totals, ['PRICE_TO_PAY', 'TO_PAY'])) : null,
            ],
            'paid' => $totals !== null
                ? ShoptetValues::bool($this->text($totals, ['PAID']))
                : ShoptetValues::bool($this->text($el, ['PAID'])),
        ];
    }

    /** @return array<string,mixed> */
    private function item(\DOMElement $el): array
    {
        $unit = $this->child($el, ['UNIT_PRICE']);
        $total = $this->child($el, ['TOTAL_PRICE']);
        $type = ShoptetValues::text($this->text($el, ['TYPE', 'ITEM_TYPE']), 40);
        $rate = ($unit !== null ? $this->text($unit, ['VAT_RATE']) : null)
            ?? ($total !== null ? $this->text($total, ['VAT_RATE']) : null)
            ?? $this->text($el, ['VAT_RATE']);

        return [
            'kind' => ShoptetValues::itemKind($type),
            'type' => $type,
            'code' => ShoptetValues::text($this->text($el, ['CODE', 'PRODUCT_CODE']), 80),
            'ean' => ShoptetValues::text($this->text($el, ['EAN']), 20),
            'name' => ShoptetValues::text($this->text($el, ['NAME']), 400),
            'variant' => ShoptetValues::text($this->text($el, ['VARIANT_NAME', 'VARIANT']), 200),
            'quantity' => ShoptetValues::decimal($this->text($el, ['AMOUNT', 'QUANTITY'])),
            'unit' => ShoptetValues::text($this->text($el, ['UNIT']), 20),
            'unit_price_with_vat' => $unit !== null ? ShoptetValues::decimal($this->text($unit, ['WITH_VAT'])) : null,
            'unit_price_without_vat' => $unit !== null ? ShoptetValues::decimal($this->text($unit, ['WITHOUT_VAT'])) : null,
            'vat_rate' => ShoptetValues::decimal($rate),
            'total_with_vat' => $total !== null ? ShoptetValues::decimal($this->text($total, ['WITH_VAT'])) : null,
            'total_without_vat' => $total !== null ? ShoptetValues::decimal($this->text($total, ['WITHOUT_VAT'])) : null,
            'discount_percent' => ShoptetValues::decimal($this->text($el, ['DISCOUNT', 'DISCOUNT_PERCENT'])),
        ];
    }

    /** @return array<string,?string> */
    private function address(?\DOMElement $el, bool $withIds): array
    {
        if ($el === null) {
            return [];
        }
        $street = trim((string) $this->text($el, ['STREET']) . ' ' . (string) $this->text($el, ['HOUSENUMBER', 'HOUSE_NUMBER']));
        $out = [
            'name' => ShoptetValues::text($this->text($el, ['NAME', 'FULL_NAME']), 190),
            'company' => ShoptetValues::text($this->text($el, ['COMPANY']), 190),
            'street' => ShoptetValues::text($street, 190),
            'city' => ShoptetValues::text($this->text($el, ['CITY']), 120),
            'zip' => ShoptetValues::text($this->text($el, ['ZIP', 'POSTCODE']), 20),
            'country' => ShoptetValues::country($this->text($el, ['COUNTRY_CODE']))
                ?? ShoptetValues::country($this->text($el, ['COUNTRY'])),
        ];
        if ($withIds) {
            $out['company_id'] = ShoptetValues::text($this->text($el, ['COMPANY_ID', 'ICO']), 20);
            $out['vat_id'] = ShoptetValues::text($this->text($el, ['VAT_ID', 'DIC']), 30);
        }

        return $out;
    }

    /** @param list<string> $names */
    private function child(\DOMElement $parent, array $names): ?\DOMElement
    {
        $wanted = array_map(ShoptetValues::key(...), $names);
        foreach ($wanted as $name) {
            foreach ($parent->childNodes as $node) {
                if ($node instanceof \DOMElement && ShoptetValues::key($node->localName) === $name) {
                    return $node;
                }
            }
        }

        return null;
    }

    /** @param list<string> $names */
    private function text(\DOMElement $parent, array $names): ?string
    {
        $el = $this->child($parent, $names);
        if ($el === null) {
            return null;
        }
        $value = trim($el->textContent);

        return $value !== '' ? $value : null;
    }

    private function ownText(\DOMElement $el): string
    {
        $out = '';
        foreach ($el->childNodes as $node) {
            if ($node instanceof \DOMText) {
                $out .= $node->wholeText;
            }
        }

        return $out;
    }
}
