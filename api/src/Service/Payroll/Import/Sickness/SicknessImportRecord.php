<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Sickness;

use MyInvoice\Service\Payroll\Submission\Sickness\SicknessBenefitKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;

/**
 * Jedna věta NEMPRI nebo HZUPN, kterou podal předchozí mzdový program.
 *
 * Věta nese jen to, co dokument skutečně obsahuje. Čeho dokument nemá (u
 * nemocenského ani jeho NEMPRI den vzniku neschopnosti nenese, ten zná ČSSZ
 * z eNeschopenky), zůstává `null` a doplní se z evidence, nebo věta zůstane
 * zablokovaná. Nic se nedosazuje odhadem.
 */
final readonly class SicknessImportRecord
{
    public const NEMPRI25 = 'NEMPRI25';
    /** Starý formát NEMPRI od Money S3 (nemoc přes eNeschopenku). */
    public const NEMPRI20 = 'NEMPRI20';
    public const HZUPN20 = 'HZUPN20';

    /**
     * @param array<string,mixed> $caseFields sloupce `payroll_sickness_cases`, které dokument nese
     * @param list<array{from:string,to:string}> $workDays dny práce v době neschopnosti
     * @param list<string> $notes poznámky ke čtení věty, které jdou do varování náhledu
     * @param bool $personReport HZUPN podává sám dobrovolně pojištěný, ne zaměstnavatel
     * @param bool $incapacityToDerived poslední den neschopnosti věta nenese, odvodil se ze dne před návratem do práce
     */
    public function __construct(
        public string $documentType,
        public int $position,
        public int $sequence,
        public SicknessDocumentKind $document,
        public SicknessBenefitKind $kind,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $birthNumber,
        public ?string $birthDate,
        public ?string $employerVariableSymbol,
        public ?string $employerBusinessId,
        public ?string $employmentFrom,
        public ?string $employmentTo,
        public ?string $decisionNumber,
        public ?int $osszCode,
        public ?string $incapacityFrom,
        public ?string $incapacityTo,
        public ?string $decisiveTo,
        public ?string $issuedOn,
        public ?string $returnedOn,
        public array $caseFields,
        public array $workDays = [],
        public array $notes = [],
        public bool $personReport = false,
        public bool $incapacityToDerived = false,
    ) {}

    public function isHzupn(): bool
    {
        return $this->document === SicknessDocumentKind::Hzupn;
    }

    /**
     * První den, ve kterém může ve skutečnosti ležet poslední den neschopnosti.
     *
     * HZUPN nese jen datum návratu do práce, takže poslední den neschopnosti je
     * odvozený ze dne před ním. Vrací-li se zaměstnanec v pondělí, den před tím
     * je neděle, ale neschopnost mohla skončit už v pátek (evidence a NEMPRI
     * vedou skutečný konec). Odvozený den proto platí jako horní mez a okno
     * sahá zpět přes víkend. Konec, který věta nese sama, je přesný.
     */
    public function incapacityToWindowStart(): ?string
    {
        if ($this->incapacityTo === null) {
            return null;
        }
        if (!$this->incapacityToDerived) {
            return $this->incapacityTo;
        }
        $day = new \DateTimeImmutable($this->incapacityTo);
        while ((int) $day->format('N') >= 6) {
            $day = $day->modify('-1 day');
        }

        return $day->format('Y-m-d');
    }

    /** Je konec neschopnosti vedený v evidenci v souladu s tím, co věta nese nebo z čeho ho odvodila? */
    public function acceptsIncapacityTo(string $existing): bool
    {
        $start = $this->incapacityToWindowStart();

        return $start !== null && $this->incapacityTo !== null
            && $existing >= $start && $existing <= $this->incapacityTo;
    }

    public function fullName(): ?string
    {
        $name = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));

        return $name === '' ? null : $name;
    }

    /**
     * Den, ke kterému se věta k události vztahuje, pro výběr pracovního vztahu:
     * den vzniku, jinak poslední den neschopnosti, jinak první den měsíce po
     * rozhodném období.
     */
    public function anchorDate(): ?string
    {
        if ($this->incapacityFrom !== null) {
            return $this->incapacityFrom;
        }
        if ($this->incapacityTo !== null) {
            return $this->incapacityTo;
        }
        if ($this->returnedOn !== null) {
            return (new \DateTimeImmutable($this->returnedOn))->modify('-1 day')->format('Y-m-d');
        }

        return $this->eventMonthStart();
    }

    /**
     * Měsíc události podle rozhodného období: rozhodné období končí posledním
     * dnem měsíce před událostí (§ 18 odst. 3 zák. č. 187/2006 Sb.). Končí-li
     * uprostřed měsíce, jde o pravděpodobný příjem v měsíci vzniku pojištění
     * a událost leží v témže měsíci.
     */
    public function eventMonthStart(): ?string
    {
        if ($this->decisiveTo === null) {
            return null;
        }
        $end = new \DateTimeImmutable($this->decisiveTo);
        $first = $end->modify('first day of this month');
        if ($end->format('Y-m-d') === $end->modify('last day of this month')->format('Y-m-d')) {
            return $first->modify('+1 month')->format('Y-m-d');
        }

        return $first->format('Y-m-d');
    }

    public function eventMonthEnd(): ?string
    {
        $start = $this->eventMonthStart();

        return $start === null
            ? null
            : (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
    }
}
