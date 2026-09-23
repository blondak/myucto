<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

/**
 * Maska účtů pravidla dimenze: předpony oddělené čárkou (nebo mezerou), vyloučení
 * začíná vykřičníkem. `5, 6, !59, !69` = třídy 5 a 6 kromě daně z příjmů.
 *
 * Účet odpovídá, když začíná některou zahrnutou předponou a žádnou vyloučenou.
 * Specifičnost = délka nejdelší zahrnuté předpony, která účtu odpovídá; při souběhu
 * pravidel téhož typu rozhoduje o výchozí hodnotě to specifičtější (`518` před `5`).
 */
final class DimensionAccountMask
{
    private const TOKEN = '/^[0-9A-Za-z.]{1,10}$/';

    /**
     * @param list<string> $include
     * @param list<string> $exclude
     */
    private function __construct(
        public readonly array $include,
        public readonly array $exclude,
    ) {}

    /** @throws DimensionException neplatná nebo prázdná maska */
    public static function parse(string $mask): self
    {
        $include = [];
        $exclude = [];
        foreach (preg_split('/[\s,;]+/', trim($mask), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $negated = str_starts_with($token, '!');
            $prefix = strtoupper($negated ? substr($token, 1) : $token);
            if (preg_match(self::TOKEN, $prefix) !== 1) {
                throw new DimensionException(
                    'invalid_account_mask',
                    'Maska účtů: „' . $token . '" není předpona účtu (povolené znaky 0-9, A-Z a tečka, nejvýš 10).',
                );
            }
            if ($negated) {
                $exclude[$prefix] = $prefix;
            } else {
                $include[$prefix] = $prefix;
            }
        }
        if ($include === []) {
            throw new DimensionException('invalid_account_mask', 'Maska účtů musí obsahovat aspoň jednu předponu účtu (např. 5, 6).');
        }
        sort($include, SORT_STRING);
        sort($exclude, SORT_STRING);
        return new self(array_values($include), array_values($exclude));
    }

    public function normalized(): string
    {
        return implode(', ', [...$this->include, ...array_map(static fn (string $p): string => '!' . $p, $this->exclude)]);
    }

    public function matches(string $accountCode): bool
    {
        return $this->specificity($accountCode) > 0;
    }

    /** Délka nejdelší zahrnuté předpony, které účet odpovídá; 0 = neodpovídá. */
    public function specificity(string $accountCode): int
    {
        // Sloupec kódu účtu má collation _ci, porovnání v SQL je bez ohledu na velikost
        // písmen — PHP se musí chovat stejně, jinak by audit a vynucení nesouhlasily.
        $accountCode = strtoupper($accountCode);
        foreach ($this->exclude as $prefix) {
            if (str_starts_with($accountCode, $prefix)) {
                return 0;
            }
        }
        $best = 0;
        foreach ($this->include as $prefix) {
            if (str_starts_with($accountCode, $prefix)) {
                $best = max($best, strlen($prefix));
            }
        }
        return $best;
    }

    /**
     * Podmínka nad sloupcem s kódem účtu (bez vedoucího AND). Předpony obsahují jen
     * znaky 0-9, A-Z a tečku, takže LIKE nepotřebuje escapování.
     *
     * @return array{0:string,1:list<string>}
     */
    public function sql(string $column): array
    {
        $include = implode(' OR ', array_fill(0, count($this->include), "{$column} LIKE ?"));
        $params = array_map(static fn (string $p): string => $p . '%', $this->include);
        $sql = "({$include})";
        foreach ($this->exclude as $prefix) {
            $sql .= " AND {$column} NOT LIKE ?";
            $params[] = $prefix . '%';
        }
        return ['(' . $sql . ')', $params];
    }
}
