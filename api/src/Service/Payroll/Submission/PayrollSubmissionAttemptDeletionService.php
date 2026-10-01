<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;

/**
 * Trvalé smazání pokusu o odeslání z historie.
 *
 * PROČ TO EXISTUJE
 * ------------------------------------------------------------------------------
 * Ledger pokusů je záměrně append-only a běžná cesta ven je zahození
 * ({@see PayrollSubmissionAbandonService}) — pokus dostane terminální stav
 * a v historii zůstane i s tím, co úřad odpověděl. Jenže po nepovedeném
 * odeslání (třeba certifikátem, který ČSSZ nemá v registru podávajících, nebo
 * chybou spojení) zůstane v přehledu viset záznam, který nic nedokládá a jen
 * mate. Tohle je páka, jak takový záznam odklidit úplně.
 *
 * CO SE SMAZAT NESMÍ: všechno, co odešlo na úřad
 * ------------------------------------------------------------------------------
 * Pokus, který úřad převzal, je doklad o ostrém podání, ať dopadl jakkoli.
 * Za převzatý se považuje pokus, u kterého platí cokoli z tohoto:
 *
 *   - stav `sent`, `awaiting_protocol`, `completed` nebo `possibly_delivered`
 *     — každý z nich vzniká až po tom, co požadavek odešel
 *     ({@see PayrollSubmissionTransportAttemptRepository::markSent()},
 *     `markCompleted()`, `markPossiblyDelivered()`),
 *   - vyplněné `sent_at` — čas, kdy úřad odeslání potvrdil,
 *   - vyplněné `correlation_reference` — identifikátor, pod kterým úřad
 *     zprávu převzal; podle něj se dohledá protokol i dodejka a bez něj by
 *     nešlo zjistit, co se odeslalo. Pokus, který úřad převzal a pak odmítl
 *     protokolem (`failed` s CorrelationID), je proto taky doklad.
 *
 * Dřív stačilo, že k CorrelationID ještě nebyla připnutá dodejka. Tím šlo
 * smazat pokus, který úřad převzal a protokol k němu jen ještě nebyl
 * dotažený — tedy ostré podání. Ta skulina je zavřená.
 *
 * Smazat jde jen pokus, který nikdy neodešel (`prepared`) nebo selhal dřív, než
 * ho úřad převzal (`failed` nebo `expired` bez `sent_at` i bez CorrelationID).
 * Rozhoduje se jen z řádku pokusu, takže stejné pravidlo může UI použít
 * k rozhodnutí, jestli tlačítko vůbec ukázat. Smazání se zapisuje do auditního
 * logu (volající), protože po řádku samotném nezůstane nic.
 */
final readonly class PayrollSubmissionAttemptDeletionService
{
    /** Stavy, do kterých se pokus dostane až po odeslání na úřad. */
    private const DISPATCHED_STATUSES = ['sent', 'awaiting_protocol', 'completed'];

    public function __construct(
        private PayrollSubmissionTransportAttemptRepository $attempts,
    ) {}

    /**
     * Vrátí důvod, proč pokus smazat nejde, nebo `null`, když jde.
     *
     * Oddělené od {@see delete()}, aby si UI mohlo tlačítko rovnou schovat
     * a nenabízelo akci, která stejně skončí chybou.
     *
     * @param array<string,mixed> $attempt
     */
    public function blockedReason(array $attempt): ?string
    {
        $status = (string) ($attempt['status'] ?? '');
        if ($status === 'completed') {
            return 'Pokus dostal od úřadu protokol o zpracování — je to doklad o odeslání'
                . ' a z historie se nemaže.';
        }
        // Smazáním by zmizela jediná stopa, že požadavek odešel, a podání by
        // šlo odeslat znovu bez potvrzení. Tudy se obejít nedá.
        if ($status === PayrollDispatchGate::POSSIBLY_DELIVERED_STATUS) {
            return 'Požadavek možná došel k ČSSZ. Pokus je jediný doklad, že odešel,'
                . ' a z historie se nemaže. Dohledejte protokol, případně potvrďte'
                . ' opakování odeslání.';
        }
        if (
            in_array($status, self::DISPATCHED_STATUSES, true)
            || trim((string) ($attempt['sent_at'] ?? '')) !== ''
            || trim((string) ($attempt['correlation_reference'] ?? '')) !== ''
        ) {
            return 'Pokus odešel na úřad a úřad ho převzal — je to doklad o ostrém podání'
                . ' a z historie se nemaže. Pokud podání nemá platit, použijte zahození'
                . ' pokusu nebo storno hlášení.';
        }

        return null;
    }

    /**
     * @return array{deleted:true,attempt_id:int,submission_id:int,attempt_no:int,channel:string,status:string,correlation_reference:?string}
     */
    public function delete(
        int $supplierId,
        string $environment,
        int $attemptId,
        int $expectedRowVersion,
    ): array {
        $attempt = $this->attempts->find($supplierId, $environment, $attemptId);
        if ($attempt === null) {
            throw new \DomainException('Pokus o odeslání nebyl nalezen.');
        }
        $blocked = $this->blockedReason($attempt);
        if ($blocked !== null) {
            throw new \DomainException($blocked);
        }

        // Snapshot PŘED smazáním — po něm už není z čeho auditní zápis složit.
        $snapshot = [
            'deleted' => true,
            'attempt_id' => (int) $attempt['id'],
            'submission_id' => (int) $attempt['submission_id'],
            'attempt_no' => (int) $attempt['attempt_no'],
            'channel' => (string) $attempt['channel'],
            'status' => (string) $attempt['status'],
            'correlation_reference' => isset($attempt['correlation_reference'])
                && (string) $attempt['correlation_reference'] !== ''
                    ? (string) $attempt['correlation_reference']
                    : null,
        ];

        $this->attempts->delete($attemptId, $expectedRowVersion);

        return $snapshot;
    }
}
