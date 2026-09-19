<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\OpeningBalance;

use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;

/**
 * Věcná kontrola jednoho měsíce počátečního stavu.
 *
 * Existuje proto, že do počátečních stavů vedou TŘI cesty — ruční mřížka,
 * import hlášení JMHZ a tabulkový import z předchozího programu — a každá si
 * dřív nesla vlastní představu o tom, co je platný měsíc. Kontrola, která
 * platí jen na jedné z nich, je horší než žádná: uživatel ji objeví až na
 * cestě, kterou zrovna nepoužil.
 *
 * Kontroluje se jen to, co je PROKAZATELNĚ nemožné, ne to, co je nepravděpodobné.
 * Daň bez základu ani pojistné bez vyměřovacího základu vzniknout nemůže —
 * takový řádek je překlep nebo špatně napárovaný sloupec sestavy a jeho převzetí
 * by zkreslilo roční kumulaci i roční zúčtování.
 */
final class OpeningBalanceMonthValidator
{
    /**
     * Částka vlevo nemůže být kladná, když je částka vpravo nulová.
     *
     * Záloha i srážková daň se počítají ZE základu, bonus náleží jen k příjmu
     * rozhodnému pro bonus a zdravotní pojistné (včetně dopočtu do minima) se
     * odvádí z vyměřovacího základu.
     */
    private const REQUIRES_BASE = [
        'advance_tax_minor_units' => 'advance_base_minor_units',
        'withholding_tax_minor_units' => 'withholding_base_minor_units',
        'tax_bonus_minor_units' => 'bonus_qualifying_income_minor_units',
        'health_employee_contribution_minor_units' => 'health_assessment_base_minor_units',
        'health_employer_contribution_minor_units' => 'health_assessment_base_minor_units',
        'health_minimum_top_up_minor_units' => 'health_assessment_base_minor_units',
    ];

    /**
     * Názvy sloupců tak, jak je uživatel vidí v mřížce i ve vzorovém souboru.
     * Hláška musí ukázat na buňku, do které se má sáhnout; „advance_base_minor_units"
     * účetní nikam nenavede.
     */
    private const LABELS = [
        'social_assessment_base_minor_units' => 'Vyměřovací základ sociálního pojištění',
        'health_assessment_base_minor_units' => 'Vyměřovací základ zdravotního pojištění',
        'health_employee_contribution_minor_units' => 'Zdravotní pojistné zaměstnance',
        'health_employer_contribution_minor_units' => 'Zdravotní pojistné zaměstnavatele',
        'health_minimum_top_up_minor_units' => 'Z toho dopočet do minima',
        'advance_base_minor_units' => 'Základ zálohové daně',
        'advance_tax_minor_units' => 'Záloha na daň',
        'withholding_base_minor_units' => 'Základ srážkové daně',
        'withholding_tax_minor_units' => 'Srážková daň',
        'applied_non_refundable_credits_minor_units' => 'Uplatněné slevy na dani',
        'applied_child_credit_minor_units' => 'Uplatněné daňové zvýhodnění na děti',
        'tax_bonus_minor_units' => 'Daňový bonus',
        'bonus_qualifying_income_minor_units' => 'Příjem rozhodný pro bonus',
    ];

    public static function label(string $field): string
    {
        return self::LABELS[$field] ?? throw new \LogicException(
            "Sloupec počátečního stavu {$field} nemá popisek.",
        );
    }

    /** @return array<string,string> sloupec rozpisu => popisek, v pořadí kumulací */
    public static function labels(): array
    {
        $labels = [];
        foreach (PayrollOpeningBalanceService::monthFields() as $field) {
            $labels[$field] = self::label($field);
        }

        return $labels;
    }

    /**
     * @param array<string,mixed> $row měsíc rozpisu; chybějící sloupec je nula
     * @throws \InvalidArgumentException
     */
    public static function assertValid(array $row): void
    {
        $reason = self::reject($row);
        if ($reason !== null) {
            throw new \InvalidArgumentException($reason);
        }
    }

    /**
     * Důvod odmítnutí, nebo `null`. Náhled importu potřebuje důvod jako text
     * u řádku, zápis jako výjimku — obojí z jednoho pravidla.
     *
     * @param array<string,mixed> $row
     */
    public static function reject(array $row): ?string
    {
        $month = $row['month'] ?? null;
        if (!is_int($month) || $month < 1 || $month > 12) {
            return 'Měsíc počátečního stavu musí být číslo 1 až 12.';
        }
        $values = [];
        foreach (PayrollOpeningBalanceService::monthFields() as $field) {
            $value = $row[$field] ?? 0;
            if (!is_int($value) || $value < 0) {
                return sprintf(
                    'Sloupec „%s" v měsíci %d musí být částka nula nebo vyšší, v celých haléřích.',
                    self::label($field),
                    $month,
                );
            }
            $values[$field] = $value;
        }
        foreach (self::REQUIRES_BASE as $amount => $base) {
            if (($values[$amount] ?? 0) > 0 && ($values[$base] ?? 0) === 0) {
                return sprintf(
                    'V měsíci %d je vyplněný sloupec „%s", ale „%s" je nulový. '
                        . 'Taková kombinace vzniknout nemůže — zkontrolujte, ze kterého sloupce sestavy jste čísla opsali.',
                    $month,
                    self::label($amount),
                    self::label($base),
                );
            }
        }

        return null;
    }
}
