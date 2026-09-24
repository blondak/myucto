<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * Řazení seznamu bankovních pohybů podle sloupce — sdílené detailem výpisu
 * ({@see \MyInvoice\Action\Bank\BankStatementAction::detail()}) a záložkami
 * „K zaúčtování" / „Všechny pohyby"
 * ({@see \MyInvoice\Repository\BankPostingSuggestionRepository::paginateUnposted()}).
 *
 * Z požadavku se do SQL nikdy nedostane nic jiného než výraz z whitelistu. Seznam
 * je stránkovaný na serveru, takže se řadí v SQL, ne jen načtená stránka. Druhý
 * klíč `bt.id` drží pořadí stabilní i při shodných hodnotách, jinak by se řádky
 * mezi stránkami přelévaly.
 *
 * Výrazy počítají s aliasy dotazů obou míst: `bt` = bank_transactions,
 * `i` = spárovaná vystavená faktura, `p` = spárovaná přijatá faktura,
 * `bs` = výpis (jen tam, kde je joinovaný — proto `account` povolí jen volající,
 * který ho má).
 */
final class BankTransactionSort
{
    public const KEYS = ['posted_at', 'amount', 'variable_symbol', 'counterparty', 'invoice', 'status', 'posting', 'account'];

    /**
     * @param array<string,mixed> $query       query parametry `sort` a `direction`
     * @param list<string>        $allowedKeys podmnožina {@see KEYS}, kterou volající umí
     * @return array{key:string, dir:string}
     */
    public static function fromQuery(array $query, array $allowedKeys, string $defaultKey = 'posted_at', string $defaultDir = 'asc'): array
    {
        $key = isset($query['sort']) && is_string($query['sort']) ? $query['sort'] : '';
        if (!in_array($key, $allowedKeys, true) || !in_array($key, self::KEYS, true)) {
            $key = $defaultKey;
        }
        $dir = isset($query['direction']) && is_string($query['direction']) ? strtolower($query['direction']) : '';
        if ($dir !== 'asc' && $dir !== 'desc') {
            $dir = $defaultDir === 'desc' ? 'desc' : 'asc';
        }
        return ['key' => $key, 'dir' => $dir];
    }

    /**
     * ORDER BY bez klíčového slova. `status` = stav párování, `posting` = zda má
     * pohyb aktivní zápis v deníku (stejný predikát jako filtr zaúčtování).
     */
    public static function orderBySql(string $key, string $dir, int $supplierId): string
    {
        $dir = $dir === 'desc' ? 'DESC' : 'ASC';
        $expr = match ($key) {
            'amount'          => 'bt.amount',
            'variable_symbol' => "NULLIF(bt.variable_symbol, '')",
            'counterparty'    => "COALESCE(NULLIF(bt.counterparty_name, ''), NULLIF(bt.counterparty_account, ''))",
            'invoice'         => "COALESCE(i.varsymbol, NULLIF(p.vendor_invoice_number, ''), p.varsymbol)",
            'status'          => 'bt.match_status',
            'posting'         => BankTransactionPostingScope::existsSql($supplierId, 'bt.id'),
            'account'         => 'bs.account_number',
            default           => 'bt.posted_at',
        };
        // Prázdné hodnoty (bez VS, bez faktury) vždy na konec — v obou směrech;
        // jinak by vzestupné řazení podle faktury začínalo stránkou pomlček.
        $nullsLast = $key === 'posted_at' || $key === 'amount' || $key === 'posting'
            ? ''
            : "({$expr}) IS NULL, ";
        $secondary = $key === 'posted_at' ? '' : ", bt.posted_at {$dir}";
        return "{$nullsLast}{$expr} {$dir}{$secondary}, bt.id {$dir}";
    }
}
