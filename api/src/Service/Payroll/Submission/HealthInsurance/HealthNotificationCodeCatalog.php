<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Kódy změny jednotné datové věty HOZ a to, co o nich podklady doloženě říkají.
 *
 * Podklady dokládají DVĚ věci: úplný výčet 25 kódů a jejich rozdělení do tří
 * skupin. Význam jednotlivých písmen NEDOKLÁDAJÍ — v `private/Mzdy/podklady/`
 * není ani datový slovník, ani pokyny k vyplnění HOZ. Katalog to říká nahlas
 * přes {@see self::SEMANTICS_UNDOCUMENTED} a odmítá kód vyrobit; hádat, že
 * „P je přihláška", by znamenalo poslat pojišťovně tvrzení, které nikdo
 * neověřil, a přijatou větu už nelze vzít zpět.
 *
 * Co katalog naopak umí i bez významu písmen: **odmítnout kód, který
 * zaměstnavatel po 1. 1. 2026 podat nesmí.** Skupinová příslušnost na to
 * stačí a XSD tuhle kontrolu neudělá — enum v schématu obsahuje všech 25
 * kódů i po zúžení povinnosti.
 */
final class HealthNotificationCodeCatalog
{
    public const SEMANTICS_UNDOCUMENTED = 'semantics_undocumented';

    /** Kódy vzniku, změny a zániku zaměstnání. */
    private const EMPLOYMENT = ['P', 'A', 'E', 'C', 'O', 'Q'];

    /** Kódy kategorií, kde je plátcem pojistného stát. */
    private const STATE_CATEGORY = [
        'M', 'U', 'D', 'H', 'I', 'J', 'G', 'F',
        'L', 'T', 'N', 'K', 'S', 'R', 'W', 'V',
    ];

    /** Kódy oprav již podaných vět. */
    private const CORRECTION = ['X', 'Y', 'Z'];

    /**
     * Jediné dva kódy ze skupiny „plátcem je stát", které zaměstnavatel
     * po zúžení k 1. 1. 2026 podat smí. Který z nich je mateřská a který
     * rodičovská, podklady neříkají — proto se sem nedá napsat význam,
     * jen povolení.
     */
    private const STATE_CATEGORY_STILL_REPORTED = ['M', 'U'];

    /** @return list<string> */
    public function codes(): array
    {
        return array_merge(
            self::EMPLOYMENT,
            self::STATE_CATEGORY,
            self::CORRECTION,
        );
    }

    public function isKnown(string $code): bool
    {
        return in_array($code, $this->codes(), true);
    }

    public function group(string $code): HealthNotificationCodeGroup
    {
        if (in_array($code, self::EMPLOYMENT, true)) {
            return HealthNotificationCodeGroup::Employment;
        }
        if (in_array($code, self::STATE_CATEGORY, true)) {
            return HealthNotificationCodeGroup::StateCategory;
        }
        if (in_array($code, self::CORRECTION, true)) {
            return HealthNotificationCodeGroup::Correction;
        }

        throw new HealthNotificationException(
            'zp_change_code_unknown',
            'Kód změny není v jednotné datové větě HOZ.',
        );
    }

    /**
     * Smí zaměstnavatel tenhle kód ke dni změny podat?
     *
     * Tohle je ta kontrola, kterou XSD neudělá. Od 1. 1. 2026 propadne
     * čtrnáct kódů ze skupiny „plátcem je stát" — schéma je propustí,
     * zákon je zaměstnavateli odebral.
     */
    public function isReportableByEmployer(string $code, string $onDate): bool
    {
        $group = $this->group($code);
        if ($group !== HealthNotificationCodeGroup::StateCategory) {
            return true;
        }
        if (in_array($code, self::STATE_CATEGORY_STILL_REPORTED, true)) {
            return true;
        }

        return $onDate < HealthNotificationDutyCatalog::NARROWING_EFFECTIVE_FROM;
    }

    public function assertReportableByEmployer(
        string $code,
        string $onDate,
    ): void {
        if (!$this->isKnown($code)) {
            throw new HealthNotificationException(
                'zp_change_code_unknown',
                'Kód změny není v jednotné datové větě HOZ.',
            );
        }
        if ($this->isReportableByEmployer($code, $onDate)) {
            return;
        }

        throw new HealthNotificationException(
            'zp_change_code_not_reported_by_employer',
            sprintf(
                'Kód změny %s od %s nehlásí zaměstnavatel, ale sám pojištěnec. '
                . 'Jednotné XSD ho propustí, zákon ne.',
                $code,
                HealthNotificationDutyCatalog::NARROWING_EFFECTIVE_FROM,
            ),
        );
    }

    /**
     * Kód pro daný druh povinnosti. Vždy skončí chybou: mapování druh → kód
     * není v podkladech doložené u jediného písmene.
     *
     * Metoda existuje proto, aby se ta mezera dala pojmenovat na jednom místě
     * a aby ji šlo zavřít doplněním podkladu, ne přepsáním volajících.
     */
    public function codeFor(HealthNotificationDutyKind $kind): never
    {
        throw new HealthNotificationException(
            'zp_change_code_mapping_undocumented',
            sprintf(
                'Podklady nedokládají, který kód změny odpovídá povinnosti „%s". '
                . 'Doplňte do private/Mzdy/podklady/ datový slovník nebo pokyny '
                . 'k vyplnění HOZ; do té doby se kód neodhaduje.',
                $kind->value,
            ),
        );
    }
}
