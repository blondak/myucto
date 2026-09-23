<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

/** Ověření vysvětlitelného rozdílu mezi korunovým celkem a daňovým základem cizoměnového dokladu. */
final class StereoNxForeignCurrencyCheck
{
    /** @param array<string,mixed> $header @param list<array<string,mixed>> $items @return list<string> */
    public static function reviewCodes(array $header, array $items, StereoNxVat $vat): array
    {
        if ($items === [] || ($header['ZpracovatDPH'] ?? null) !== true
            || ($header['CenySDPH'] ?? null) !== false || ($header['Stornovano'] ?? null) !== false
            || ($header['TypDokladu'] ?? null) !== 'F' || !self::isZero($header['Zalohy'] ?? null)) return [];
        $total = self::number($header['Celkem'] ?? null);
        $ownTotal = self::number($header['CelkemVlastni'] ?? null);
        $rate = self::number($header['Kurz'] ?? null);
        $units = self::number($header['KurzMn'] ?? null);
        if ($total === null || $ownTotal === null || $rate === null || $units === null
            || $total <= 0 || $rate <= 0 || $units <= 0) return [];

        $agenda = $header['Agenda'] ?? null;
        if (!in_array($agenda, ['VF', 'PF'], true)) return [];
        $slot = $agenda === 'VF' ? '0' : 'z';
        $baseField = $slot === '0' ? 'BezDane' : 'ZaklDPHz';
        $sourceBase = self::number($header[$baseField] ?? null);
        if ($sourceBase === null || $sourceBase <= 0) return [];
        foreach (['ZaklDPHz', 'ZaklDPHs', 'ZaklDPHt', 'BezDane'] as $field) {
            if ($field === $baseField) continue;
            if (!self::isZero($header[$field] ?? null)) return [];
        }
        foreach (['DPHz', 'DPHs', 'DPHt'] as $field) {
            if ($agenda === 'PF' && $field === 'DPHz') continue;
            if (!self::isZero($header[$field] ?? null)) return [];
        }
        try {
            if ($agenda === 'VF') {
                $classification = $vat->sale(trim((string) ($header['TypDPH'] ?? '')), $slot);
                if (!$classification['in_return']) return [];
            } else {
                $classification = $vat->purchase(trim((string) ($header['TypDPH'] ?? '')), $slot);
                if (!$classification['reverse']) return [];
                $tax = self::number($header['DPHz'] ?? null);
                $rateVat = self::number($header['SazbaDPHz'] ?? null);
                if ($tax === null || $rateVat === null || $rateVat <= 0
                    || !self::same($tax, round($sourceBase * $rateVat / 100, 2))) return [];
            }
        } catch (StereoNxException) {
            return [];
        }

        $foreignLines = 0.0;
        $baseLines = 0.0;
        $taxLines = 0.0;
        foreach ($items as $item) {
            if (strtolower(trim((string) ($item['TypSazby'] ?? ''))) !== $slot
                || ($item['Stornovano'] ?? null) !== false
                || ($item['Zaloha'] ?? null) !== false
                || ($item['ZalohaProforma'] ?? null) !== false
                || !self::isZero($item['ProcSlevy'] ?? null)) return [];
            $quantity = self::number($item['Mnozstvi'] ?? null);
            $foreignUnit = self::number($item['JednCenaC'] ?? null);
            $ownUnit = self::number($item['JednCena'] ?? null);
            $base = self::number($item['ZakladDPH'] ?? null);
            $tax = self::number($item['CelkemDPH'] ?? null);
            if ($quantity === null || $foreignUnit === null || $ownUnit === null || $base === null || $tax === null
                || $quantity <= 0 || $foreignUnit < 0 || $base < 0 || $tax < 0
                || !self::same($quantity * $ownUnit, $base)
                || ($agenda === 'VF' && !self::same($tax, 0))) return [];
            $foreignLines += $quantity * $foreignUnit;
            $baseLines += $base;
            $taxLines += $tax;
        }
        $headerTax = $agenda === 'PF' ? self::number($header['DPHz'] ?? null) : null;
        if (!self::same($foreignLines, $total) || !self::same($baseLines, $sourceBase)
            || ($agenda === 'PF' && ($headerTax === null || !self::same($taxLines, $headerTax)))) return [];
        $converted = round($total * $rate / $units, 2);
        if (!self::same($ownTotal, $converted)) {
            return self::same($sourceBase, $ownTotal) ? ['foreign_currency_rate_mismatch'] : [];
        }
        return self::same($sourceBase, $ownTotal) ? [] : ['foreign_currency_vat_base_mismatch'];
    }

    private static function number(mixed $value): ?float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) return null;
        return (float) $value;
    }

    private static function isZero(mixed $value): bool
    {
        $number = self::number($value);
        return $number !== null && self::same($number, 0);
    }

    private static function same(float $a, float $b): bool
    {
        return abs(round($a, 2) - round($b, 2)) <= 0.011;
    }
}
