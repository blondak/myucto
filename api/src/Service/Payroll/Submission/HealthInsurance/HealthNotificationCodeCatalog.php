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
 * opravované položce, zůstává metoda fail-closed i s XSD
 * ({@see self::UNMAPPED_DUTY_REASON}). Přestup mezi pojišťovnami kód dostane
 * až povinnost se směrem ({@see self::codeForDuty()}).
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
     * - `P` — „nástup do zaměstnání", u cizince viz {@see self::employmentStartCode()},
     * - `O` — „ukončení zaměstnání (u zaměstnance přihlášeného kódy „P", „A",
     *   „E" nebo „C")",
     * - `Q` — „jednodenní zaměstnání. Použije se v případě, kdy zaměstnání
     *   vznikne a zanikne v jeden den",
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
        HealthNotificationDutyKind::SingleDayEmployment->value => 'Q',
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

    /**
     * Kód změny pro KONKRÉTNÍ povinnost.
     *
     * Přestup mezi pojišťovnami druh povinnosti sám neurčí, ale povinnost se
     * směrem ano. Doslovné znění `kodZmenyZamestnaceTyp` v připnutém HOZ XSD:
     * - `O` — „Použije se v případech ukončení zaměstnání, přestupu k jiné
     *   zdravotní pojišťovně či ukončení pojištění v ČR" → dosavadní pojišťovna,
     * - `P` — nástup „… a při přestupu od jiné zdravotní pojišťovny" → nová
     *   pojišťovna.
     */
    public function codeForDuty(HealthNotificationDuty $duty): string
    {
        if ($duty->kind === HealthNotificationDutyKind::InsurerChange) {
            return match ($duty->insurerDirection) {
                HealthNotificationDuty::DIRECTION_OUTGOING => 'O',
                HealthNotificationDuty::DIRECTION_INCOMING => 'P',
                default => $this->codeFor($duty->kind),
            };
        }

        return $this->codeFor($duty->kind);
    }

    /** Dá se z téhle povinnosti doloženě vyrobit kód změny? */
    public function isDutyCodeDocumented(HealthNotificationDuty $duty): bool
    {
        if ($duty->kind === HealthNotificationDutyKind::InsurerChange) {
            return in_array(
                $duty->insurerDirection,
                [HealthNotificationDuty::DIRECTION_OUTGOING, HealthNotificationDuty::DIRECTION_INCOMING],
                true,
            );
        }

        return $this->isCodeMappingDocumented($duty->kind);
    }

    /**
     * Státy, jejichž občan se podle schématu hlásí kódem „A"/„E" jako „občan
     * EU pojištěný v ČR". Vedle členských států EU jsou tu i státy EHP
     * a Švýcarsko: koordinační nařízení (ES) č. 883/2004 se na jejich občany
     * vztahuje stejně, takže v českém zdravotním pojištění mají postavení
     * občana EU, ne „cizince ze zemí mimo EU" (kód „C").
     */
    /** Kód pojišťovny ZP MV ČR v číselníku zdravotních pojišťoven. */
    public const INSURER_MV = '211';

    private const EU_COORDINATION_COUNTRIES =
        \MyInvoice\Service\Payroll\PayrollEuFreeMovementCountries::CODES;

    /**
     * Kód nástupu podle státní příslušnosti a toho, zda pojištěnec už má
     * přidělené číslo pojištěnce.
     *
     * Doslovné znění `kodZmenyZamestnaceTyp` v připnutém HOZ XSD:
     * - `P` — nástup zaměstnance s trvalým pobytem na území ČR „nebo
     *   zaměstnance s dlouhodobým pobytem, který má již přiděleno číslo
     *   pojištěnce",
     * - `A` — „nástup do zaměstnání občana EU pojištěného v ČR, který má již
     *   přiděleno číslo pojištěnce",
     * - `E` — „první přihlášení zaměstnance - občana EU pojištěného v ČR",
     * - `C` — „první přihlášení zaměstnance - cizince ze zemí mimo EU, který
     *   nemá trvalý pobyt na území ČR".
     *
     * „Přidělené číslo" se tu pozná podle rodného čísla nebo čísla pojištěnce
     * přiděleného zdravotní pojišťovnou v evidenci osoby — jen ty schéma bere
     * jako číslo pojištěnce. Evidenční číslo ČSSZ (EČP) číslem pojištěnce
     * zdravotní pojišťovny není, takže cizinec jen s EČP se hlásí jako první
     * přihlášení.
     *
     * ZP MV ČR (kód pojišťovny 211) kódy „E" a „C" nepoužívá: zaměstnanec musí
     * být u ní předem zaregistrovaný pod přiděleným číslem pojištěnce a
     * zaměstnavatel pak použije „P" nebo „A" (Poučení ZP MV ČR 1/2026 k HOZ,
     * bod 2). Bez přiděleného čísla se tu proto první přihlášení nehlásí, ale
     * zastaví se s výzvou k registraci - kód „E"/„C" by pojišťovna odmítla.
     *
     * @param string|null $citizenshipCountryCode ISO 3166-1 alfa-2; `null` nebo
     *        `CZ` = občan ČR, u kterého se kód neodvozuje od cizinecké větve
     * @param string|null $insurerCode kód zdravotní pojišťovny, které se podání adresuje
     */
    public function employmentStartCode(
        ?string $citizenshipCountryCode,
        bool $hasAssignedInsuranceNumber,
        ?string $insurerCode = null,
    ): string {
        $country = $citizenshipCountryCode === null
            ? null
            : strtoupper(trim($citizenshipCountryCode));
        if ($country === null || $country === '' || $country === 'CZ') {
            return 'P';
        }
        $eu = in_array($country, self::EU_COORDINATION_COUNTRIES, true);
        if ($hasAssignedInsuranceNumber) {
            return $eu ? 'A' : 'P';
        }
        if ($insurerCode === self::INSURER_MV) {
            throw new HealthNotificationException(
                'zp_mv_registration_required',
                'Zaměstnanec je cizinec a nemá přidělené číslo pojištěnce. U ZP MV ČR (211) se kódy „E" a „C" '
                . 'nepoužívají: zaměstnanec musí být nejdřív zaregistrovaný u ZP MV ČR a přidělené číslo '
                . 'pojištěnce se opíše do jeho karty osoby (oddíl Identifikátory, typ „Číslo pojištěnce ZP"). '
                . 'Teprve pak lze nástup ohlásit kódem „P" nebo „A".',
            );
        }

        return $eu ? 'E' : 'C';
    }

    /** Je kód prvním přihlášením cizince, který ještě číslo pojištěnce nemá? */
    public function isFirstRegistrationCode(string $code): bool
    {
        return $code === 'E' || $code === 'C';
    }

    /**
     * Číslo pojištěnce pro první přihlášení cizince: pohlaví a datum narození
     * ve tvaru `MDDMMRRRR` (muž) nebo `ZDDMMRRRR` (žena), jak ho předepisuje
     * dokumentace prvku `cisloPojistence` v HOZ XSD („Příklad: M05071980").
     * Bez doloženého pohlaví a data narození se číslo nevymýšlí.
     */
    public function firstRegistrationInsuranceNumber(
        ?string $sex,
        ?string $birthDate,
    ): string {
        $prefix = match ($sex) {
            'male' => 'M',
            'female' => 'Z',
            default => null,
        };
        $date = is_string($birthDate)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate)
            : false;
        if ($prefix === null
            || !$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $birthDate
        ) {
            throw new HealthNotificationException(
                'zp_first_registration_identity_missing',
                'První přihlášení cizince bez přiděleného čísla pojištěnce se '
                . 'hlásí pohlavím a datem narození (tvar MDDMMRRRR / ZDDMMRRRR). '
                . 'Doplňte je v kartě osoby.',
            );
        }

        return $prefix . $date->format('dmY');
    }
}
