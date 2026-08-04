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
        ], true);
    }

    public function evidenceKind(): ?string
    {
        return match ($this) {
            self::MarkFinal => 'initial_order',
            self::AuthorizeRemittance => 'remittance',
            self::DeferNoWithholding, self::DeferHold => 'deferment',
            self::ResumeHolding, self::ResumeRemittance => 'resumption',
            self::Stop => 'termination',
            default => null,
        };
    }
}
