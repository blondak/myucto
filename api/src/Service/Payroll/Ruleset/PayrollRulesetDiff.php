<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

/**
 * Porovnání dvou verzí téže domény nad kanonickým snapshotem.
 *
 * Hodnoty se nevrací jako předformátovaný text — typ (`money_minor`,
 * `decimal_rate`, …) jde ven spolu s hodnotou, aby prezentační vrstva mohla
 * haléře a bazické body přepočítat na koruny a procenta. Formátovat tady by
 * znamenalo zabetonovat českou lokalizaci do výpočetní vrstvy.
 */
final class PayrollRulesetDiff
{
    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array{
     *   added: list<array{key:string, after:array<string,mixed>}>,
     *   removed: list<array{key:string, before:array<string,mixed>}>,
     *   changed: list<array{key:string, before:array<string,mixed>, after:array<string,mixed>}>,
     *   unchanged_count: int,
     *   identical: bool
     * }
     */
    public static function between(array $left, array $right): array
    {
        $leftParameters = self::parameters($left);
        $rightParameters = self::parameters($right);

        $keys = array_unique([...array_keys($leftParameters), ...array_keys($rightParameters)]);
        sort($keys, SORT_STRING);

        $added = [];
        $removed = [];
        $changed = [];
        $unchanged = 0;

        foreach ($keys as $key) {
            $before = $leftParameters[$key] ?? null;
            $after = $rightParameters[$key] ?? null;
            if ($before === null) {
                /** @var array<string,mixed> $after */
                $added[] = ['key' => $key, 'after' => $after];
                continue;
            }
            if ($after === null) {
                $removed[] = ['key' => $key, 'before' => $before];
                continue;
            }
            if (CanonicalJson::encode($before) === CanonicalJson::encode($after)) {
                $unchanged++;
                continue;
            }
            $changed[] = ['key' => $key, 'before' => $before, 'after' => $after];
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'unchanged_count' => $unchanged,
            'identical' => $added === [] && $removed === [] && $changed === [],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, array<string, mixed>>
     */
    private static function parameters(array $snapshot): array
    {
        $raw = $snapshot['parameters'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $parameters = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
                continue;
            }
            $normalized = [];
            foreach ($value as $field => $item) {
                if (is_string($field)) {
                    $normalized[$field] = $item;
                }
            }
            $parameters[$key] = $normalized;
        }

        return $parameters;
    }
}
