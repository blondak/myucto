<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Registration;

/**
 * Jedna věta registrace ČSSZ (element `employee`) tak, jak ji přečetl
 * {@see RegistrationXmlReader}. Hodnoty jsou jen převzaté ze souboru — nic se tu
 * nedomýšlí ani neověřuje proti evidenci.
 *
 * Adresy mají tvar
 * `{street:?string,house_number:?string,orientation_number:?string,postal_code:?string,city:?string,country_code:?string}`.
 */
final readonly class RegistrationRecord
{
    private const DPP_ACTIVITY_CODES = ['T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'ZA', 'ZB', 'ZC'];

    /**
     * @param array<string,?string>|null $permanentAddress
     * @param array<string,?string>|null $contactAddress
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
    ) {}

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
        $code = $this->activityCode;
        if ($code === null) {
            return null;
        }
        if (preg_match('/^[1-9]$/D', $code) === 1) {
            return $this->smallScale ? 'small_scale_employment' : 'employment';
        }
        if (preg_match('/^[A-J]$/D', $code) === 1) {
            return 'dpc';
        }
        if (in_array($code, self::DPP_ACTIVITY_CODES, true)) {
            return 'dpp';
        }

        return $code === 'S' ? 'statutory_body' : null;
    }

    /** Den, ke kterému se údaje věty vztahují. */
    public function decisiveDate(): ?string
    {
        return match (true) {
            $this->documentType === 'PREZEC26' => $this->expectedStartOn ?? $this->preparedOn,
            $this->actionCode === 1 => $this->startOn ?? $this->preparedOn,
            $this->actionCode === 2 => $this->endOn ?? $this->preparedOn,
            default => $this->effectiveOn ?? $this->preparedOn,
        };
    }
}
