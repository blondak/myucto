<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

final class PayrollInputValidator
{
    private const SOURCE_KINDS = [
        'manual',
        'recurring',
        'time',
        'absence',
        'import',
        'correction',
    ];

    /**
     * @param array<string,mixed> $input
     * @return array{
     *   employee_id:int,
     *   employment_id:int,
     *   component_id:int,
     *   period_start:string,
     *   source_period_start:?string,
     *   amount_minor:int,
     *   quantity_milliunits:?int,
     *   source_kind:string,
     *   external_id:?string
     * }
     */
    public function validate(array $input): array
    {
        $source = $this->string($input['source_kind'] ?? 'manual', 'source_kind');
        if (!in_array($source, self::SOURCE_KINDS, true)) {
            throw new \InvalidArgumentException('Zdroj mzdového vstupu není podporovaný.');
        }

        return [
            'employee_id' => $this->positiveInt($input['employee_id'] ?? null, 'employee_id'),
            'employment_id' => $this->positiveInt(
                $input['employment_id'] ?? null,
                'employment_id',
            ),
            'component_id' => $this->positiveInt(
                $input['component_id'] ?? null,
                'component_id',
            ),
            'period_start' => $this->month($input['period'] ?? null, 'period'),
            'source_period_start' => $this->optionalMonth(
                $input['source_period'] ?? null,
                'source_period',
            ),
            'amount_minor' => $this->int($input['amount_minor'] ?? null, 'amount_minor'),
            'quantity_milliunits' => $this->optionalInt(
                $input['quantity_milliunits'] ?? null,
                'quantity_milliunits',
            ),
            'source_kind' => $source,
            'external_id' => $this->optionalString(
                $input['external_id'] ?? null,
                'external_id',
                190,
            ),
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

    private function int(mixed $value, string $field): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT);
        if ($result === false) {
            throw new \InvalidArgumentException("Pole {$field} musí být celé číslo.");
        }
        return (int) $result;
    }

    private function optionalInt(mixed $value, string $field): ?int
    {
        return $value === null || $value === ''
            ? null
            : $this->int($value, $field);
    }

    private function month(mixed $value, string $field): string
    {
        $normalized = $this->string($value, $field);
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $normalized);
        if ($date === false || $date->format('Y-m') !== $normalized) {
            throw new \InvalidArgumentException("Pole {$field} musí být měsíc YYYY-MM.");
        }
        return $normalized . '-01';
    }

    private function optionalMonth(mixed $value, string $field): ?string
    {
        return $value === null || $value === '' ? null : $this->month($value, $field);
    }

    private function string(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Pole {$field} musí být řetězec.");
        }
        return trim($value);
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
