<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Jedno pravidlo oznamovací povinnosti i s pramenem.
 *
 * Pramen se váže na ustanovení, které povinnost SKUTEČNĚ stanoví. Kde přesné
 * ustanovení z podkladů neplyne, zůstává {@see self::$section} `null` a stav
 * pramene to říká nahlas — vymyšlený paragraf je horší než přiznaná mezera,
 * protože ho příští novela nenajde.
 */
final readonly class HealthNotificationDutyRule
{
    public const STATUTE_VERIFIED = 'statute_verified';
    public const EXTERNAL_UNVERIFIED = 'external_unverified';

    public function __construct(
        public HealthNotificationDutyKind $kind,
        public string $label,
        public bool $employerReports,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public string $act,
        public ?string $section,
        public string $sourceStatus,
        public string $verifiedOn,
        public string $note,
    ) {}

    /** Text pramene tak, jak se smí ukázat uživateli. */
    public function source(): string
    {
        return $this->section ?? $this->act;
    }

    public function isStatutory(): bool
    {
        return $this->sourceStatus === self::STATUTE_VERIFIED;
    }

    public function appliesOn(string $date): bool
    {
        return $date >= $this->effectiveFrom
            && ($this->effectiveTo === null || $date <= $this->effectiveTo);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'label' => $this->label,
            'employer_reports' => $this->employerReports,
            'effective_from' => $this->effectiveFrom,
            'effective_to' => $this->effectiveTo,
            'act' => $this->act,
            'section' => $this->section,
            'source' => $this->source(),
            'source_status' => $this->sourceStatus,
            'verified_on' => $this->verifiedOn,
            'note' => $this->note,
        ];
    }
}
