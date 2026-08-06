<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

/**
 * Otisk ULOŽENÉHO overridu, ne sloučeného výsledku.
 *
 * Kdyby se hashoval efektivní obsah, rozešel by se sám od sebe v okamžiku, kdy
 * nová verze aplikace doplní do defaultu další parametr — a aktivaci by pak
 * blokovala „neplatná" kontrolní suma, přestože nikdo nic neupravil. Otisk
 * overridu se mění jen tehdy, když někdo zapisuje, takže spolehlivě odhalí
 * zásah do DB mimo aplikaci.
 */
final class PayrollRulesetOverrideHash
{
    /** @param array<string, mixed> $row */
    public static function canonical(array $row): string
    {
        $data = $row['data'] ?? null;
        $decoded = is_string($data) && $data !== ''
            ? json_decode($data, true, 64, JSON_THROW_ON_ERROR)
            : [];

        return CanonicalJson::encode([
            'capability' => self::nullableString($row, 'capability'),
            'data' => is_array($decoded) ? $decoded : [],
            'effective_from' => self::nullableString($row, 'effective_from'),
            'effective_to' => self::nullableString($row, 'effective_to'),
            'lifecycle' => self::nullableString($row, 'lifecycle'),
            'ruleset_id' => self::nullableString($row, 'ruleset_id'),
            'version' => self::nullableString($row, 'version'),
        ]);
    }

    /** @param array<string, mixed> $row */
    public static function hash(array $row): string
    {
        return hash('sha256', self::canonical($row));
    }

    /** @param array<string, mixed> $row */
    public static function matches(array $row): bool
    {
        $stored = $row['content_hash'] ?? null;
        if (!is_string($stored)) {
            return false;
        }

        try {
            return hash_equals($stored, self::hash($row));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $row */
    private static function nullableString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
