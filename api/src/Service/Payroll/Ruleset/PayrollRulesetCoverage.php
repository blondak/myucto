<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

use DateTimeImmutable;

/**
 * Kontrola spojitosti účinností jedné domény.
 *
 * Registry umí fail-closed odmítnout výpočet, když k rozhodnému dni existuje
 * nula nebo víc než jedna verze. Tady se táž vlastnost ověřuje DOPŘEDU — na
 * množině, která by po zamýšlené aktivaci vznikla — aby se mezera nebo překryv
 * neprojevily až selháním mzdy.
 */
final class PayrollRulesetCoverage
{
    /**
     * @param list<array{id:int, effective_from:string, effective_to:string, ruleset_id:string}> $intervals
     * @return list<array{code:string, message:string, context:array<string,mixed>}>
     */
    public static function issues(array $intervals): array
    {
        usort(
            $intervals,
            static fn (array $left, array $right): int =>
                [$left['effective_from'], $left['id']] <=> [$right['effective_from'], $right['id']],
        );

        $issues = [];
        $previous = null;
        foreach ($intervals as $interval) {
            if ($previous === null) {
                $previous = $interval;
                continue;
            }

            if ($interval['effective_from'] <= $previous['effective_to']) {
                $issues[] = [
                    'code' => 'effective_overlap',
                    'message' => sprintf(
                        'Účinnost verzí %s a %s se překrývá.',
                        $previous['ruleset_id'],
                        $interval['ruleset_id'],
                    ),
                    'context' => [
                        'left' => $previous['ruleset_id'],
                        'right' => $interval['ruleset_id'],
                        'from' => $interval['effective_from'],
                        'to' => $previous['effective_to'],
                    ],
                ];
                $previous = $interval['effective_to'] > $previous['effective_to'] ? $interval : $previous;
                continue;
            }

            $expected = (new DateTimeImmutable($previous['effective_to']))
                ->modify('+1 day')
                ->format('Y-m-d');
            if ($interval['effective_from'] !== $expected) {
                $issues[] = [
                    'code' => 'effective_gap',
                    'message' => sprintf(
                        'Mezi verzemi %s a %s chybí účinnost od %s do %s.',
                        $previous['ruleset_id'],
                        $interval['ruleset_id'],
                        $expected,
                        (new DateTimeImmutable($interval['effective_from']))
                            ->modify('-1 day')
                            ->format('Y-m-d'),
                    ),
                    'context' => [
                        'left' => $previous['ruleset_id'],
                        'right' => $interval['ruleset_id'],
                        'gap_from' => $expected,
                        'gap_to' => (new DateTimeImmutable($interval['effective_from']))
                            ->modify('-1 day')
                            ->format('Y-m-d'),
                    ],
                ];
            }
            $previous = $interval;
        }

        return $issues;
    }
}
