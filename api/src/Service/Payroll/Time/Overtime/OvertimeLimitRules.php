<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Overtime;

use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Čtecí cesta k limitům § 93 přes registr rulesetů.
 *
 * Projekt má tvrdé pravidlo, že zákonné hranice žijí v rulesetu a ne v kódu —
 * naposledy se jeho porušení projevilo vadou u hranice srážkové daně. Klíče
 * v {@see \MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026} zatím
 * nejsou (soubor právě mění jiná větev), takže tenhle resolver chybějící klíč
 * NEBERE jako chybu a spadne zpátky na zákonnou hodnotu z § 93. Jakmile se
 * klíče do rulesetu doplní, začnou se používat bez zásahu do téhle třídy.
 *
 * Fallback je vědomě tichý jen co do výsledku, ne co do doložitelnosti:
 * `OvertimeLimits::$fromRuleset` nese informaci, odkud hodnoty pocházejí, a
 * propisuje se až do odpovědi API.
 *
 * Očekávané klíče (doména `employment_thresholds`, typ `integer`):
 *   overtime.ordered.weekly_max_minutes            480   (§ 93 odst. 2)
 *   overtime.ordered.yearly_max_minutes           9000   (§ 93 odst. 2)
 *   overtime.averaging.weekly_average_max_minutes  480   (§ 93 odst. 4)
 *   overtime.averaging.max_weeks                    26   (§ 93 odst. 4)
 *   overtime.annual.early_warning_basis_points     8000   (bez opory v zákoně,
 *                                                          jen práh upozornění)
 */
final class OvertimeLimitRules
{
    public const KEY_WEEKLY = 'overtime.ordered.weekly_max_minutes';
    public const KEY_YEARLY = 'overtime.ordered.yearly_max_minutes';
    public const KEY_AVERAGING_WEEKLY = 'overtime.averaging.weekly_average_max_minutes';
    public const KEY_AVERAGING_WEEKS = 'overtime.averaging.max_weeks';
    public const KEY_EARLY_WARNING = 'overtime.annual.early_warning_basis_points';

    /** § 93 odst. 2 — 8 hodin v jednotlivých týdnech. */
    private const STATUTORY_WEEKLY_MINUTES = 480;

    /** § 93 odst. 2 — 150 hodin v kalendářním roce. */
    private const STATUTORY_YEARLY_MINUTES = 9_000;

    /** § 93 odst. 4 — průměr nejvýše 8 hodin týdně. */
    private const STATUTORY_AVERAGING_WEEKLY_MINUTES = 480;

    /** § 93 odst. 4 — vyrovnávací období nejvýše 26 týdnů po sobě jdoucích. */
    private const STATUTORY_AVERAGING_WEEKS = 26;

    private const DEFAULT_EARLY_WARNING_BASIS_POINTS = 8_000;

    /**
     * Registr je POVINNÝ — jako volitelný parametr by ho PHP-DI nevyplnilo a
     * hlídání by tiše počítalo z vestavěných hodnot místo z administrátorského
     * nastavení. Hlídá to i architektonická brána
     * {@see \MyInvoice\Tests\Architecture\PayrollRulesetSingleSourceGuardTest}.
     */
    public function __construct(
        private readonly PayrollRulesetProvider $rulesets,
    ) {}

    public function forDate(string $date): OvertimeLimits
    {
        // Období mimo pokrytí rulesetu (starší docházka, kterou si účetní jen
        // prohlíží) nesmí obrazovku shodit — v takovém případě se použije
        // zákonné znění § 93, které se od roku 2006 nezměnilo.
        try {
            $version = $this->rulesets->forDate(
                PayrollRulesetDomain::EmploymentThresholds,
                $date,
            );
        } catch (PayrollRulesetException|\InvalidArgumentException) {
            $version = null;
        }

        $resolved = 0;
        $read = function (string $key, int $fallback) use ($version, &$resolved): int {
            if ($version === null) {
                return $fallback;
            }
            try {
                $value = $version->parameter($key)->value;
            } catch (PayrollRulesetException) {
                return $fallback;
            }
            if (!is_int($value)) {
                return $fallback;
            }
            ++$resolved;

            return $value;
        };

        $weekly = $read(self::KEY_WEEKLY, self::STATUTORY_WEEKLY_MINUTES);
        $yearly = $read(self::KEY_YEARLY, self::STATUTORY_YEARLY_MINUTES);
        $averagingWeekly = $read(
            self::KEY_AVERAGING_WEEKLY,
            self::STATUTORY_AVERAGING_WEEKLY_MINUTES,
        );
        $averagingWeeks = $read(self::KEY_AVERAGING_WEEKS, self::STATUTORY_AVERAGING_WEEKS);
        $earlyWarning = $read(
            self::KEY_EARLY_WARNING,
            self::DEFAULT_EARLY_WARNING_BASIS_POINTS,
        );

        return new OvertimeLimits(
            $weekly,
            $yearly,
            $averagingWeekly,
            $averagingWeeks,
            $earlyWarning,
            $resolved === 5,
        );
    }
}
