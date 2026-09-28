<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

final class AbraAssetMapper
{
    /** @return array<string,mixed> */
    public function map(array $row): array
    {
        $kind = AbraSource::reference($row['druhK'] ?? null);
        $type = match ($kind) {
            'druhMaj.drobny' => 'small_asset',
            'druhMaj.hmDl' => 'asset',
            default => '',
        };
        $key = AbraSource::sourceKey($row);
        $number = trim((string) ($row['kod'] ?? ''));
        $name = trim((string) ($row['nazev'] ?? ''));
        $price = AbraSource::number($row['cena'] ?? null);
        $acquired = AbraSource::date($row['datKoupe'] ?? null);
        $started = AbraSource::date($row['datZar'] ?? null);
        $disposed = AbraSource::date($row['datUdalVyr'] ?? null);
        $account = AbraSource::account($row['primarniUcet'] ?? null);
        $accumulated = AbraSource::account($row['opravnyUcet'] ?? null);
        $blockers = [];
        if ($type === '') $blockers[] = 'asset_kind_unsupported';
        if ($key === '' || $number === '' || $name === '' || $price === null || $price <= 0 || $acquired === null
            || $started === null || strlen($number) > ($type === 'asset' ? 30 : 40)) {
            $blockers[] = 'asset_card_invalid';
        }
        if ($disposed !== null && $acquired !== null && $disposed < $acquired) $blockers[] = 'asset_disposal_invalid';
        if ($type === 'asset' && ($account === '' || strlen($account) > 10
            || ($accumulated !== '' && strlen($accumulated) > 10))) $blockers[] = 'asset_account_invalid';
        return [
            'type' => $type,
            'source_key' => $key,
            'source_hash' => AbraSource::hash($row),
            'number' => $number,
            'name' => mb_substr($name, 0, 255),
            'description' => trim((string) ($row['popis'] ?? $row['poznam'] ?? '')),
            'price' => $price !== null ? round($price, 2) : 0.0,
            'acquired' => $acquired,
            'started' => $started,
            'disposed' => $disposed,
            'account' => $account,
            'accumulated' => $accumulated,
            'blockers' => $blockers,
        ];
    }
}
