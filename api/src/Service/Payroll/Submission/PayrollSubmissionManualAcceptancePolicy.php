<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;

/**
 * Smí účetní podání ručně označit za přijaté?
 *
 * Ptá se na to služba zápisu i čtecí cesty, podle kterých obrazovka rozhoduje,
 * jestli tlačítko nabídnout — jedno pravidlo, aby UI nenabízelo akci, kterou
 * server odmítne.
 *
 * Agendy jsou vyjmenované úzce. U JMHZ přijetí nic dalšího z protokolu
 * nepřebírá, takže ruční přijetí má tytéž následky jako ověřené. Registrace
 * (PREZEC/REGZEC) z přijatého protokolu přebírají variabilní symbol a registraci
 * vztahu, nemocenské agendy a OZUSPOJ mají vlastní evidenci případu — tam by
 * ruční přijetí stejné následky nemělo, proto tu nejsou.
 */
final readonly class PayrollSubmissionManualAcceptancePolicy
{
    /** Kanonické kódy agend, u kterých ruční přijetí platí. */
    public const AGENDAS = [JmhzSubmissionBridgeService::AGENDA_CODE];

    public function supportsAgenda(string $agendaCode): bool
    {
        return in_array(
            PayrollDispatchCapabilityCatalog::canonical($agendaCode),
            self::AGENDAS,
            true,
        );
    }

    /** `null` = jde to; jinak věta pro účetní. */
    public function blockedReason(
        string $agendaCode,
        string $submissionStatus,
        string $obligationStatus,
    ): ?string {
        if (!$this->supportsAgenda($agendaCode)) {
            return 'Ruční potvrzení přijetí je dostupné jen u hlášení JMHZ.';
        }
        if ($obligationStatus === 'cancelled') {
            return 'Povinnost podání je zrušená.';
        }
        if ($submissionStatus === 'accepted') {
            return 'Podání už je přijaté.';
        }
        if (in_array($submissionStatus, PayrollSubmissionStateMachine::PRE_SUBMISSION_STATUSES, true)) {
            return 'Podání aplikace ještě neodeslala. Podali-li jste ho na portálu'
                . ' ČSSZ, použijte „Podáno mimo aplikaci".';
        }
        if (!in_array(
            $submissionStatus,
            PayrollSubmissionStateMachine::MANUALLY_ACCEPTABLE_STATUSES,
            true,
        )) {
            return sprintf(
                'Podání ve stavu „%s" nelze ručně označit za přijaté.',
                $submissionStatus,
            );
        }

        return null;
    }
}
