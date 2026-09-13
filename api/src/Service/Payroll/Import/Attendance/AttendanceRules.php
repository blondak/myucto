<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Pravidla mapování sloupců: `{sheet, header, meaning, unit, component_code}`.
 *
 * `header` i `sheet` se porovnávají normalizovaně; `sheet = null` platí pro
 * všechny listy. Pořadí pravidel je priorita — při stejném významu z více
 * listů vyhrává dřívější pravidlo, hodnoty se nikdy nesčítají.
 *
 * @phpstan-type AttendanceRule array{sheet:?string,header:string,meaning:string,unit:?string,component_code:?string}
 */
final class AttendanceRules
{
    public const MAX_RULES = 1000;

    /**
     * Kód složky „podle hlavičky": sloupec s částkami, který profil výslovně
     * nezná, dostane vlastní mzdovou složku pojmenovanou podle hlavičky.
     */
    public const AUTO_COMPONENT = '*';

    /** @var array<string,string> vzor → regulární výraz */
    private static array $patterns = [];

    /** @return list<AttendanceRule> */
    public static function validate(mixed $rules): array
    {
        if ($rules === null) {
            return [];
        }
        if (!is_array($rules) || !array_is_list($rules)) {
            throw new \InvalidArgumentException('Pravidla mapování musí být seznam.');
        }
        if (count($rules) > self::MAX_RULES) {
            throw new \InvalidArgumentException('Pravidel mapování je příliš mnoho (nejvýše ' . self::MAX_RULES . ').');
        }
        $result = [];
        foreach ($rules as $index => $rule) {
            $position = $index + 1;
            if (!is_array($rule)) {
                throw new \InvalidArgumentException("Pravidlo č. {$position} nemá platný tvar.");
            }
            $header = $rule['header'] ?? null;
            if (!is_string($header) || trim($header) === '' || mb_strlen($header) > 191) {
                throw new \InvalidArgumentException("Pravidlo č. {$position} nemá platný název sloupce.");
            }
            $sheet = $rule['sheet'] ?? null;
            if ($sheet !== null && (!is_string($sheet) || mb_strlen($sheet) > 120)) {
                throw new \InvalidArgumentException("Pravidlo č. {$position} nemá platný název listu.");
            }
            if (is_string($sheet) && trim($sheet) === '') {
                $sheet = null;
            }
            $meaning = $rule['meaning'] ?? null;
            if (!is_string($meaning) || !AttendanceMeaning::isValid($meaning)) {
                throw new \InvalidArgumentException("Pravidlo pro sloupec „{$header}“ má neznámý význam.");
            }
            $unit = $rule['unit'] ?? null;
            if ($unit !== null) {
                if (!is_string($unit) || !in_array($unit, AttendanceMeaning::UNITS, true)) {
                    throw new \InvalidArgumentException("Pravidlo pro sloupec „{$header}“ má neznámou jednotku.");
                }
                if ($meaning !== AttendanceMeaning::IGNORE
                    && !in_array($unit, AttendanceMeaning::allowedUnits($meaning), true)) {
                    throw new \InvalidArgumentException(
                        "Jednotka „{$unit}“ se k významu sloupce „{$header}“ nehodí. Hodiny mají jednotku hodiny "
                        . 'nebo excelové trvání, částky jednotku částka.',
                    );
                }
            }
            $component = $rule['component_code'] ?? null;
            if ($meaning === AttendanceMeaning::COMPONENT) {
                $component = is_string($component) ? strtoupper(trim($component)) : '';
                if ($component !== self::AUTO_COMPONENT
                    && preg_match('/^[A-Z0-9][A-Z0-9._-]{0,63}$/D', $component) !== 1) {
                    throw new \InvalidArgumentException(
                        "Sloupec „{$header}“ je mzdová složka, ale nemá platný kód složky. Vyberte složku.",
                    );
                }
            } elseif ($component !== null && $component !== '') {
                throw new \InvalidArgumentException(
                    "Kód mzdové složky patří jen ke sloupci s významem mzdová složka (sloupec „{$header}“).",
                );
            } else {
                $component = null;
            }
            $result[] = [
                'sheet' => $sheet === null ? null : trim($sheet),
                'header' => trim($header),
                'meaning' => $meaning,
                'unit' => $unit,
                'component_code' => $component,
            ];
        }

        return $result;
    }

    /** @param AttendanceRule $rule */
    public static function matches(array $rule, string $normalizedSheet, string $normalizedHeader): bool
    {
        return self::like($rule['header'], $normalizedHeader)
            && ($rule['sheet'] === null || self::like($rule['sheet'], $normalizedSheet));
    }

    /**
     * Porovnání s normalizovaným textem; vzor smí obsahovat `*` (libovolný
     * text) a `?` (jeden znak). Názvy listů i hlavičky se mezi verzemi šablony
     * liší měsícem („Mzdy 06-26") nebo doplňkem („… vč. přesčasů a NOC").
     */
    public static function like(string $pattern, string $normalizedValue): bool
    {
        $normalized = AttendanceText::normalize($pattern);
        if (!str_contains($normalized, '*') && !str_contains($normalized, '?')) {
            return $normalized === $normalizedValue;
        }
        $regex = self::$patterns[$normalized]
            ??= '/^' . strtr(preg_quote($normalized, '/'), ['\*' => '.*', '\?' => '.']) . '$/su';

        return preg_match($regex, $normalizedValue) === 1;
    }
}
