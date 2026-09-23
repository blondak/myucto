<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

/**
 * Jeden formulář osoby (`formularOsoby`) z měsíčního hlášení JMHZ tak, jak ho
 * přečetl {@see JmhzReportReader}. Nic se tu nedomýšlí: částky jsou celé koruny
 * přesně podle souboru, chybějící element je `null`.
 *
 * Tvary polí:
 *  - `advance`: `{base:?int, computed:?int, after_credits:?int, bonus:?int}` (10297, 10298, 10305, 10306),
 *  - `withholding`: `{base:?int, tax:?int}` (10307, 10309),
 *  - `credits`: druh slevy evidence (`taxpayer`, `disability-basic`, …) => částka nároku (10299–10302),
 *  - `childCredit`: `{monthly:?int, applied:?int, other_caregiver:?bool, caregivers:list<Person>, children:list<Child>}`,
 *    kde Person = `{given_name:string, family_name:string, birth_date:?string, birth_number:?string}`
 *    a Child = Person + `{ztp_p:bool, order:string}` (10435–10440),
 *  - `workplace`: `{city:string, municipality_code:string, country_code:string}` (10229–10231),
 *  - `fund`: `{standard:string, agreed:string, weekly:string}` (10259–10261, desetinný zápis).
 */
final readonly class JmhzReportForm
{
    public const VARIANTS = [
        'bezPriznaku',
        'pestoun',
        'cinnostKS',
        'vezen',
        'mezinarodniPronajemSily',
        'jinyPrijem',
        'ozpTpp',
        'odlozenyPrijem',
    ];

    /**
     * @param array{base:?int,computed:?int,after_credits:?int,bonus:?int}|null $advance
     * @param array{base:?int,tax:?int}|null $withholding
     * @param array<string,int> $credits
     * @param array<string,mixed>|null $childCredit
     * @param array{city:string,municipality_code:string,country_code:string}|null $workplace
     * @param array{standard:string,agreed:string,weekly:string}|null $fund
     */
    public function __construct(
        public int $position,
        public string $formGuid,
        public string $formType,
        public ?bool $primary,
        public ?string $variant,
        public ?string $personIdentifier = null,
        public ?string $employmentIdentifier = null,
        public ?string $lastName = null,
        public ?string $firstName = null,
        public ?string $birthDate = null,
        public ?string $startDate = null,
        public ?string $activityCode = null,
        public bool $hasSummary = false,
        public ?int $incomeTotal = null,
        public ?array $advance = null,
        public ?array $withholding = null,
        public ?bool $declarationSigned = null,
        public array $credits = [],
        public ?array $childCredit = null,
        public ?int $socialBase = null,
        public ?bool $socialDiscount = null,
        public ?bool $orchardDiscount = null,
        public bool $hasPosition = false,
        public ?array $workplace = null,
        public ?bool $apz = null,
        public ?string $apzInstrument = null,
        public ?bool $functionalBenefits = null,
        public ?bool $temporaryAssignment = null,
        public ?array $fund = null,
        public ?int $evidenceDays = null,
        public ?int $workedDays = null,
        public ?int $workedMillihours = null,
        public ?int $taxableIncome = null,
        public ?int $wage = null,
        public ?int $irregularBonuses = null,
        public ?int $standbyPay = null,
        public ?int $averageHourlyMilli = null,
        public ?string $insuranceFrom = null,
    ) {}

    public function hasBody(): bool
    {
        return $this->variant !== null;
    }

    /** Větev A `identifikaceType`: OIČ i ID PPV. */
    public function hasIdentifierBranch(): bool
    {
        return $this->personIdentifier !== null && $this->employmentIdentifier !== null;
    }

    public function fullName(): ?string
    {
        $name = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));

        return $name === '' ? null : $name;
    }

    /** Průměrný hodinový výdělek (10345) v haléřích, zaokrouhlený půl nahoru. */
    public function averageHourlyMinor(): ?int
    {
        return $this->averageHourlyMilli === null ? null : intdiv($this->averageHourlyMilli + 5, 10);
    }

    public function workedHoursText(): ?string
    {
        if ($this->workedMillihours === null) {
            return null;
        }

        return intdiv($this->workedMillihours, 1000) . '.'
            . str_pad((string) ($this->workedMillihours % 1000), 3, '0', STR_PAD_LEFT);
    }
}
