<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Epo;

use MyInvoice\Service\Submission\Channel\AcceptanceEvidence;
use MyInvoice\Service\Submission\Channel\AcceptanceState;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelEvidenceStrength;
use MyInvoice\Service\Submission\Channel\ChannelStatus;
use MyInvoice\Service\Submission\Channel\DispatchProbe;
use MyInvoice\Service\Submission\Channel\DispatchResult;
use MyInvoice\Service\Submission\Channel\DispatchState;
use MyInvoice\Service\Submission\Channel\OutboundSubmission;
use MyInvoice\Service\Submission\Channel\SubmissionChannel;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * EPO jako kanál podání — protějšek {@see \MyInvoice\Service\Submission\Channel\Isds\IsdsChannel}
 * na druhém konci škály důkazů.
 *
 * Existuje hlavně proto, aby abstrakce unesla oba světy najednou: EPO vrací
 * strukturovaný protokol o přijetí, datovka jen doručenku. Kdyby se rozhraní
 * psalo jen podle datovky, „přijato úřadem" by v něm nemělo kam patřit a EPO
 * by se muselo ohýbat; kdyby se psalo jen podle EPO, doručenka by se
 * nevyhnutelně někam namapovala jako přijetí.
 *
 * ── Odesílání ────────────────────────────────────────────────────────────────
 * `send()` tudy VĚDOMĚ nevede. Přímé EPO má vlastní obřad, který se nedá
 * obejít ani zjednodušit: úspěšný test se ZAREP certifikátem, step-up ověření
 * a odeslání týmž uživatelem a týmž certifikátem
 * ({@see \MyInvoice\Service\Epo\EpoDirectSubmissionService::submit()} vyžaduje
 * pokus ve stavu `test_passed` od téhož `requested_by`). Přesunout to do
 * obecné fronty by znamenalo tenhle řetěz rozvázat, a to je u daňového podání
 * ta poslední věc, kterou chceme udělat kvůli sjednocení rozhraní.
 *
 * Čtecí strana je naopak plnohodnotná: outbox díky ní ukazuje EPO i ISDS
 * podání ve stejném slovníku stavů, jen s tím rozdílem, který mezi nimi
 * doopravdy je.
 */
final readonly class EpoChannel implements SubmissionChannel
{
    public const CODE = 'epo';

    public function __construct(private EpoAttemptStatusReader $attempts) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function evidenceStrength(): ChannelEvidenceStrength
    {
        return ChannelEvidenceStrength::ProcessingProtocol;
    }

    /**
     * EPO míří na bránu podatelny, ne na schránku konkrétního úřadu — není
     * tu tedy co ověřovat. `null` je odpověď „tenhle kanál příjemce
     * neadresuje", ne „ověření se nepovedlo".
     */
    public function verifyRecipient(?string $recipientBoxId, ChannelContext $context): ?array
    {
        return null;
    }

    public function send(OutboundSubmission $submission, ChannelContext $context): DispatchResult
    {
        return DispatchResult::failed(
            'epo_requires_signing_ceremony',
            'Přiznání na EPO se odesílá na stránce podání: nejdřív test se ZAREP certifikátem, '
            . 'pak potvrzení druhým faktorem. Frontou podání to poslat nejde. '
            . 'Datovou schránkou lze totéž podání odeslat jako alternativu k EPO.',
        );
    }

    public function probe(string $correlationReference, ChannelContext $context): DispatchProbe
    {
        // Přes EPO se odsud neodesílá, takže tu není co dohledávat. „Neodešlo"
        // je tu prokazatelná pravda, ne odhad.
        return DispatchProbe::notSent('Přes frontu podání se na EPO neodesílá.');
    }

    public function fetchStatus(string $externalMessageId, ChannelContext $context): ChannelStatus
    {
        $attempt = $this->attempts->findAttempt($context->supplierId, $externalMessageId);
        if ($attempt === null) {
            throw new SubmissionChannelException(
                'epo_attempt_not_found',
                'Pokus o podání na EPO se nepodařilo dohledat.',
                404,
            );
        }

        $status = (string) $attempt['status'];
        $decidedAt = $this->parseTime($attempt['decided_at'] ?? null);

        return match ($status) {
            // Protokol podatelny = plnohodnotný důkaz o zpracování. Tohle je
            // přesně to, co datová schránka nikdy vrátit neumí.
            'confirmed' => new ChannelStatus(
                DispatchState::Delivered,
                AcceptanceState::Accepted,
                AcceptanceEvidence::EpoProtocol,
                $decidedAt ?? new \DateTimeImmutable('now'),
                $decidedAt,
                'Podatelna EPO potvrdila přijetí.',
            ),
            'rejected' => new ChannelStatus(
                DispatchState::Delivered,
                AcceptanceState::Rejected,
                AcceptanceEvidence::EpoProtocol,
                $decidedAt ?? new \DateTimeImmutable('now'),
                $decidedAt,
                $this->nullableString($attempt['error_message'] ?? null)
                    ?? 'Podatelna EPO podání odmítla.',
            ),
            'submitted', 'processing' => ChannelStatus::sentOnly('Odesláno na EPO, protokol zatím nedorazil.'),
            'uncertain' => new ChannelStatus(
                DispatchState::SendUncertain,
                note: 'U EPO není jisté, jestli podání dorazilo. Vyřešte to na stránce podání.',
            ),
            'failed', 'expired', 'cancelled' => new ChannelStatus(
                DispatchState::Failed,
                note: $this->nullableString($attempt['error_message'] ?? null) ?? 'Podání na EPO selhalo.',
            ),
            default => new ChannelStatus(DispatchState::Ready, note: 'Podání na EPO ještě nebylo odesláno.'),
        };
    }

    public function downloadConfirmation(string $externalMessageId, ChannelContext $context): ?array
    {
        return $this->attempts->confirmation($context->supplierId, $externalMessageId);
    }

    private function parseTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
