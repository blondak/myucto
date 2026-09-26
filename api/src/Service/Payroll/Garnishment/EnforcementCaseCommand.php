<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

enum EnforcementCaseCommand: string
{
    case MarkFinal = 'mark_final';
    case AuthorizeRemittance = 'authorize_remittance';
    case DeferNoWithholding = 'defer_no_withholding';
    case DeferHold = 'defer_hold';
    case ResumeHolding = 'resume_holding';
    case ResumeRemittance = 'resume_remittance';
    case MarkPaid = 'mark_paid';
    case Stop = 'stop';
    /**
     * Vydání depozita insolvenčnímu správci po schválení oddlužení nebo
     * prohlášení konkursu. Exekuce se od téhož rozhodnutí nevykonává, proto
     * případ přejde do odkladu bez srážení.
     */
    case ReleaseToAdministrator = 'release_to_administrator';
    /** Skončení srážek u tohoto plátce po skončení pracovního poměru. */
    case EndAtPayer = 'end_at_payer';

    public function requiresDecisionDocument(): bool
    {
        return in_array($this, [
            self::MarkFinal,
            self::AuthorizeRemittance,
            self::DeferNoWithholding,
            self::DeferHold,
            self::ResumeHolding,
            self::ResumeRemittance,
            self::Stop,
            self::ReleaseToAdministrator,
        ], true);
    }

    public function evidenceKind(): ?string
    {
        return match ($this) {
            self::MarkFinal => 'initial_order',
            self::AuthorizeRemittance => 'remittance',
            self::DeferNoWithholding, self::DeferHold,
            self::ReleaseToAdministrator => 'deferment',
            self::ResumeHolding, self::ResumeRemittance => 'resumption',
            self::Stop => 'termination',
            default => null,
        };
    }
}
