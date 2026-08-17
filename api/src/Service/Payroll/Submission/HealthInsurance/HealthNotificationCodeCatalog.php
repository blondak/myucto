<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Kódy změny jednotné datové věty HOZ a to, co o nich podklady doloženě říkají.
 *
 * Význam jednotlivých písmen dokládá anotace `xsd:documentation` u typu
 * `kodZmenyZamestnaceTyp` v připnutém
 * `api/xsd/zp/2025-v8/hromadneOznameniZamestnavatele_2025_v8.xsd` — dokud
 * schéma v repu nebylo, katalog kód odmítal vyrobit. Odblokovaly se jen ty
 * druhy povinnosti, kde schéma určuje JEDINÝ kód
 * ({@see self::DOCUMENTED_CODE_FOR_DUTY}); tam, kde by kód závisel na
 * opravované položce nebo na směru přestupu, zůstává metoda fail-closed
 * i s XSD ({@see self::UNMAPPED_DUTY_REASON}).
 *
 * Co katalog naopak umí i bez významu písmen: **odmítnout kód, který
 * zaměstnavatel po 1. 1. 2026 podat nesmí.** Skupinová příslušnost na to
 * stačí a XSD tuhle kontrolu neudělá — enum v schématu obsahuje všech 25
 * kódů i po zúžení povinnosti.
 */
final class HealthNotificationCodeCatalog
{
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
     * Mapování druh povinnosti → kód změny, doložené anotací
     * `xsd:documentation` u typu `kodZmenyZamestnaceTyp`
     * v `api/xsd/zp/2025-v8/hromadneOznameniZamestnavatele_2025_v8.xsd`.
     *
     * Doslovné znění schématu k použitým písmenům:
     * - `P` — „nástup do zaměstnání",
     * - `O` — „ukončení zaměstnání (u zaměstnance přihlášeného kódy „P", „A",
     *   „E" nebo „C")",
     * - `M` — „nástup zaměstnankyně na mateřskou dovolenou NEBO osoby na
     *   rodičovskou dovolenou" (schéma obě dovolené vede pod jedním kódem,
     *   proto sem míří dva druhy povinnosti),
     * - `U` — „ukončení mateřské nebo rodičovské dovolené".
     *
     * @var array<string,string>
     */
    private const DOCUMENTED_CODE_FOR_DUTY = [
        HealthNotificationDutyKind::EmploymentStart->value => 'P',
        HealthNotificationDutyKind::EmploymentEnd->value => 'O',
        HealthNotificationDutyKind::MaternityLeaveStart->value => 'M',
        HealthNotificationDutyKind::ParentalLeaveStart->value => 'M',
        HealthNotificationDutyKind::MaternityOrParentalLeaveEnd->value => 'U',
    ];

    /**
     * Zbylé druhy povinnosti kód nedostanou, a to KAŽDÝ z jiného důvodu —
     * proto se nesmí slít do jedné hlášky.
     *
     * @var array<string,string>
     */
    private const UNMAPPED_DUTY_REASON = [
        HealthNotificationDutyKind::EmployeeDataChange->value =>
            'Opravné kódy „X", „Y" a „Z" schéma váže na KONKRÉTNÍ opravovanou '
            . 'položku (číslo pojištěnce, datum přihlášení, datum odhlášení). '
            . 'Druh povinnosti „změna údajů" tuhle položku nenese, takže z něj '
            . 'jediný kód neplyne.',
        HealthNotificationDutyKind::InsurerChange->value =>
            'Přestup mezi pojišťovnami se podle schématu hlásí každé pojišťovně '
            . 'jinak: odcházející kódem „O" („přestupu k jiné zdravotní '
            . 'pojišťovně"), přijímající kódem „P" („při přestupu od jiné '
            . 'zdravotní pojišťovny"). Bez směru přestupu se kód určit nedá.',
        HealthNotificationDutyKind::StateCategoryOther->value =>
            'Skutečnosti ze skupiny „plátcem je stát" mimo kódy „M" a „U" '
            . 'zaměstnavatel od 1. 1. 2026 nehlásí, takže se pro ně kód '
            . 'nevydává vůbec.',
    ];

    /**
     * Kód změny pro daný druh povinnosti.
     *
     * Význam písmen dokládá anotace připnutého XSD; u druhů, kde ani schéma
     * jediný kód neurčuje, metoda dál končí `zp_change_code_mapping_undocumented`
     * s konkrétním důvodem místo odhadu.
     */
    public function codeFor(HealthNotificationDutyKind $kind): string
    {
        $code = self::DOCUMENTED_CODE_FOR_DUTY[$kind->value] ?? null;
        if ($code !== null) {
            return $code;
        }

        throw new HealthNotificationException(
            'zp_change_code_mapping_undocumented',
            sprintf(
                'Kód změny pro povinnost „%s" se neodhaduje. %s',
                $kind->value,
                self::UNMAPPED_DUTY_REASON[$kind->value]
                    ?? 'Schéma pro tenhle druh povinnosti kód nedokládá.',
            ),
        );
    }

    /** Druhy povinnosti, ke kterým schéma kód doloženě určuje. */
    public function isCodeMappingDocumented(
        HealthNotificationDutyKind $kind,
    ): bool {
        return isset(self::DOCUMENTED_CODE_FOR_DUTY[$kind->value]);
    }
}
