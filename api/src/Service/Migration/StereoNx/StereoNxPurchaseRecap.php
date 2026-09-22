<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

/**
 * Náhradní položky přijatého dokladu z rekapitulace SPFH (jen CZK).
 * Hodnoty řádků jsou zdrojové: samovyměřená daň není daní placenou dodavateli.
 * Výsledkem je plán, nikoli zaúčtovaný doklad. Neznámé příznaky zůstávají k posouzení.
 */
final class StereoNxPurchaseRecap
{
    public function __construct(private readonly StereoNxVat $vat) {}

    /** @param array<string,mixed> $header
     * @return array<string,mixed> */
    public function plan(array $header): array
    {
        if (($header['Agenda'] ?? null) !== 'PF' || ($header['TypDokladu'] ?? null) !== 'F') {
            throw new StereoNxException('purchase_kind_unsupported', 'Rekapitulaci lze převést jen pro přijatou fakturu.');
        }
        if (($header['Stornovano'] ?? null) !== false) {
            throw new StereoNxException('purchase_cancelled', 'Stornovaný nebo neověřený doklad nelze převést rekapitulací.');
        }
        if (!in_array($header['Mena'] ?? null, ['Kč', 'CZK'], true)
            || self::number($header, 'Kurz') !== 1.0 || self::number($header, 'KurzMn') !== 1.0) {
            throw new StereoNxException('purchase_currency_unsupported', 'Cizoměnová rekapitulace vyžaduje samostatné mapování.');
        }
        if (self::number($header, 'Zalohy') !== 0.0) {
            throw new StereoNxException('purchase_advance_unsupported', 'Odpočet zálohy nelze nahradit běžnou položkou.');
        }
        if (!array_key_exists('CenySDPH', $header) || (!is_bool($header['CenySDPH']) && $header['CenySDPH'] !== null)) {
            throw new StereoNxException('price_mode_invalid', 'Neplatný příznak cen s DPH.');
        }
        $pricesIncludeVat = $header['CenySDPH'];
        $review = $pricesIncludeVat === null ? ['price_mode_unassigned'] : [];
        if (!is_bool($header['ZpracovatDPH'] ?? null)) $review[] = 'vat_participation_unassigned';
        elseif ($header['ZpracovatDPH'] === false) $review[] = 'vat_participation_disabled';
        $items = [];
        $reverse = null;
        $code = trim((string) ($header['TypDPH'] ?? ''));
        $description = trim((string) ($header['Text'] ?? ''));
        foreach (['z', 's', 't', '0'] as $slot) {
            $base = self::money($header, $slot === '0' ? 'BezDane' : 'ZaklDPH' . $slot);
            $sourceVat = $slot === '0' ? 0.0 : self::money($header, 'DPH' . $slot);
            if ($base === 0.0 && $sourceVat === 0.0) continue;
            $rate = $slot === '0' ? 0.0 : self::number($header, 'SazbaDPH' . $slot);
            if ($rate < 0.0 || $rate > 100.0 || ($rate === 0.0 && $sourceVat !== 0.0)) {
                throw new StereoNxException('purchase_rate_invalid', 'Neplatná nebo chybějící sazba rekapitulace.');
            }
            $class = $this->vat->purchase($code, $slot);
            if ($class['in_return']) {
                if ($reverse !== null && $reverse !== $class['reverse']) {
                    throw new StereoNxException('purchase_mixed_vat', 'Smíšené samovyměření a tuzemská daň vyžadují samostatné mapování.');
                }
                $reverse = $class['reverse'];
            }
            if ($class['reverse'] && abs(round($base * $rate / 100, 2) - $sourceVat) >= 0.005) {
                $review[] = 'self_assessment_amount_mismatch';
            }
            $chargedVat = $class['reverse'] ? 0.0 : $sourceVat;
            $gross = round($base + $chargedVat, 2);
            if ($pricesIncludeVat === null && $chargedVat !== 0.0) {
                throw new StereoNxException('price_mode_required', 'Doklad s dodavatelskou DPH nemá určený režim cen.');
            }
            $items[] = [
                'source_slot' => $slot,
                'description' => $description,
                'quantity' => 1.0,
                'unit_price' => $pricesIncludeVat === true ? $gross : $base,
                'unit_price_without_vat' => $pricesIncludeVat === true ? $gross : $base,
                'vat_rate_percent' => $rate,
                'vat_rate_snapshot' => $rate,
                'total_without_vat' => $base,
                'total_vat' => $chargedVat,
                'total_with_vat' => $gross,
                'vat_classification_code' => $class['code'],
                'vat_deduction' => $class['deduction'],
                'source_return_lines' => $class['source_lines'],
                'source_self_assessed_vat' => $class['reverse'] ? $sourceVat : 0.0,
            ];
        }
        if ($items === []) {
            throw new StereoNxException('purchase_empty_recap', 'Rekapitulace neobsahuje žádnou nenulovou položku.');
        }
        $base = round(array_sum(array_column($items, 'total_without_vat')), 2);
        $vat = round(array_sum(array_column($items, 'total_vat')), 2);
        $total = round($base + $vat, 2);
        $rounding = self::money($header, 'Zaokrouhleni');
        $sourceTotal = self::money($header, 'Celkem');
        if ((int) round(($total + $rounding) * 100) !== (int) round($sourceTotal * 100)) {
            throw new StereoNxException('purchase_recap_mismatch', 'Součet rekapitulace a uloženého zaokrouhlení nesouhlasí s celkem dokladu.');
        }
        return ['origin' => 'vat_recap', 'items' => $items,
            'prices_include_vat' => $pricesIncludeVat, 'reverse_charge' => $reverse ?? false,
            'source_vat_participation' => $header['ZpracovatDPH'] ?? null,
            'review_codes' => $review, 'requires_draft' => $review !== [],
            'total_without_vat' => $base, 'total_vat' => $vat,
            'total_with_vat' => $total, 'rounding' => $rounding, 'source_total_with_vat' => $sourceTotal];
    }

    /** @param array<string,mixed> $row */
    private static function number(array $row, string $key): float
    {
        $value = $row[$key] ?? null;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs($value) > 1.0e12) {
            throw new StereoNxException('purchase_amount_invalid', 'Chybějící nebo neplatné číselné pole rekapitulace: ' . $key);
        }
        return (float) $value;
    }

    /** @param array<string,mixed> $row */
    private static function money(array $row, string $key): float
    {
        return round(self::number($row, $key), 2);
    }
}
