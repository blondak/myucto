<?php

declare(strict_types=1);

namespace MyInvoice\Service\Validation;

use MyInvoice\Support\PaymentMethods;

/**
 * Validace přijaté faktury. Vrací mapu pole → list chyb (CZ texty z ErrorCatalog jsou pro
 * runtime API messages; tato validace vrací technické zprávy, které jdou přes ErrorCatalog
 * v Json::error).
 */
final class PurchaseInvoiceValidation
{
    public const ALLOWED_DOC_KINDS = ['invoice', 'receipt', 'credit_note', 'advance', 'tax_document'];
    public const ALLOWED_STATUSES  = ['draft', 'received', 'booked', 'paid', 'cancelled'];

    /** Klasifikační kódy v režimu samovyměření příjemcem: tuzemský §92 (5), pořízení
     *  zboží z JČS (23), služba z EU/3. země (24, 24e), dovoz zboží ze 3. země (25). */
    public const REVERSE_CHARGE_CODES = ['5', '23', '24', '24e', '25'];

    /**
     * Je doklad v režimu přenesení daňové povinnosti / samovyměření? Rozpozná se
     * příznakem `reverse_charge` nebo klasifikačním kódem hlavičky. U reverse charge
     * je dodavatel z pohledu české DPH neplátce ze své podstaty (nefakturuje českou
     * DPH), ale příjemce si daň samovyměří a smí ji odečíst (§ 72/73) — proto se u něj
     * nesmí hlásit „od neplátce nelze odpočíst".
     *
     * @param array<string,mixed> $invoice
     */
    public static function isReverseCharge(array $invoice): bool
    {
        if (!empty($invoice['reverse_charge'])) {
            return true;
        }
        return in_array((string) ($invoice['vat_classification_code'] ?? ''), self::REVERSE_CHARGE_CODES, true);
    }

    /**
     * @param array<int, float>|null $vatRates
     * @return array<string, string[]>
     */
    public static function invoice(array $data, ?array $vatRates = null): array
    {
        $err = [];

        if (empty($data['vendor_id']) || !is_numeric($data['vendor_id']) || (int) $data['vendor_id'] <= 0) {
            $err['vendor_id'][] = 'Dodavatel je povinný';
        }

        $vendorInvNum = trim((string) ($data['vendor_invoice_number'] ?? ''));
        if ($vendorInvNum === '') {
            $err['vendor_invoice_number'][] = 'Číslo dokladu od dodavatele je povinné';
        } elseif (strlen($vendorInvNum) > 50) {
            $err['vendor_invoice_number'][] = 'Číslo dokladu má max 50 znaků';
        } elseif (preg_match('/[\x00-\x1f\x7f]/', $vendorInvNum)) {
            // Bez kontrolních znaků (anti-injection / log poisoning)
            $err['vendor_invoice_number'][] = 'Číslo dokladu obsahuje neplatné znaky';
        }

        if (isset($data['document_kind'])) {
            $kind = (string) $data['document_kind'];
            if (!in_array($kind, self::ALLOWED_DOC_KINDS, true)) {
                $err['document_kind'][] = 'Neplatný typ dokladu';
            }
        }

        // Forma úhrady (migrace 1128) — whitelist; neznámá hodnota je chyba, ať uživatel
        // vidí, že se jeho volba neuložila. Repository navíc padá na 'bank_transfer'.
        if (array_key_exists('payment_method', $data) && $data['payment_method'] !== null && $data['payment_method'] !== '') {
            if (!PaymentMethods::isValid($data['payment_method'])) {
                $err['payment_method'][] = 'Neplatný způsob úhrady';
            }
        }

        if (isset($data['currency_id']) && (int) $data['currency_id'] <= 0) {
            $err['currency_id'][] = 'Neplatné currency_id';
        }

        if (!empty($data['issue_date']) && !self::isValidDate((string) $data['issue_date'])) {
            $err['issue_date'][] = 'Neplatné datum vystavení';
        } elseif (empty($data['issue_date'])) {
            $err['issue_date'][] = 'Datum vystavení je povinné';
        }

        if (!empty($data['due_date']) && !self::isValidDate((string) $data['due_date'])) {
            $err['due_date'][] = 'Neplatné datum splatnosti';
        } elseif (empty($data['due_date'])) {
            $err['due_date'][] = 'Datum splatnosti je povinné';
        }

        if (!empty($data['tax_date']) && !self::isValidDate((string) $data['tax_date'])) {
            $err['tax_date'][] = 'Neplatné DUZP';
        }

        if (!empty($data['received_at']) && !self::isValidDate((string) $data['received_at'])) {
            $err['received_at'][] = 'Neplatné datum přijetí';
        }

        // Manuální varsymbol — volitelný
        if (array_key_exists('varsymbol', $data) && $data['varsymbol'] !== null && $data['varsymbol'] !== '') {
            $vs = (string) $data['varsymbol'];
            if (strlen($vs) > 20) {
                $err['varsymbol'][] = 'Varsymbol má max 20 znaků';
            }
            if (preg_match('/[\x00-\x1f\x7f]/', $vs)) {
                $err['varsymbol'][] = 'Varsymbol obsahuje neplatné znaky';
            }
        }

        // Multi-currency platba
        if (!empty($data['payment_currency_id']) && (int) $data['payment_currency_id'] <= 0) {
            $err['payment_currency_id'][] = 'Neplatné payment_currency_id';
        }
        if (isset($data['payment_exchange_rate']) && $data['payment_exchange_rate'] !== null && $data['payment_exchange_rate'] !== '') {
            $r = (float) $data['payment_exchange_rate'];
            if ($r <= 0 || $r > 100000) {
                $err['payment_exchange_rate'][] = 'Kurz platby je mimo rozumný rozsah';
            }
        }
        if (isset($data['exchange_rate']) && $data['exchange_rate'] !== null && $data['exchange_rate'] !== '') {
            $r = (float) $data['exchange_rate'];
            if ($r <= 0 || $r > 100000) {
                $err['exchange_rate'][] = 'Kurz faktury je mimo rozumný rozsah';
            }
        }

        // Items
        $items = $data['items'] ?? [];
        if (!is_array($items)) {
            $err['items'][] = 'items musí být pole';
        } else {
            foreach (array_values($items) as $i => $item) {
                if (!is_array($item)) {
                    $err["items.{$i}"][] = 'Neplatná položka';
                    continue;
                }
                $itemErrors = InvoiceAmountPolicy::validateItem($item, $i);
                if (!empty($itemErrors)) {
                    $err = array_merge($err, $itemErrors);
                }
                // VAT rate musí existovat v číselníku
                if ($vatRates !== null) {
                    $rateId = (int) ($item['vat_rate_id'] ?? 0);
                    if ($rateId === 0 || !array_key_exists($rateId, $vatRates)) {
                        $err["items.{$i}.vat_rate_id"][] = 'Neznámá DPH sazba';
                    }
                }
            }
        }

        $advance = (float) ($data['advance_paid_amount'] ?? 0);
        if ($advance < 0) {
            $err['advance_paid_amount'][] = 'Záloha nesmí být záporná';
        }

        // Notes — omezit velikost (anti-DoS na DB)
        foreach (['note_above_items', 'note_below_items'] as $f) {
            if (isset($data[$f]) && is_string($data[$f]) && strlen($data[$f]) > 65535) {
                $err[$f][] = 'Poznámka přesahuje 64 KB';
            }
        }

        return $err;
    }

    /**
     * Non-blocking varování k uložené faktuře (vyhodnocuje se PO recompute, nad
     * skutečně uloženými sumami). UI je zobrazí jako upozornění — neblokují uložení.
     *
     * @param array<string,mixed> $invoice Záznam z PurchaseInvoiceRepository::find().
     * @return list<string>
     */
    public static function warnings(array $invoice): array
    {
        $warn = [];

        // Dobropis (opravný daňový doklad) má dle metodiky záporné částky. Kladný
        // součet typicky znamená dvojí negaci (záporné množství I cena zároveň) →
        // base = qty × price vyjde kladně a ve výkazech DPH/KH by se plnění přičetlo
        // místo odečtení (DPHDP3 ř. 40, KH B.2). Záporné znaménko stačí na jedné
        // straně: −1 ks × 1000, nebo 1 ks × −1000. Viz issue #35.
        if ((string) ($invoice['document_kind'] ?? 'invoice') === 'credit_note') {
            $totalBase = (float) ($invoice['total_without_vat'] ?? 0);
            if ($totalBase > 0.005) {
                $warn[] = 'credit_note_positive_total';
            }
        }

        return $warn;
    }

    private static function isValidDate(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
