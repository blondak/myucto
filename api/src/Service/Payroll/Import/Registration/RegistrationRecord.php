<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Registration;

use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;

/**
 * Jedna věta registrace ČSSZ (element `employee`) tak, jak ji přečetl
 * {@see RegistrationXmlReader}. Hodnoty jsou jen převzaté ze souboru — nic se tu
 * nedomýšlí ani neověřuje proti evidenci.
 *
 * Adresy mají tvar
 * `{street:?string,house_number:?string,orientation_number:?string,postal_code:?string,city:?string,country_code:?string}`.
 *
 * Věta exportu zaměstnanců z ePortálu ČSSZ (`CSSZ_EXPORT`) nese jen identitu,
 * identifikátory, druh činnosti a VS zaměstnavatele. Datum nástupu v exportu
 * není; dosadí ho {@see withDerivedStart()} z měsíčního hlášení téže dávky.
 *
 * Věta `JMHZ_DERIVED` v žádném souboru není: sestaví ji import z řady měsíčních
 * hlášení ({@see \MyInvoice\Service\Payroll\Import\Jmhz\JmhzDerivedRegistrations}) —
 * přihlášení vztahu, který dávka dokládá (akce 1).
 */
final readonly class RegistrationRecord
{
    public const CSSZ_EXPORT = 'CSSZ_EXPORT';
    public const JMHZ_DERIVED = 'JMHZ_DERIVED';

    private const DPP_ACTIVITY_CODES = ['T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'ZA', 'ZB', 'ZC'];

    /**
     * @param array<string,?string>|null $permanentAddress
     * @param array<string,?string>|null $contactAddress
     * @param array{on:string,source:string,period:string,earliest_period:string}|null $derivedStart
     *        nástup odvozený z měsíčního hlášení; `source` je `start_date` (datum nástupu
     *        z identifikace formuláře) nebo `insurance_from` (začátek pojištění v měsíci)
     */
    public function __construct(
        public string $documentType,
        public int $position,
        public int $sequence,
        public int $actionCode,
        public ?string $preparedOn = null,
        public ?string $effectiveOn = null,
        public ?string $expectedStartOn = null,
        public ?string $birthNumber = null,
        public ?string $personIdentifier = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $titlePrefix = null,
        public ?string $birthDate = null,
        public ?string $birthSurname = null,
        public ?string $birthPlace = null,
        public ?string $birthCountryCode = null,
        public ?string $sex = null,
        public ?string $citizenshipCountryCode = null,
        public ?array $permanentAddress = null,
        public ?array $contactAddress = null,
        public ?string $employmentIdentifier = null,
        public ?string $startOn = null,
        public ?string $endOn = null,
        public ?string $activityCode = null,
        public ?string $relationshipDetailCode = null,
        public bool $smallScale = false,
        public ?string $contractPlace = null,
        public ?string $workplaceCity = null,
        public ?string $workplaceMunicipalityCode = null,
        public ?string $professionCode = null,
        public ?string $positionName = null,
        public ?string $healthInsurerCode = null,
        public ?string $highestEducationCode = null,
        public ?string $employerVariableSymbol = null,
        public ?array $derivedStart = null,
        /** Úvazek nového vztahu, `{workload_basis_points:int, weekly_hours:string}`. */
        public ?array $workload = null,
        /** Poznámky k odvození věty z hlášení (co je odhad, co zkontrolovat). */
        public array $notes = [],
        /** Evidenční číslo pojištěnce (EČP) — u cizince bez rodného čísla místo něj. */
        public ?string $insuredPersonNumber = null,
        /** Začátek a konec pojistného vztahu podle exportu ČSSZ (`PojistnyVztahOd`/`Do`). */
        public ?string $insuranceFrom = null,
        public ?string $insuranceTo = null,
        /**
         * Druh vztahu, když ho věta nenese kódem činnosti, ale dokládá ho řada
         * hlášení (DPP bez účasti na pojištění). Kód činnosti má přednost.
         */
        public ?string $relationTypeHint = null,
        /**
         * Druhy vztahu, mezi kterými volí účetní v náhledu, protože je z podkladů
         * nejde rozlišit (DPP, nebo DPČ malého rozsahu). První je výchozí.
         *
         * @var list<string>
         */
        public array $relationTypeOptions = [],
        /** Nástup je jen odhad z prvního hlášeného měsíce — účetní ho doplní ze smlouvy. */
        public bool $startEstimated = false,
        /** Daňová rezidence z `taxidrezid`, `{country_code, changed_on, identifier_type, identifier}`. */
        public ?array $taxResidency = null,
        /**
         * Údaje věty REGZEC ve tvaru profilu registrace A1, jen ty, které věta
         * uvádí. Import je přenese do profilu vztahu, ať formulář registrace
         * odpovídá tomu, co ČSSZ od předchozího programu přijala.
         *
         * @var array<string,mixed>
         */
        public array $a1Profile = [],
        /** Nový VS zaměstnavatele (`comp@nvs`, 10222) — věta o změně VS nese starý i nový. */
        public ?string $employerNewVariableSymbol = null,
        /** Dřívější příjmení (`name@ona`, 10064) — není rodné příjmení, import ho nepřebírá. */
        public ?string $formerSurname = null,
        /** Variabilní číslo pojištěnce (`client@vcp`, 10060). */
        public ?string $vcp = null,
        /** Vztah skončil úmrtím zaměstnance (`job@endbydeath`, 10225). */
        public bool $endedByDeath = false,
        /** Kód důvodu ukončení z podkladů pro úřad práce (`unemplcomp@rsnterempl`/`rsnterrel`, 10380/10381). */
        public ?string $terminationReasonCode = null,
    ) {}

    public function isCsszExport(): bool
    {
        return $this->documentType === self::CSSZ_EXPORT;
    }

    public function isJmhzDerived(): bool
    {
        return $this->documentType === self::JMHZ_DERIVED;
    }

    /** @param array{on:string,source:string,period:string,earliest_period:string} $start */
    public function withDerivedStart(array $start): self
    {
        return clone($this, ['startOn' => $start['on'], 'derivedStart' => $start]);
    }

    public function fullName(): ?string
    {
        $name = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));

        return $name === '' ? null : $name;
    }

    /**
     * Druh vztahu v aplikaci podle kódu druhu činnosti (`job@rel`). Zrcadlí
     * {@see \MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily::matches()}.
     */
    public function relationType(): ?string
    {
        return $this->activityCode === null
            ? $this->relationTypeHint
            : self::relationTypeOf($this->activityCode, $this->smallScale);
    }

    /** Druh vztahu podle kódu druhu činnosti ČSSZ (10239); `null` = kód neznámý. */
    public static function relationTypeOf(string $code, bool $smallScale = false): ?string
    {
        if (preg_match('/^[1-9]$/D', $code) === 1) {
            return $smallScale ? 'small_scale_employment' : 'employment';
        }
        if (preg_match('/^[A-J]$/D', $code) === 1) {
            return 'dpc';
        }
        if (in_array($code, self::DPP_ACTIVITY_CODES, true)) {
            return 'dpp';
        }

        // K (dobrovolný pracovník pečovatelské služby) sice jde stejným
        // formulářem, ale členem orgánu není; druh vztahu import nehádá.
        return $code !== 'K' && PayrollEmploymentJmhzActivityFamily::isCorporateBodyActivity($code)
            ? 'statutory_body'
            : null;
    }

    /**
     * Začátek pojistného vztahu z exportu je dnem nástupu jen u vztahu, který
     * se přihlašuje k nástupu. Zaměstnání malého rozsahu a DPP jsou účastny
     * pojištění jen v měsících s rozhodným příjmem, takže začátek pojištění
     * nástup být nemusí (pokyny MPSV k 10223).
     */
    public function insuranceStartIsEmploymentStart(): bool
    {
        return $this->insuranceFrom !== null && $this->insuredFromStartToEnd();
    }

    /** Konec pojistného vztahu z exportu je skončením vztahu (stejné výhrady jako u začátku). */
    public function insuranceEndIsEmploymentEnd(): bool
    {
        return $this->insuranceTo !== null && $this->insuredFromStartToEnd();
    }

    private function insuredFromStartToEnd(): bool
    {
        return !$this->smallScale
            && in_array($this->relationType(), ['employment', 'dpc', 'statutory_body'], true);
    }

    /** Den, ke kterému se údaje věty vztahují. */
    public function decisiveDate(): ?string
    {
        return match (true) {
            $this->documentType === 'PREZEC26' => $this->expectedStartOn ?? $this->preparedOn,
            $this->isCsszExport() => $this->startOn ?? $this->preparedOn,
            $this->actionCode === 1 => $this->startOn ?? $this->preparedOn,
            $this->actionCode === 2 => $this->endOn ?? $this->preparedOn,
            default => $this->effectiveOn ?? $this->preparedOn,
        };
    }
}
