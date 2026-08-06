<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

enum DeductionAgreementCommand: string
{
    case Activate = 'activate';
    case Pause = 'pause';
    case Resume = 'resume';
    case End = 'end';
    case Cancel = 'cancel';

    /** @return list<DeductionAgreementStatus> */
    public function allowedFrom(): array
    {
        return match ($this) {
            self::Activate => [
                DeductionAgreementStatus::Draft,
            ],
            self::Pause => [
                DeductionAgreementStatus::Active,
            ],
            self::Resume => [
                DeductionAgreementStatus::Paused,
            ],
            self::End => [
                DeductionAgreementStatus::Draft,
                DeductionAgreementStatus::Active,
                DeductionAgreementStatus::Paused,
            ],
            self::Cancel => [
                DeductionAgreementStatus::Draft,
            ],
        };
    }

    public function target(): DeductionAgreementStatus
    {
        return match ($this) {
            self::Activate, self::Resume => DeductionAgreementStatus::Active,
            self::Pause => DeductionAgreementStatus::Paused,
            self::End => DeductionAgreementStatus::Ended,
            self::Cancel => DeductionAgreementStatus::Cancelled,
        };
    }

    public function changeKind(): string
    {
        return match ($this) {
            self::Activate => 'activated',
            self::Pause => 'paused',
            self::Resume => 'resumed',
            self::End => 'ended',
            self::Cancel => 'cancelled',
        };
    }

    /**
     * Zrušení je jediný přechod, který dohodu odstraní z evidence úplně —
     * smí se proto použít jen dokud dohoda nemá jediný ledger pohyb.
     * Ukončení naproti tomu historii ledgeru vždy ponechává.
     */
    public function requiresEmptyLedger(): bool
    {
        return $this === self::Cancel;
    }

    public function closesValidity(): bool
    {
        return $this === self::End;
    }
}
