<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollImportedJmhzProtocolRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;

/**
 * Výslovné potvrzení, že se podání „možná doručeno" smí odeslat znovu.
 *
 * PROČ TO EXISTUJE
 * ------------------------------------------------------------------------------
 * Když odeslání spadne až po odeslání požadavku (vypršený čas, ztracená nebo
 * nečitelná odpověď), neví se, jestli ČSSZ zprávu převzala. Automatika to
 * rozhodnout neumí: pustit opakování naslepo by mohlo založit druhé podání,
 * blokovat navždy by nechalo povinnost nepodatelnou.
 *
 * POSTUP, KTERÝ SLUŽBA VYNUCUJE
 * ------------------------------------------------------------------------------
 * 1. Nejdřív dohledat protokol. GUID podání generujeme my a ČSSZ ho v protokolu
 *    vrací, takže načtený protokol se stejným GUID dokládá, že originál u ČSSZ
 *    je. Pak se opakování odmítne.
 * 2. Pokus, na který ČSSZ už odpověděla „shodné podání existuje" (20022),
 *    se neopakuje vůbec.
 * 3. Jinak účetní potvrdí opakování s důvodem. Pokus přejde do `expired`
 *    s kódem `retry_confirmed_by_user` a zůstává v historii; odesílá se pak
 *    TÝŽ zmrazený dokument se stejným GUID. Když originál u ČSSZ přece jen
 *    je, odpoví kontrolou 22 a aplikace to pozná jako „originál je u ČSSZ".
 */
final readonly class PayrollSubmissionRetryConfirmationService
{
    public function __construct(
        private PayrollSubmissionTransportAttemptRepository $attempts,
        private PayrollImportedJmhzProtocolRepository $protocols,
        private JmhzFrozenPayloadReader $frozen,
    ) {}

    /**
     * @return array{confirmed_attempts:list<int>,submission_guid:?string}
     */
    public function confirm(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $reason,
    ): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException(
                'Uveďte, jak jste ověřili, že protokol originálu u ČSSZ není.'
                    . ' Bez důvodu by v historii zůstal pokus, u kterého nikdo'
                    . ' nezjistí, proč se odeslal znovu.',
            );
        }

        $open = array_values(array_filter(
            $this->attempts->listForSubmission($supplierId, $environment, $submissionId),
            static fn (array $attempt): bool
                => (string) ($attempt['status'] ?? '') === PayrollDispatchGate::POSSIBLY_DELIVERED_STATUS,
        ));
        if ($open === []) {
            throw new \DomainException(
                'Podání nemá pokus ve stavu „možná doručeno", opakování není'
                    . ' potřeba potvrzovat.',
            );
        }
        foreach ($open as $attempt) {
            if ((string) ($attempt['error_code'] ?? '') === PayrollDispatchGate::ORIGINAL_AT_CSSZ_ERROR_CODE) {
                throw new \DomainException((string) PayrollDispatchGate::possiblyDeliveredReason($attempt));
            }
        }

        $guid = $this->submissionGuid($supplierId, $environment, $submissionId);
        if ($guid !== null) {
            $found = $this->protocols->findBySubmissionGuid($supplierId, $environment, $guid);
            if ($found !== []) {
                throw new \DomainException(sprintf(
                    'K podání je načtený protokol ČSSZ se stejným GUID %s (stav „%s"),'
                        . ' originál je tedy u ČSSZ. Znovu neodesílejte; výsledek'
                        . ' najdete v načtených protokolech.',
                    $guid,
                    (string) ($found[0]['status_name'] ?? ''),
                ));
            }
        }

        $confirmed = [];
        foreach ($open as $attempt) {
            $this->attempts->markExpired(
                (int) $attempt['id'],
                PayrollDispatchGate::RETRY_CONFIRMED_ERROR_CODE,
                $reason,
                (int) $attempt['row_version'],
            );
            $confirmed[] = (int) $attempt['id'];
        }

        return ['confirmed_attempts' => $confirmed, 'submission_guid' => $guid];
    }

    /**
     * GUID podání ze zmrazené datové věty. Jiné agendy než JMHZ (registrace)
     * GUID podání nenesou; u nich se dohledává jen podle CorrelationID.
     */
    private function submissionGuid(int $supplierId, string $environment, int $submissionId): ?string
    {
        try {
            return $this->frozen->identity($supplierId, $environment, $submissionId)->submissionGuid;
        } catch (JmhzXmlException) {
            return null;
        }
    }
}
