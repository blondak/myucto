<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

/**
 * @phpstan-type RecurringComponentInput array{
 *   employment_id:int,
 *   component_id:int,
 *   calculation_kind:string,
 *   amount_minor:?int,
 *   rate_basis_points:?int,
 *   valid_from:string,
 *   valid_to:?string,
 *   allocation_rule:string,
 *   maximum_amount_minor:?int,
 *   note:?string,
 *   is_active:bool
 * }
 */
final class PayrollRecurringComponentValidator
{
    private const CALCULATION_KINDS = [
        'fixed_amount',
        'employment_gross_basis_points',
        'manual_review',
    ];

    private const ALLOCATION_RULES = [
        'full_month',
        'calendar_days',
        'working_days',
        'hours',
        'manual_review',
    ];

    /**
     * @param array<string,mixed> $input
     * @return RecurringComponentInput
     */
    public function validate(array $input): array
    {
        $calculation = $this->string(
            $input['calculation_kind'] ?? 'fixed_amount',
            'calculation_kind',
        );
        if (!in_array($calculation, self::CALCULATION_KINDS, true)) {
            throw new \InvalidArgumentException('Výpočet opakované složky není podporovaný.');
        }
        $allocation = $this->string(
            $input['allocation_rule'] ?? 'full_month',
            'allocation_rule',
        );
        if (!in_array($allocation, self::ALLOCATION_RULES, true)) {
            throw new \InvalidArgumentException('Rozpočítání opakované složky není podporované.');
        }

        $amount = $this->optionalInt($input['amount_minor'] ?? null, 'amount_minor');
        $rate = $this->optionalPositiveInt(
            $input['rate_basis_points'] ?? null,
            'rate_basis_points',
        );
        if ($amount === PHP_INT_MIN) {
            throw new \InvalidArgumentException('Částka opakované složky je mimo podporovaný rozsah.');
        }
        if ($calculation === 'fixed_amount' && ($amount === null || $rate !== null)) {
            throw new \InvalidArgumentException(
                'Pevná opakovaná složka vyžaduje částku a nesmí obsahovat sazbu.'
            );
        }
        if ($calculation === 'employment_gross_basis_points'
            && ($amount !== null || $rate === null || $rate > 10000)) {
            throw new \InvalidArgumentException(
                'Procentní předpis vyžaduje sazbu 1 až 10000 bazických bodů bez pevné částky.'
            );
        }
        if ($calculation === 'manual_review' && ($amount !== null || $rate !== null)) {
            throw new \InvalidArgumentException(
                'Ručně posuzovaný předpis nesmí předstírat automatickou částku nebo sazbu.'
            );
        }

        $from = $this->date($input['valid_from'] ?? null, 'valid_from');
        $to = $this->optionalDate($input['valid_to'] ?? null, 'valid_to');
        if ($to !== null && $to < $from) {
            throw new \InvalidArgumentException('Konec platnosti nesmí předcházet začátku.');
        }

        return [
            'employment_id' => $this->positiveInt(
                $input['employment_id'] ?? null,
                'employment_id',
            ),
            'component_id' => $this->positiveInt(
                $input['component_id'] ?? null,
                'component_id',
            ),
            'calculation_kind' => $calculation,
            'amount_minor' => $amount,
            'rate_basis_points' => $rate,
            'valid_from' => $from,
            'valid_to' => $to,
            'allocation_rule' => $allocation,
            'maximum_amount_minor' => $this->optionalPositiveInt(
                $input['maximum_amount_minor'] ?? null,
                'maximum_amount_minor',
            ),
            'note' => $this->optionalString($input['note'] ?? null, 'note', 500),
            'is_active' => $this->bool($input['is_active'] ?? true, 'is_active'),
        ];
    }

    private function positiveInt(mixed $value, string $field): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($result === false) {
            throw new \InvalidArgumentException("Pole {$field} musí být kladné celé číslo.");
        }
        return (int) $result;
    }

    private function optionalPositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->positiveInt($value, $field);
    }

    private function optionalInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $result = filter_var($value, FILTER_VALIDATE_INT);
        if ($result === false) {
            throw new \InvalidArgumentException("Pole {$field} musí být celé číslo.");
        }
        return (int) $result;
    }

    private function string(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Pole {$field} musí být text.");
        }
        return trim($value);
    }

    private function date(mixed $value, string $field): string
    {
        $normalized = $this->string($value, $field);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $normalized);
        if ($date === false || $date->format('Y-m-d') !== $normalized) {
            throw new \InvalidArgumentException("Pole {$field} musí být datum YYYY-MM-DD.");
        }
        return $normalized;
    }

    private function optionalDate(mixed $value, string $field): ?string
    {
        return $value === null || $value === '' ? null : $this->date($value, $field);
    }

    private function bool(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }
        throw new \InvalidArgumentException("Pole {$field} musí být boolean.");
    }

    private function optionalString(mixed $value, string $field, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = $this->string($value, $field);
        if ($normalized === '' || mb_strlen($normalized) > $max) {
            throw new \InvalidArgumentException("Pole {$field} není platné.");
        }
        return $normalized;
    }
}
