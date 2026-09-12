<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Repository\StatementDefinitionRepository;
use MyInvoice\Repository\StatementOverrideRepository;

/**
 * Jediné místo, kde vzniká mapa účtů pro výkaz konkrétní firmy:
 *
 *   globální mapa verze (`statement_account_map`)
 *   + u účelové VZZ přiřazení nákladů funkci (`statement_function_map`)
 *   + výjimky firmy (`statement_account_overrides`).
 *
 * Používá ji každá cesta, která výkaz staví: {@see FinancialStatementService} (rozvaha,
 * obě VZZ a přes ni PDF/XLSX export, příloha DPPO, měsíční report, uzávěrkový balík,
 * křížové kontroly) i {@see EntityCategoryService} (aktiva netto pro kategorii ÚJ). Kdyby
 * si některá cesta skládala mapu sama, výjimka by v jednom výkazu platila a v jiném ne.
 *
 * Pravidlo přednosti: nejdelší prefix vyhrává ({@see StatementMapper::map()}), při stejné
 * délce vyhrává výjimka firmy — globální záznam se stejným prefixem se pro pokrytou stranu
 * zůstatku zahodí. Když výjimka pokrývá jen jednu stranu saldového účtu (debit/credit),
 * druhá strana se převezme z toho, co by pro prefix platilo bez výjimky, a překlíčuje se
 * na prefix výjimky. Jinak by ji mapper při stejné délce prefixu vůbec nenamapoval
 * a guard {@see StatementMapper::noCompensationPrefixes()} by výkaz odmítl.
 *
 * Náhled dopadu neuložených výjimek jde přes {@see simulate()}: po dobu volání se místo
 * uložených výjimek firmy použije předaná sada, nic se nezapisuje do databáze.
 */
final class StatementMapResolver
{
    /** @var array<int, array<int, list<array<string,mixed>>>> supplier → verze → výjimky */
    private array $overlay = [];

    public function __construct(
        private readonly StatementDefinitionRepository $definitions,
        private readonly StatementOverrideRepository $overrides,
    ) {}

    /**
     * Sloučená mapa pro výkaz firmy.
     *
     * @param array<string,mixed> $version řádek statement_versions (id, statement_type)
     * @return list<array<string,mixed>>
     */
    public function accountMap(array $version, int $supplierId): array
    {
        $versionId = (int) $version['id'];
        $base = array_map(
            static fn (array $m): array => $m + ['source' => 'global'],
            $this->definitions->accountMap($versionId),
        );
        if ((string) ($version['statement_type'] ?? '') === FinancialStatementService::TYPE_PURPOSE) {
            // Řádky A./B./C. globální mapu nemají — funkce, které náklad slouží, není
            // vlastnost účtu. Doplní se z per-firma mapy a dál se s ní zachází stejně.
            foreach ($this->definitions->functionMap($supplierId) as $m) {
                $base[] = $m + ['source' => 'function'];
            }
        }

        return self::applyOverrides($base, $this->overridesFor($supplierId, $versionId));
    }

    /** @return list<array<string,mixed>> výjimky firmy pro verzi (uložené, případně simulované) */
    public function overridesFor(int $supplierId, int $versionId): array
    {
        return $this->overlay[$supplierId][$versionId] ?? $this->overrides->forVersion($supplierId, $versionId);
    }

    /**
     * Spustí `$fn` tak, že firma má pro verzi místo uložených výjimek sadu `$overrides`.
     *
     * @template T
     * @param list<array<string,mixed>> $overrides
     * @param callable(): T $fn
     * @return T
     */
    public function simulate(int $supplierId, int $versionId, array $overrides, callable $fn): mixed
    {
        $previous = $this->overlay[$supplierId][$versionId] ?? null;
        $this->overlay[$supplierId][$versionId] = array_values($overrides);
        try {
            return $fn();
        } finally {
            if ($previous === null) {
                unset($this->overlay[$supplierId][$versionId]);
            } else {
                $this->overlay[$supplierId][$versionId] = $previous;
            }
        }
    }

    /**
     * Čisté slití výjimek do mapy (bez databáze, kvůli testovatelnosti).
     *
     * @param list<array<string,mixed>> $base      globální (+ funkční) mapa
     * @param list<array<string,mixed>> $overrides výjimky firmy
     * @return list<array<string,mixed>>
     */
    public static function applyOverrides(array $base, array $overrides): array
    {
        if ($overrides === []) {
            return $base;
        }

        $byPrefix = [];
        foreach ($overrides as $o) {
            $byPrefix[(string) $o['account_prefix']][] = $o;
        }

        $out = [];
        foreach ($base as $m) {
            if (!isset($byPrefix[(string) $m['account_prefix']])) {
                $out[] = $m;
            }
        }

        foreach ($byPrefix as $prefix => $items) {
            $prefix = (string) $prefix;
            $covered = [];
            foreach ($items as $o) {
                $condition = (string) $o['balance_condition'];
                foreach (self::sides($condition) as $side) {
                    $covered[$side] = true;
                }
                $out[] = [
                    'id'                => null,
                    'override_id'       => isset($o['id']) ? (int) $o['id'] : null,
                    'row_code'          => (string) $o['row_code'],
                    'account_prefix'    => $prefix,
                    'target'            => (string) $o['target'],
                    'balance_condition' => $condition,
                    'sign'              => (int) $o['sign'],
                    'source'            => 'override',
                ];
            }

            $remaining = array_values(array_diff(['debit', 'credit'], array_keys($covered)));
            if ($remaining === []) {
                continue;
            }
            foreach (self::longestMatching($base, $prefix) as $inherited) {
                $keep = array_values(array_intersect(self::sides((string) $inherited['balance_condition']), $remaining));
                if ($keep === []) {
                    continue;
                }
                $inherited['account_prefix'] = $prefix;
                $inherited['balance_condition'] = count($keep) === 2 ? 'any' : $keep[0];
                $out[] = $inherited;
            }
        }

        return $out;
    }

    /**
     * Záznamy mapy, které by bez výjimky platily pro účet s kódem `$code` — nejdelší
     * prefix, který je prefixem kódu (včetně shody).
     *
     * @param list<array<string,mixed>> $map
     * @return list<array<string,mixed>>
     */
    public static function longestMatching(array $map, string $code): array
    {
        $maxLen = 0;
        foreach ($map as $m) {
            $p = (string) $m['account_prefix'];
            if ($p !== '' && str_starts_with($code, $p) && strlen($p) > $maxLen) {
                $maxLen = strlen($p);
            }
        }
        if ($maxLen === 0) {
            return [];
        }

        return array_values(array_filter(
            $map,
            static fn (array $m): bool => strlen((string) $m['account_prefix']) === $maxLen
                && str_starts_with($code, (string) $m['account_prefix']),
        ));
    }

    /** @return list<string> */
    private static function sides(string $condition): array
    {
        return $condition === 'any' ? ['debit', 'credit'] : [$condition];
    }
}
