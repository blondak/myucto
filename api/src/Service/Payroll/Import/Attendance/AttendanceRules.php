<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Pravidla mapování sloupců: `{sheet, header, meaning, unit, component_code}`,
 * volitelně s podmínkou `{when_header, when_value}`.
 *
 * `header` i `sheet` se porovnávají normalizovaně; `sheet = null` platí pro
 * všechny listy. Pořadí pravidel je priorita — při stejném významu z více
 * listů vyhrává dřívější pravidlo, hodnoty se nikdy nesčítají.
 *
 * Podmínka omezí pravidlo na řádky, kde má jiný sloupec téhož listu danou
 * hodnotu (třeba „oddělení = výroba"). Vyhodnocuje se po řádcích: kde neplatí,
 * použije se další pravidlo, které sloupci odpovídá.
 *
 * Pravidlo překážky na straně zaměstnavatele smí nést `rate_percent`: sazbu
 * náhrady mzdy v procentech průměrného výdělku (60 až 100, výchozí 80). Firma
 * ji zná ze svého podkladu (§ 207 písm. a) nejméně 80 %, § 207 písm. b)
 * a § 209 nejméně 60 %), z hodin samotných se určit nedá.
 *
 * @phpstan-type AttendanceRule array{sheet:?string,header:string,meaning:string,unit:?string,component_code:?string,when_header?:string,when_value?:string,rate_percent?:int}
 */
final class AttendanceRules
{
    public const MAX_RULES = 1000;

    public const RATE_MEANING = 'obstacle_employer_hours';
    public const DEFAULT_RATE_PERCENT = 80;
    private const MIN_RATE_PERCENT = 60;
    private const MAX_RATE_PERCENT = 100;

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
            $whenHeader = self::conditionText($rule['when_header'] ?? null, $header);
            $whenValue = self::conditionText($rule['when_value'] ?? null, $header);
            if (($whenHeader === null) !== ($whenValue === null)) {
                throw new \InvalidArgumentException(
                    "Pravidlo pro sloupec „{$header}“ má neúplnou podmínku. Vyplňte sloupec i hodnotu, nebo obojí smažte.",
                );
            }
            if ($whenHeader !== null && $component === self::AUTO_COMPONENT) {
                throw new \InvalidArgumentException(
                    "Pravidlo s podmínkou (sloupec „{$header}“) potřebuje konkrétní kód složky, ne *.",
                );
            }
            $rate = $rule['rate_percent'] ?? null;
            if ($rate !== null && $meaning !== self::RATE_MEANING) {
                throw new \InvalidArgumentException(
                    "Sazbu náhrady lze zadat jen u překážky na straně zaměstnavatele (sloupec „{$header}“).",
                );
            }
            if ($rate !== null && (!is_int($rate) || $rate < self::MIN_RATE_PERCENT || $rate > self::MAX_RATE_PERCENT)) {
                throw new \InvalidArgumentException(
                    "Sazba náhrady u sloupce „{$header}“ musí být celé procento od " . self::MIN_RATE_PERCENT
                    . ' do ' . self::MAX_RATE_PERCENT . '.',
                );
            }
            $entry = [
                'sheet' => $sheet === null ? null : trim($sheet),
                'header' => trim($header),
                'meaning' => $meaning,
                'unit' => $unit,
                'component_code' => $component,
            ];
            // Klíče podmínky a sazby jen u pravidla, které je má — profily
            // a otisky dávek bez nich zůstávají beze změny.
            if ($whenHeader !== null && $whenValue !== null) {
                $entry['when_header'] = $whenHeader;
                $entry['when_value'] = $whenValue;
            }
            if (is_int($rate)) {
                $entry['rate_percent'] = $rate;
            }
            $result[] = $entry;
        }
        // Hodnoty stejného významu se nesčítají, platí jedna — sazba proto také jedna.
        $rates = [];
        foreach ($result as $entry) {
            if ($entry['meaning'] === self::RATE_MEANING) {
                $rates[$entry['rate_percent'] ?? self::DEFAULT_RATE_PERCENT] = true;
            }
        }
        if (count($rates) > 1) {
            throw new \InvalidArgumentException(
                'Překážka na straně zaměstnavatele má v profilu různé sazby náhrady. Hodnoty z více sloupců '
                . 'se nesčítají, nechte u všech jejích pravidel stejnou sazbu.',
            );
        }

        return $result;
    }

    /**
     * Sazba náhrady za překážku na straně zaměstnavatele podle pravidel;
     * `null` = pravidla ji neurčují, platí výchozích 80 %.
     *
     * @param list<AttendanceRule> $rules
     */
    public static function obstacleEmployerRate(array $rules): ?int
    {
        foreach ($rules as $rule) {
            if ($rule['meaning'] === self::RATE_MEANING && isset($rule['rate_percent'])) {
                return (int) $rule['rate_percent'];
            }
        }

        return null;
    }

    /** @param AttendanceRule $rule */
    public static function matches(array $rule, string $normalizedSheet, string $normalizedHeader): bool
    {
        return self::like($rule['header'], $normalizedHeader)
            && ($rule['sheet'] === null || self::like($rule['sheet'], $normalizedSheet));
    }

    /** @param AttendanceRule $rule */
    public static function hasCondition(array $rule): bool
    {
        return isset($rule['when_header'], $rule['when_value']);
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

    private static function conditionText(mixed $value, string $header): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > 191) {
            throw new \InvalidArgumentException("Pravidlo pro sloupec „{$header}“ nemá platnou podmínku.");
        }

        return trim($value);
    }
}
