<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use DomainException;

final class EnforcementCaseLifecycle
{
    public function transition(
        EnforcementCaseStatus $from,
        EnforcementCaseCommand $command,
        EnforcementTransitionContext $context,
    ): EnforcementCaseStatus {
        if ($from->isTerminal()) {
            throw new DomainException('Ukončený exekuční případ nelze znovu otevřít zkratkou.');
        }
        if (
            in_array(
                $command,
                [
                    EnforcementCaseCommand::MarkFinal,
                    EnforcementCaseCommand::AuthorizeRemittance,
                    EnforcementCaseCommand::ResumeHolding,
                    EnforcementCaseCommand::ResumeRemittance,
                ],
                true,
            )
            && !$context->evidenceComplete
        ) {
            throw new DomainException(
                'Přechod vyžaduje úplné a ověřené podklady.',
            );
        }
        if (
            in_array(
                $command,
                [
                    EnforcementCaseCommand::AuthorizeRemittance,
                    EnforcementCaseCommand::ResumeRemittance,
                ],
                true,
            )
            && !$context->recipientVerified
        ) {
            throw new DomainException('Odesílání vyžaduje ověřeného příjemce.');
        }
        if ($command->requiresDecisionDocument() && !$context->decisionVerified) {
            throw new DomainException(
                'Přechod vyžaduje ověřené rozhodnutí z dokumentů.',
            );
        }
        if ($command === EnforcementCaseCommand::MarkPaid && $context->outstandingMinorUnits !== 0) {
            throw new DomainException('Případ s nenulovým zůstatkem nelze označit za uhrazený.');
        }
        if ($command === EnforcementCaseCommand::ReleaseToAdministrator
            && $context->heldDepositMinorUnits === 0
        ) {
            throw new DomainException(
                'Případ nemá žádné depozitum, které by šlo vydat insolvenčnímu správci.',
            );
        }
        if ($command === EnforcementCaseCommand::EndAtPayer) {
            // § 295 odst. 2 o. s. ř.: skončení poměru se soudu OZNAMUJE,
            // nerozhoduje o něm. Případ proto nepotřebuje rozhodnutí, ale musí
            // být doložené, že poměr opravdu skončil, poslední mzda se srazila
            // a oznámení existuje — jinak by běh přestal srážet mzdu, kterou
            // ještě vyplácí.
            if (!$context->employmentExitSettled) {
                throw new DomainException(
                    'Případ lze u plátce ukončit až po skončení všech pracovních vztahů, '
                    . 'schválení poslední mzdy a vystavení oznámení soudu nebo exekutorovi.',
                );
            }
            if ($context->heldDepositMinorUnits > 0) {
                throw new DomainException(
                    'Případ má v depozitu sražené částky. Nejdřív je uvolněte oprávněnému, '
                    . 'vraťte zaměstnanci nebo vydejte insolvenčnímu správci.',
                );
            }
        }
        if (
            in_array(
                $command,
                [
                    EnforcementCaseCommand::DeferNoWithholding,
                    EnforcementCaseCommand::DeferHold,
                    EnforcementCaseCommand::Stop,
                    EnforcementCaseCommand::ReleaseToAdministrator,
                ],
                true,
            )
            && (
                !$context->decisionVerified
                || $context->reason === null
                || trim($context->reason) === ''
            )
        ) {
            throw new DomainException(
                'Odklad nebo zastavení vyžaduje ověřené rozhodnutí a důvod.',
            );
        }

        return match ([$from, $command]) {
            [EnforcementCaseStatus::Received, EnforcementCaseCommand::MarkFinal] =>
                EnforcementCaseStatus::WithholdAndHold,
            [EnforcementCaseStatus::WithholdAndHold, EnforcementCaseCommand::AuthorizeRemittance] =>
                EnforcementCaseStatus::Remit,
            [EnforcementCaseStatus::WithholdAndHold, EnforcementCaseCommand::DeferNoWithholding],
            [EnforcementCaseStatus::Remit, EnforcementCaseCommand::DeferNoWithholding] =>
                EnforcementCaseStatus::DeferredNoWithholding,
            [EnforcementCaseStatus::WithholdAndHold, EnforcementCaseCommand::DeferHold],
            [EnforcementCaseStatus::Remit, EnforcementCaseCommand::DeferHold] =>
                EnforcementCaseStatus::DeferredHold,
            [EnforcementCaseStatus::DeferredNoWithholding, EnforcementCaseCommand::ResumeHolding],
            [EnforcementCaseStatus::DeferredHold, EnforcementCaseCommand::ResumeHolding] =>
                EnforcementCaseStatus::WithholdAndHold,
            [EnforcementCaseStatus::DeferredNoWithholding, EnforcementCaseCommand::ResumeRemittance],
            [EnforcementCaseStatus::DeferredHold, EnforcementCaseCommand::ResumeRemittance] =>
                EnforcementCaseStatus::Remit,
            [EnforcementCaseStatus::Remit, EnforcementCaseCommand::MarkPaid] =>
                EnforcementCaseStatus::Paid,
            [EnforcementCaseStatus::Received, EnforcementCaseCommand::Stop],
            [EnforcementCaseStatus::WithholdAndHold, EnforcementCaseCommand::Stop],
            [EnforcementCaseStatus::Remit, EnforcementCaseCommand::Stop],
            [EnforcementCaseStatus::DeferredNoWithholding, EnforcementCaseCommand::Stop],
            [EnforcementCaseStatus::DeferredHold, EnforcementCaseCommand::Stop] =>
                EnforcementCaseStatus::Stopped,
            [EnforcementCaseStatus::WithholdAndHold, EnforcementCaseCommand::ReleaseToAdministrator],
            [EnforcementCaseStatus::Remit, EnforcementCaseCommand::ReleaseToAdministrator],
            [EnforcementCaseStatus::DeferredHold, EnforcementCaseCommand::ReleaseToAdministrator] =>
                EnforcementCaseStatus::DeferredNoWithholding,
            [EnforcementCaseStatus::Received, EnforcementCaseCommand::EndAtPayer],
            [EnforcementCaseStatus::WithholdAndHold, EnforcementCaseCommand::EndAtPayer],
            [EnforcementCaseStatus::Remit, EnforcementCaseCommand::EndAtPayer],
            [EnforcementCaseStatus::DeferredNoWithholding, EnforcementCaseCommand::EndAtPayer],
            [EnforcementCaseStatus::DeferredHold, EnforcementCaseCommand::EndAtPayer] =>
                EnforcementCaseStatus::EndedAtPayer,
            default => throw new DomainException(
                "Příkaz {$command->value} není povolen ze stavu {$from->value}.",
            ),
        };
    }
}
