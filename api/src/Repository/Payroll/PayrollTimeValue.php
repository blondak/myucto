<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class PayrollTimeValue
{
    public static function int(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new \UnexpectedValueException("{$field} musí být celé číslo.");
    }

    public static function string(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException("{$field} musí být text.");
        }
        return $value;
    }

    public static function bool(mixed $value, string $field): bool
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
        throw new \UnexpectedValueException("{$field} musí být boolean.");
    }

    /** @return array<string,mixed> */
    public static function row(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException("{$field} musí být objekt.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException("{$field} musí mít textové klíče.");
            }
            $result[$key] = $item;
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public static function rows(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException("{$field} musí být seznam.");
        }
        $result = [];
        foreach ($value as $index => $row) {
            $result[] = self::row($row, "{$field}.{$index}");
        }
        return $result;
    }
}
