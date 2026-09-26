<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * Žádost o dávku a podklady pro její výplatu — část datové věty, kterou mají
 * jen ošetřovné (OSE), dlouhodobé ošetřovné (DLO), otcovská (OPP) a peněžitá
 * pomoc v mateřství (PPM).
 *
 * ## Zaměstnavatel žádost přijímá, nevymýšlí
 *
 * § 97 odst. 1 zák. č. 187/2006 Sb. ukládá zaměstnavateli žádosti o dávky
 * s výjimkou nemocenského PŘIJÍMAT a neprodleně PŘEDÁVAT územní správě. Údaje
 * o dítěti, o ošetřované osobě, o důvodu péče nebo otcovské proto opisuje
 * z žádosti, kterou mu zaměstnanec předal. Prohlášení, které zaměstnanec
 * neučinil, zůstává `null` a do věty se nedostane; ČSSZ si ho pak vyžádá
 * sama. Zásady NEMPRI pro takové prohlášení počítají s odpovědí „NE“, ne
 * s tím, že podání neodejde.
 *
 * ## Akce u ošetřovného
 *
 * `oseVznik`, `oseTrvani` a `oseUkonceni` (u DLO `dlo*`) jsou v XSD povinné
 * a ČSSZ odmítá větu, ve které není pravdivá ani jedna. Potvrzení
 * zaměstnavatele, rozhodné období, pracovní volno i den, od kterého se
 * o ošetřovné žádá, patří JEN k akci vznik — ČSSZ je u samotného trvání nebo
 * ukončení odmítá. Střídání ošetřujících osob se vykáže tak, že první osoba
 * péči ukončí (akce ukončení) a druhá podá vlastní žádost (akce vznik);
 * u DLO nese střídání navíc výslovný příznak `jeStridani`.
 */
final readonly class NempriBenefitApplication
{
    public const CARE_REASON_ILL = 'ill';
    public const CARE_REASON_QUARANTINE = 'quarantine';
    public const CARE_REASON_CANNOT_CARE = 'cannot_care';
    public const CARE_REASON_SCHOOL_CLOSED = 'school_closed';

    /** @var list<string> */
    public const CARE_REASONS = [
        self::CARE_REASON_ILL,
        self::CARE_REASON_QUARANTINE,
        self::CARE_REASON_CANNOT_CARE,
        self::CARE_REASON_SCHOOL_CLOSED,
    ];

    /**
     * @param list<array{from:string,to:string}> $careDays `pecovalVeDnech`
     * @param list<array{from:string,to:string}> $workDays `seznamPraceVeDnech`
     */
    public function __construct(
        public bool $actionStart = true,
        public bool $actionContinuation = false,
        public bool $actionEnd = false,
        public ?string $fromDate = null,
        public ?string $toDate = null,
        public ?NempriPerson $person = null,
        public ?string $careReason = null,
        public ?string $schoolName = null,
        public ?string $schoolBusinessId = null,
        public ?bool $sharedHousehold = null,
        public ?bool $loneCaregiver = null,
        public ?bool $childUnder16 = null,
        public ?bool $otherMaternityClaim = null,
        public ?bool $otherParentalClaim = null,
        public ?bool $otherPersonS57 = null,
        public ?bool $caredPersonally = null,
        public array $careDays = [],
        public ?string $relationshipCode = null,
        public ?bool $alternation = null,
        public ?string $paternityReason = null,
        public ?string $maternityCareReason = null,
        public ?int $childOrder = null,
        public ?bool $workedLastDay = null,
        public ?string $shiftHoursLastDay = null,
        public ?string $hoursWorkedLastDay = null,
        public ?bool $plannedShifts = null,
        public ?bool $plannedShiftsWorked = null,
        public ?string $returnedOn = null,
        public array $workDays = [],
    ) {}
}
