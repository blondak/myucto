<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Service\Migration\Shared\VatReturnLineClassifier;

final class AbraVatMapper
{
    /** @return array{code:?string,review:bool,deduction:string,reverse:bool,fixed_asset:bool} */
    public static function map(string $sourceCode, string $evidence, float $rate, float $base, float $vat): array
    {
        $sourceCode = trim($sourceCode);
        $hasVat = abs($vat) >= 0.005;
        $outside = $sourceCode === '' || in_array($sourceCode, ['000U', '000P'], true);
        $empty = ['code' => null, 'review' => in_array($evidence, ['faktura-vydana', 'prodejka'], true) && $hasVat,
            'deduction' => 'none', 'reverse' => false, 'fixed_asset' => false];
        if ($outside) {
            return $empty;
        }
        $compactCode = preg_replace('/\s+/u', '', $sourceCode);
        if (preg_match('/^\d{2}(?:[-,]\d{2})*$/D', $compactCode) !== 1) {
            return ['code' => null, 'review' => true, 'deduction' => 'none', 'reverse' => false, 'fixed_asset' => false];
        }
        $lines = array_values(array_unique(array_map('intval', preg_split('/[-,]/', $compactCode))));
        sort($lines);
        if (in_array($evidence, ['faktura-vydana', 'prodejka'], true)) {
            $class = VatReturnLineClassifier::saleFromLineSet($lines);
            if ($class === null) {
                return ['code' => null, 'review' => true, 'deduction' => 'none', 'reverse' => false, 'fixed_asset' => false];
            }
            if (!$class['in_return']) {
                return $empty;
            }
            if ($class['domestic']) {
                $code = match (true) {
                    abs($rate - 21.0) < 0.01 => '1',
                    abs($rate - 12.0) < 0.01 => '2',
                    default => null,
                };
                if ($class['asset_sale']) {
                    $code = VatReturnLineClassifier::assetSaleCode($rate);
                }
                return ['code' => $code, 'review' => $code === null || (!$hasVat && abs($base) >= 0.005),
                    'deduction' => 'none', 'reverse' => false, 'fixed_asset' => false];
            }
            return ['code' => $class['code'], 'review' => $hasVat, 'deduction' => 'none',
                'reverse' => false, 'fixed_asset' => false];
        }

        $class = VatReturnLineClassifier::purchaseFromLineSet($lines, str_contains($sourceCode, 'KR'));
        if ($class === null) {
            return ['code' => null, 'review' => true, 'deduction' => 'none', 'reverse' => false, 'fixed_asset' => false];
        }
        if (!$class['in_return']) {
            return $empty;
        }
        if ($class['reverse']) {
            return ['code' => $class['code'], 'review' => true, 'deduction' => $class['deduction'],
                'reverse' => true, 'fixed_asset' => $class['fixed_asset']];
        }
        $code = match (true) {
            abs($rate - 21.0) < 0.01 => '40',
            abs($rate - 12.0) < 0.01 => '41',
            default => null,
        };
        return ['code' => $code, 'review' => $code === null || (!$hasVat && abs($base) >= 0.005),
            'deduction' => $class['deduction'], 'reverse' => false, 'fixed_asset' => $class['fixed_asset']];
    }
}
