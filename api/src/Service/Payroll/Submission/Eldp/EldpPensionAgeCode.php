<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

/**
 * Druhý znak kódu ELDP „D": výdělečná činnost po dovršení důchodového věku
 * nebo poživatel předčasného starobního důchodu (číselník CIS Kód ELDP,
 * ID 10240).
 *
 * Jediné místo pravidla pro oba výstupy, které kód ELDP nesou: roční
 * evidenční list ({@see EldpAnnualStatementBuilder}) i měsíční hlášení JMHZ
 * ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEldpEvidenceBuilder}).
 * Číselník je pro ně společný, takže stejná osoba nemůže mít v listu a v hlášení
 * různý kód.
 *
 * Den, od kterého kód D platí, plyne z důchodových údajů zaměstnance
 * (`pension_age_reached_on`, `early_pension_from`) v zákonné evidenci osoby
 * ({@see \MyInvoice\Service\Payroll\Pension\PayrollPensionStatus}). Bez nich
 * se kód nemění a zůstává „++".
 */
final class EldpPensionAgeCode
{
    /** Interval pojištění leží celý před dnem, od kterého platí kód D. */
    public const PLAIN = 'plain';

    /** Interval pojištění leží celý v době, na kterou se kód D vztahuje. */
    public const PENSION_AGE = 'pension_age';

    /** Kód D začíná uprostřed intervalu; vyměřovací základ by bylo nutné rozdělit. */
    public const MID_INTERVAL = 'mid_interval';

    /**
     * Den, od kterého nese činnost kód D: dovršení důchodového věku, nebo
     * přiznání předčasného starobního důchodu, podle toho, co nastalo dřív.
     *
     * @param array{pension_age_reached_on?:?string,early_pension_from?:?string} $pension
     */
    public static function codeFrom(array $pension): ?string
    {
        $dates = array_filter(
            [$pension['pension_age_reached_on'] ?? null, $pension['early_pension_from'] ?? null],
            static fn (?string $date): bool => $date !== null,
        );

        return $dates === [] ? null : min($dates);
    }

    /**
     * Kam v intervalu pojištění (včetně krajních dnů) padá den, od kterého
     * platí kód D.
     *
     * @return self::PLAIN|self::PENSION_AGE|self::MID_INTERVAL
     */
    public static function placement(?string $codeFrom, string $intervalFrom, string $intervalTo): string
    {
        if ($codeFrom === null || $intervalTo < $codeFrom) {
            return self::PLAIN;
        }

        return $intervalFrom >= $codeFrom ? self::PENSION_AGE : self::MID_INTERVAL;
    }

    /**
     * Kód ELDP s druhým znakem „D" ("1++" => "1D+"). Druh činnosti na začátku
     * a třetí znak na konci zůstávají; druhý znak je vždy předposlední.
     */
    public static function withPensionAge(string $code): string
    {
        return substr($code, 0, -2) . 'D' . substr($code, -1);
    }
}
