<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Fakta o jednom pracovním vztahu, ze kterých se odvozuje oznamovací
 * povinnost vůči zdravotní pojišťovně.
 *
 * Je to VSTUP domény, ne obraz tabulky: resolver nesmí sahat do repozitáře,
 * jinak by se hraniční případy zúžení od 2026 nedaly otestovat bez databáze.
 */
final readonly class HealthNotificationFacts
{
    /**
     * @param bool $participates zakládá vztah účast na veřejném zdravotním
     *        pojištění? Vztah bez účasti se pojišťovně nehlásí vůbec.
     */
    public function __construct(
        public int $employmentId,
        public int $employeeId,
        public string $relationType,
        public bool $participates,
        public ?string $insurerCode,
        public ?string $startedOn = null,
        public ?string $endedOn = null,
        public ?string $dataChangedOn = null,
        public ?string $previousInsurerCode = null,
        public ?string $insurerChangedOn = null,
        public ?string $maternityLeaveStartedOn = null,
        public ?string $parentalLeaveStartedOn = null,
        public ?string $maternityOrParentalLeaveEndedOn = null,
        public ?string $otherStateCategoryOccurredOn = null,
    ) {}
}
