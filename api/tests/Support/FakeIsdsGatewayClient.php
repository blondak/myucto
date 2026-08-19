<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsConceptMessage;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayClient;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayCredential;
use MyInvoice\Service\Submission\Channel\Isds\Gateway\IsdsGatewayRegistration;
use MyInvoice\Service\Submission\Channel\Isds\IsdsTransportTimeout;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * Dvojník odesílací brány. Žádný test nesahá na síť a už vůbec ne na ostrou
 * datovou schránku.
 *
 * `$pushedConcepts` je to nejdůležitější, co dvojník nabízí: testy izolace
 * tenantů kontrolují, že při odmítnutém pokusu je toto pole PRÁZDNÉ — tedy že
 * se cizí zpráva ani nezačala vkládat, ne jen že se metoda zavolala se správným
 * argumentem.
 */
final class FakeIsdsGatewayClient implements IsdsGatewayClient
{
    /** @var list<array{session_id:string,recipient:string,sender_ident:string,sha256:string}> */
    public array $pushedConcepts = [];

    /** @var list<string> */
    public array $exchangedSessions = [];

    public int $logoutCalls = 0;

    /** 'ok' | 'timeout' | 'refuse' */
    public string $exchangeBehaviour = 'ok';

    /** 'ok' | 'timeout' | 'refuse' */
    public string $conceptBehaviour = 'ok';

    public string $nextConceptId = 'KONCEPT-1';
    public string $nextMessageId = 'DM-9000';

    /** Co vrátí druhé volání `exchangeSession` (po schválení uživatelem). */
    public ?string $outcomeStatusCode = IsdsGatewayCredential::STATUS_OK;
    public ?string $outcomeStatusMessage = 'Provedeno úspěšně.';

    /** Přepne dvojníka do fáze „uživatel už rozhodl". */
    public bool $conceptResolved = false;

    public function exchangeSession(IsdsGatewayRegistration $registration, string $sessionId): IsdsGatewayCredential
    {
        // „Získání informací (timeLimitedId) z ISDS za pomocí tohoto daného
        // sessionId je možné pouze jednou." (kap. 2.6 bod 5) — dvojník to
        // vynucuje, protože právě na tom stojí bezpečnost při obnovení stránky.
        if (in_array($sessionId, $this->exchangedSessions, true)) {
            $this->exchangedSessions[] = $sessionId;

            throw new SubmissionChannelException(
                'isds_gateway_session_rejected',
                'Datová schránka už tuhle relaci nezná. Spusťte odeslání znovu.',
                409,
            );
        }
        $this->exchangedSessions[] = $sessionId;

        if ($this->exchangeBehaviour === 'timeout') {
            throw new IsdsTransportTimeout('isds_gateway_credential_unavailable', 'Spojení se přerušilo.');
        }
        if ($this->exchangeBehaviour === 'refuse') {
            throw new SubmissionChannelException(
                'isds_gateway_session_rejected',
                'Datová schránka relaci nezná.',
                409,
            );
        }

        $resolved = $this->conceptResolved;

        return new IsdsGatewayCredential(
            timeLimitedId: SensitiveValue::fromProducer(static fn (): string => 'T01-tajne'),
            appToken: null,
            conceptDmId: $resolved && $this->outcomeStatusCode === IsdsGatewayCredential::STATUS_OK
                ? $this->nextMessageId
                : null,
            conceptStatusCode: $resolved ? $this->outcomeStatusCode : null,
            conceptStatusMessage: $resolved ? $this->outcomeStatusMessage : null,
        );
    }

    public function setConcept(
        IsdsGatewayRegistration $registration,
        IsdsGatewayCredential $credential,
        IsdsConceptMessage $message,
    ): string {
        if ($this->conceptBehaviour === 'timeout') {
            throw new IsdsTransportTimeout('isds_gateway_concept_unavailable', 'Spojení se přerušilo.');
        }
        if ($this->conceptBehaviour === 'refuse') {
            throw new SubmissionChannelException(
                'isds_gateway_concept_rejected',
                'Datová schránka koncept nepřijala.',
                409,
            );
        }

        $message->assertValid();
        $this->pushedConcepts[] = [
            'session_id' => end($this->exchangedSessions) ?: '',
            'recipient' => $message->recipientBoxId,
            'sender_ident' => $message->senderIdent,
            'sha256' => $message->payloadSha256(),
        ];

        return $this->nextConceptId;
    }

    public function logout(IsdsGatewayRegistration $registration, IsdsGatewayCredential $credential): void
    {
        ++$this->logoutCalls;
    }
}
