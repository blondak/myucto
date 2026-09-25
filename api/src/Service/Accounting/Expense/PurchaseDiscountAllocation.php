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
            }

            $base = 0.0;
            foreach ($targets as $t) {
                $base += (float) $t['total_without_vat'];
            }
            if ($base <= 0.0) {
                continue;
            }
            $shares = [];
            foreach ($targets as $id => $t) {
                $shares[(int) $id] = (float) $t['total_without_vat'] / $base;
            }
            $out[(int) $item['id']] = $shares;
        }

        return $out;
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
