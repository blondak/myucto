<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Expense;

/**
 * Ke které kladné položce přijaté faktury patří slevový řádek. SSOT pro zaúčtování
 * (rozpad nákladu na účty) i pro evidenci drobného majetku (cena karty po slevě).
 *
 * Slevový řádek (záporný, text typu „Sleva", „Rabatt", „Aktionsrabatt", „Discount",
 * „Kupon", „Voucher", „Promo") nemá vlastní věcný smysl: zlevňuje zboží, u kterého
 * stojí. Dokud se klasifikoval samostatně (AI mu dala druh služba → 518), vyšel účet
 * 518 na dokladu záporně a drobný majetek zůstal v ceně před slevou (PF Amazon:
 * adaptér 197,39 EUR na 501, doprava 5,59 EUR na 518, Aktionsrabatt −7,24 EUR na 518).
 *
 * Pravidlo:
 *   - cíl = kladné položky se STEJNOU sazbou DPH (sleva zlevňuje plnění své sazby);
 *     když žádná taková není, všechny kladné položky,
 *   - sleva, která sama mluví o dopravě („Sleva na dopravné"), jde jen na dopravu
 *     (doprava, doručení, poštovné, Versand, shipping, poplatek…),
 *   - sleva přesně ve výši dopravy jde na tu dopravu (Amazon: „Aktionsrabatt" −3,69
 *     proti „Versandkosten" 3,69 = doprava zdarma),
 *   - sleva, která jmenuje položku („Sleva AlzaPlus+"), jde na tu položku,
 *   - jinak jde na zboží: doprava, poplatky ani služby (druh výdaje service) slevu
 *     nenesou, pokud mezi cíli zůstane i něco jiného („Aktionsrabatt" −7,24 u adaptéru
 *     197,39 a dopravy 5,59),
 *   - zboží vrácené dobropisem navázaným na fakturu slevu na zboží nenese (vrací se
 *     v plné ceně), u částečné vratky jen zbylá část ({@see withReturns()}),
 *   - mezi cíle se rozpočítá poměrem jejich základu.
 *
 * Slevový řádek s vlastním adresným účtem (ručně zvolený expense_account_code) je
 * vědomá volba účetní a nechává se být. Záporný řádek bez slevového textu (vratka,
 * záloha) slevou není.
 */
final class PurchaseDiscountAllocation
{
    // Bez hranice slova: „Aktionsrabatt" má rabat uprostřed složeniny.
    private const DISCOUNT_PATTERN = '/(slev[aěyuo]|rabat|nachlass|discount|kup[oó]n|coupon|voucher|gutschein|promo)/iu';
    private const SHIPPING_PATTERN = '/(doprav|doru[čc]en|po[sš]tovn|baln[eéý]|dob[ií]rk|versand|shipping|delivery|fracht|poplat|geb[uü]hr|\bporto\b|\bfee\b)/iu';
    /** Slova, která v textu slevy nic nejmenují (Alza: „Nehmotný produkt - Sleva na zboží"). */
    private const GENERIC_WORDS = ['nehmotný', 'produkt', 'produkty', 'zboží', 'položka', 'položku', 'dárkový', 'celý', 'nákup',
        'objednávka', 'objednávku', 'artikel', 'bestellung', 'order', 'total', 'items'];

    public static function isDiscountDescription(string $description): bool
    {
        return preg_match(self::DISCOUNT_PATTERN, $description) === 1;
    }

    public static function isShippingOrFee(string $description): bool
    {
        return preg_match(self::SHIPPING_PATTERN, $description) === 1;
    }

    /**
     * @param list<array<string,mixed>> $items řádky s klíči id, description, total_without_vat,
     *                                         vat_rate_snapshot a volitelně expense_account_code
     * @return array<int, array<int, float>> id slevového řádku => (id cílové položky => podíl 0..1)
     */
    public static function allocate(array $items): array
    {
        $positive = [];
        foreach ($items as $item) {
            if ((float) $item['total_without_vat'] > 0.0) {
                $positive[(int) $item['id']] = $item;
            }
        }
        if ($positive === []) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if ((float) $item['total_without_vat'] >= 0.0) {
                continue;
            }
            $description = (string) ($item['description'] ?? '');
            if (!self::isDiscountDescription($description)) {
                continue;
            }
            if (trim((string) ($item['expense_account_code'] ?? '')) !== '') {
                continue;
            }

            $rate = round((float) ($item['vat_rate_snapshot'] ?? 0), 2);
            $targets = array_filter(
                $positive,
                static fn (array $p): bool => round((float) ($p['vat_rate_snapshot'] ?? 0), 2) === $rate,
            );
            if ($targets === []) {
                $targets = $positive;
            }
            $shipping = array_filter($targets, static fn (array $p): bool => self::isShippingOrFee((string) ($p['description'] ?? '')));
            $discountCents = (int) round(abs((float) $item['total_without_vat']) * 100);
            $sameAsShipping = array_filter(
                $shipping,
                static fn (array $p): bool => (int) round((float) $p['total_without_vat'] * 100) === $discountCents,
            );
            $named = self::namedTargets($description, array_diff_key($targets, $shipping));
            // Váha cíle = základ položky; u slevy na zboží bez části, kterou dodavatel
            // vzal zpět dobropisem (returned_without_vat, viz withReturns()).
            $weightOf = static fn (array $p): float => (float) $p['total_without_vat'];
            if (self::isShippingOrFee($description)) {
                $targets = $shipping !== [] ? $shipping : $targets;
            } elseif ($sameAsShipping !== []) {
                // Sleva přesně ve výši dopravy („Aktionsrabatt" = doprava zdarma).
                $targets = [array_key_first($sameAsShipping) => $sameAsShipping[array_key_first($sameAsShipping)]];
            } elseif ($named !== []) {
                $targets = $named;
            } else {
                if ($shipping !== [] && count($shipping) < count($targets)) {
                    $targets = array_diff_key($targets, $shipping);
                }
                // Sleva bez určení míří na zboží, ne na služby vedle něj (členství,
                // instalace), pokud na dokladu nějaké zboží je.
                $goods = array_filter($targets, static fn (array $p): bool => ($p['expense_kind'] ?? null) !== 'service');
                if ($goods !== [] && count($goods) < count($targets)) {
                    $targets = $goods;
                }
                // Zboží vrácené dobropisem slevu nenese: dárkový šek zůstal u toho, co si
                // kupující nechal. Vrácený switch jde zpět v plné ceně, sleva patří routeru.
                $weightOf = static fn (array $p): float => max(
                    0.0,
                    (float) $p['total_without_vat'] - (float) ($p['returned_without_vat'] ?? 0.0),
                );
                $kept = array_filter($targets, static fn (array $p): bool => $weightOf($p) > 0.004);
                if ($kept !== []) {
                    $targets = $kept;
                } else {
                    $weightOf = static fn (array $p): float => (float) $p['total_without_vat'];
                }
            }

            $base = 0.0;
            foreach ($targets as $t) {
                $base += $weightOf($t);
            }
            if ($base <= 0.0) {
                continue;
            }
            $shares = [];
            foreach ($targets as $id => $t) {
                $shares[(int) $id] = $weightOf($t) / $base;
            }
            $out[(int) $item['id']] = $shares;
        }

        return $out;
    }

    /**
     * Doplní položkám `returned_without_vat` = kolik ze základu položky vzal dodavatel
     * zpět dobropisy navázanými na tuto fakturu (parent_purchase_invoice_id). Řádek
     * dobropisu se páruje na položku téhož popisu a sazby; vrácená část je nejvýš
     * celý základ položky (částečná vratka = jen vrácená část).
     *
     * @param list<array<string,mixed>> $items řádky faktury (id, description, vat_rate_snapshot, total_without_vat)
     * @return list<array<string,mixed>>
     */
    public static function withReturns(\PDO $pdo, int $supplierId, int $purchaseInvoiceId, array $items): array
    {
        $stmt = $pdo->prepare(
            "SELECT pii.description, pii.vat_rate_snapshot, pii.total_without_vat
               FROM purchase_invoice_items pii
               JOIN purchase_invoices cn ON cn.id = pii.purchase_invoice_id
              WHERE cn.supplier_id = ? AND cn.parent_purchase_invoice_id = ?
                AND cn.document_kind = 'credit_note' AND cn.status NOT IN ('cancelled', 'draft')
                AND pii.total_without_vat < 0"
        );
        $stmt->execute([$supplierId, $purchaseInvoiceId]);
        $pool = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $key = self::returnKey((string) $row['description'], (float) $row['vat_rate_snapshot']);
            $pool[$key] = ($pool[$key] ?? 0) + (int) round(abs((float) $row['total_without_vat']) * 100);
        }
        if ($pool === []) {
            return $items;
        }
        foreach ($items as $i => $item) {
            $net = (int) round((float) $item['total_without_vat'] * 100);
            $key = self::returnKey((string) ($item['description'] ?? ''), (float) ($item['vat_rate_snapshot'] ?? 0));
            if ($net <= 0 || ($pool[$key] ?? 0) <= 0) {
                continue;
            }
            $returned = min($net, $pool[$key]);
            $pool[$key] -= $returned;
            $items[$i]['returned_without_vat'] = $returned / 100;
        }
        return $items;
    }

    private static function returnKey(string $description, float $rate): string
    {
        return mb_strtolower(trim($description)) . '|' . number_format($rate, 2, '.', '');
    }

    /**
     * Položky, které sleva jmenuje: slovo z textu slevy (aspoň 5 znaků, mimo obecná
     * slova) se najde v popisu položky. „Sleva AlzaPlus+" patří k členství AlzaPlus+,
     * ne poměrně ke všemu zboží na dokladu.
     *
     * @param array<int,array<string,mixed>> $candidates
     * @return array<int,array<string,mixed>>
     */
    private static function namedTargets(string $description, array $candidates): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($description)) ?: [];
        $words = array_filter(
            $words,
            static fn (string $w): bool => mb_strlen($w) >= 5 && !self::isDiscountDescription($w)
                && !in_array($w, self::GENERIC_WORDS, true),
        );
        if ($words === []) {
            return [];
        }
        return array_filter($candidates, static function (array $p) use ($words): bool {
            $text = mb_strtolower((string) ($p['description'] ?? ''));
            foreach ($words as $w) {
                if (str_contains($text, $w)) {
                    return true;
                }
            }
            return false;
        });
    }

    /**
     * Základ položek po rozpuštění slev: slevový řádek zmizí a jeho částka se přičte
     * cílovým položkám (v haléřích, zbytek ze zaokrouhlení dostane největší cíl, takže
     * Σ sedí na haléř). Ostatní řádky zůstávají, jak jsou.
     *
     * @param list<array<string,mixed>> $items
     * @return array<int,float> id položky => základ po slevě
     */
    public static function netsAfterDiscounts(array $items): array
    {
        $nets = [];
        foreach ($items as $item) {
            $nets[(int) $item['id']] = (int) round((float) $item['total_without_vat'] * 100);
        }
        foreach (self::allocate($items) as $discountId => $shares) {
            $discount = $nets[$discountId];
            unset($nets[$discountId]);
            $assigned = 0;
            $biggest = null;
            foreach ($shares as $targetId => $share) {
                $part = (int) round($discount * $share);
                $nets[$targetId] += $part;
                $assigned += $part;
                if ($biggest === null || $share > $shares[$biggest]) {
                    $biggest = $targetId;
                }
            }
            if ($biggest !== null && $assigned !== $discount) {
                $nets[$biggest] += $discount - $assigned;
            }
        }

        return array_map(static fn (int $c): float => $c / 100, $nets);
    }
}
