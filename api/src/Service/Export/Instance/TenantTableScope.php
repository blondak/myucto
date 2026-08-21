<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export\Instance;

/**
 * Jak se jedna tabulka omezí na JEDNU firmu — WHERE + jeho parametry + klíč
 * pro stránkování.
 *
 * `where` nikdy není prázdný řetězec ani `1 = 1`: tabulka, u které se filtr
 * nepodařilo odvodit, se {@see TenantScopeResolver} vůbec nevrátí (default deny).
 * Díky tomu neexistuje cesta, jak se do exportu dostane neomezený SELECT.
 */
final readonly class TenantTableScope
{
    /**
     * @param list<mixed>  $params    parametry pro `where` (ve stejném pořadí)
     * @param list<string> $columns   sloupce k exportu (bez redigovaných)
     * @param list<string> $redacted  vynechané sloupce (do manifestu)
     * @param ?string      $keysetPk  jednosloupcový PK pro keyset stránkování
     * @param string       $orderBy   řadicí klíč (fallback LIMIT/OFFSET)
     * @param int          $depth     0 = přímý supplier_id, N = přes N rodičů
     * @param string       $via       lidský popis odvození (do manifestu)
     */
    public function __construct(
        public string $table,
        public string $where,
        public array $params,
        public array $columns,
        public array $redacted,
        public ?string $keysetPk,
        public string $orderBy,
        public int $depth,
        public string $via,
    ) {}
}
