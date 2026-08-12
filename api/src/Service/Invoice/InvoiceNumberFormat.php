<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Validace číselné řady faktur — jediné volatelné místo pro pravidla, která musí
 * platit všude, kde jde template/období nastavit.
 *
 * Osy, na kterých se řada dá přepsat, přibývají (dodavatel → klient → kategorie
 * tržby) a každá má vlastní repository. Kdyby pravidlo zůstalo jako `private`
 * helper jedné z nich, druhá by si ho zkopírovala — a rozdíl by se projevil až
 * tím, že neplatný template projde uložením a spadne teprve při vystavení
 * dokladu (viz AGENTS.md: „SSOT musí jít ZAVOLAT").
 *
 * Placeholdery zrcadlí {@see VarsymbolGenerator::render()}.
 */
final class InvoiceNumberFormat
{
    public const MAX_TEMPLATE_LENGTH = 60;

    public const PERIODS = ['year', 'month', 'none'];

    /**
     * Template override číselné řady. Prázdná hodnota → NULL (dědí se z nadřazené
     * úrovně). Whitelistne placeholdery a délku — uživatel nesmí protlačit template,
     * který by VarsymbolGenerator odmítl až při vystavení faktury.
     *
     * @throws \InvalidArgumentException neplatná délka nebo neznámý placeholder
     */
    public static function templateOrNull(mixed $value, string $key): ?string
    {
        $v = self::trimmedOrNull($value);
        if ($v === null) {
            return null;
        }
        if (strlen($v) > self::MAX_TEMPLATE_LENGTH) {
            throw new \InvalidArgumentException("{$key} smí mít max " . self::MAX_TEMPLATE_LENGTH . ' znaků.');
        }
        $stripped = preg_replace('/\{(YYYY|YY|MM|C+)\}/', '', $v) ?? '';
        if (preg_match('/[{}]/', $stripped)) {
            throw new \InvalidArgumentException("{$key} obsahuje neznámý placeholder. Dovolené: {YYYY} {YY} {MM} {C+}.");
        }
        return $v;
    }

    /**
     * Období counteru. NULL = dědí se z nadřazené úrovně.
     *
     * @throws \InvalidArgumentException neznámé období
     */
    public static function periodOrNull(mixed $value, string $key): ?string
    {
        $v = self::trimmedOrNull($value);
        if ($v === null) {
            return null;
        }
        if (!in_array($v, self::PERIODS, true)) {
            throw new \InvalidArgumentException("{$key} musí být year, month nebo none.");
        }
        return $v;
    }

    private static function trimmedOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);
        return $v === '' ? null : $v;
    }
}
