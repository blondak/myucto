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

    /**
     * Uložený UTC instant → místní čas v zóně záznamu.
     *
     * SSOT pro opačný směr než {@see \MyInvoice\Service\Payroll\Time\PayrollTimeInterval}:
     * mzdový modul ukládá okamžik v UTC a vedle něj IANA zónu, ve které byl
     * zadán, a všude, kde se má ukázat nebo počítat MÍSTNÍ čas (den směny, pásmo
     * stravného, výpis cesty), se zpátky převádí tímhle.
     */
    public static function localMoment(string $utc, string $timezoneName): string
    {
        try {
            $timezone = new \DateTimeZone($timezoneName);
        } catch (\Throwable) {
            throw new \UnexpectedValueException("{$timezoneName} není platná IANA zóna.");
        }

        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
            ->setTimezone($timezone)
            ->format('Y-m-d H:i:s');
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
