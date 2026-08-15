<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

use MyInvoice\Repository\Payroll\PayrollSigningProfileRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Signing\PersonalCertificateVaultService;

/**
 * Odeslání měsíčního hlášení na VREP a dotažení výsledku zpracování.
 *
 * Celá cesta je OVĚŘENÁ PROVOZEM proti testovacímu prostředí ČSSZ: obálka,
 * odpojený podpis PKCS#7 nad původními bajty datové věty, gzip, šifrování CMS
 * na certifikát ČSSZ, endpoint, potvrzení převzetí, dotaz na stav i uzavření
 * transakce. Tahle třída z toho dělá cestu aplikace místo ručního skriptu.
 *
 * Tři věci, na kterých to jinak spadne a které tu proto drží pořádek:
 *
 * 1. **Potvrzení převzetí není přijetí podání.** VREP odpoví
 *    `Qualifier=acknowledgement` hned a zpracování běží dál; protokol přijde
 *    až na dotaz. Pokus proto končí ve stavu `awaiting_protocol`, ne
 *    `completed`, a uživateli se nehlásí hotovo.
 * 2. **Transakce se musí uzavřít.** Podací protokol to říká výslovně a
 *    aplikace, které to nedělají, porušují pravidla provozu. Uzavírá se
 *    FUNKCÍ `delete`, ne kvalifikátorem — `Qualifier=delete` vrátí
 *    „Invalid qualifier". Zjištěno pokusem, ne z dokumentace.
 * 3. **Bez zapsaného CorrelationID je podání ztracené.** Ledger proto dostane
 *    correlation reference dřív, než se cokoli dalšího stane, a je to
 *    jednorázové přiřazení.
 *
 * Produkční endpoint VREP doložený není a `JmhzVrepClient` ho neodhaduje.
 * Produkční pokus tedy skončí výjimkou — což je správně: odeslat ostré hlášení
 * na hádaný cíl znamená, že lhůta uplyne bez povšimnutí.
 */
final readonly class JmhzDispatchService
{
    public const CHANNEL = 'vrep_apep';
    private const SUBMISSION_CLASS = 'CSSZ_JMHZ';

    public function __construct(
        private PayrollSubmissionTransportAttemptRepository $attempts,
        private PayrollSigningProfileRepository $profiles,
        private PersonalCertificateVaultService $vault,
        private SecretEncryption $secrets,
        private JmhzSoftwareIdentification $software,
        private ?JmhzVrepClient $client = null,
        private JmhzAcknowledgementParser $acknowledgements = new JmhzAcknowledgementParser(),
        private JmhzProtocolParser $protocols = new JmhzProtocolParser(),
    ) {}

    /**
     * Odešle připravenou datovou větu. Idempotenční klíč je povinný: bez něj
     * by opakované kliknutí založilo druhé podání za totéž období a ČSSZ ho
     * odmítne jako duplicitu — ověřeno chybou 20022.
     */
    public function send(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $payloadXml,
        string $variableSymbol,
        string $idempotencyKey,
        ?int $actorUserId,
    ): JmhzDispatchOutcome {
        $signer = $this->signer($supplierId, $environment);
        $material = $signer->unlock();

        $sealed = (new JmhzGovTalkEnvelope(JmhzGovTalkRequestShape::documented()))->seal(
            $payloadXml,
            $variableSymbol,
            self::SUBMISSION_CLASS,
            $environment,
            $this->software,
            new JmhzDetachedSigner(),
            $material['pfx'],
            $material['password'],
        );

        // Ledger se zakládá PŘED odesláním. Kdyby se založil až potom, pád
        // mezi odesláním a zápisem by po sobě nechal podání u ČSSZ, o kterém
        // aplikace neví — a druhý pokus by narazil na duplicitu bez vysvětlení.
        $attempt = $this->attempts->open(
            $supplierId,
            $environment,
            $submissionId,
            self::CHANNEL,
            $this->attempts->nextAttemptNo($supplierId, $environment, $submissionId),
            $idempotencyKey,
            $sealed->sha256(),
            $actorUserId,
        );
        if (($attempt['status'] ?? null) !== 'prepared') {
            // Klíč už jednou prošel: vracíme původní pokus, ne druhé odeslání.
            return new JmhzDispatchOutcome($attempt);
        }

        $client = $this->client($environment);
        try {
            $response = $client->submit($sealed->sendableXml(null));
        } catch (JmhzTransportException $exception) {
            $this->recordFailure(
                $attempt,
                $exception->errorCode,
                $exception->getMessage(),
                $exception->remoteHttpStatus,
            );

            throw $exception;
        }

        // Od tohoto místa je zpráva U ČSSZ. Cokoli, co selže dál, musí zůstat
        // v ledgeru jako neúspěšný pokus, ne jako nezahájený: nečitelná
        // odpověď, potvrzení bez CorrelationID i ztracený optimistický zámek
        // by jinak nechaly řádek ve stavu `prepared`, obsluha by odeslala
        // znovu a ČSSZ by druhé podání odmítla jako duplicitu.
        try {
            $acknowledgement = $this->acknowledgements->parse(
                $response->body,
                self::SUBMISSION_CLASS,
            );
            if ($acknowledgement === null) {
                // Odpověď na podání, která není potvrzením, je buď rovnou
                // protokol o zamítnutí, nebo něco neznámého.
                $failure = $this->describeImmediateFailure($response->body);

                throw new JmhzTransportException($failure[0], $failure[1], $response->httpStatus);
            }

            $attempt = $this->attempts->markSent(
                (int) $attempt['id'],
                $acknowledgement->correlationId,
                $response->httpStatus,
                (int) $attempt['row_version'],
            );
        } catch (\Throwable $exception) {
            $this->recordFailure(
                $attempt,
                $exception instanceof JmhzTransportException
                    ? $exception->errorCode
                    : 'jmhz_dispatch_send_unresolved',
                $exception->getMessage(),
                $response->httpStatus,
            );

            throw $exception;
        }

        return new JmhzDispatchOutcome($attempt, $acknowledgement);
    }

    /**
     * Zápis neúspěchu nesmí přebít původní chybu. Když se pokus mezitím
     * posunul (jiný běh, ztracený zámek), je zápis marný — ale zahodit kvůli
     * tomu důvod, proč odeslání selhalo, by bylo horší než neúplný ledger.
     *
     * @param array<string,mixed> $attempt
     */
    private function recordFailure(
        array $attempt,
        string $errorCode,
        string $message,
        ?int $httpStatus,
    ): void {
        try {
            $this->attempts->markFailed(
                (int) $attempt['id'],
                $errorCode,
                $message,
                $httpStatus,
                null,
                (int) $attempt['row_version'],
            );
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * Poslední pokusy o odeslání, od nejnovějšího. Čte se rovnou z ledgeru:
     * jiný zdroj pravdy o tom, co odešlo, neexistuje.
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $supplierId, string $environment, int $limit = 50): array
    {
        return $this->attempts->listRecent($supplierId, $environment, $limit);
    }

    /**
     * Dotaz na výsledek zpracování. Dokud VREP odpovídá potvrzením, zpracování
     * běží a pokus zůstává otevřený — vydávat to za výsledek by znamenalo
     * uzavřít podání, o kterém nic nevíme.
     */
    public function poll(
        int $supplierId,
        string $environment,
        int $attemptId,
        string $variableSymbol,
        int $packageCount = 1,
    ): JmhzDispatchOutcome {
        $attempt = $this->requireAttempt($supplierId, $environment, $attemptId);
        $correlation = (string) ($attempt['correlation_reference'] ?? '');
        if ($correlation === '') {
            throw new JmhzTransportException(
                'jmhz_dispatch_correlation_missing',
                'Pokus nemá přidělený CorrelationID, takže se na jeho výsledek'
                    . ' nelze zeptat.',
            );
        }

        $response = $this->pollOnce($environment, $correlation, $variableSymbol, false);
        $acknowledgement = $this->acknowledgements->parse(
            $response->body,
            self::SUBMISSION_CLASS,
        );
        if ($acknowledgement !== null) {
            return new JmhzDispatchOutcome($attempt, $acknowledgement);
        }

        $report = $this->protocols->parse($response->body, $packageCount, $correlation);
        if ($report->status === JmhzSubmissionStatus::Processing) {
            return new JmhzDispatchOutcome($attempt, null, $report);
        }

        $attempt = $this->attempts->markCompleted(
            (int) $attempt['id'],
            (int) $attempt['row_version'],
        );

        return new JmhzDispatchOutcome($attempt, null, $report);
    }

    /**
     * Uzavření transakce u VREP. Volá se až po dotažení protokolu — uzavřít ji
     * dřív znamená přijít o výsledek, který se pak už nedá vyzvednout.
     */
    public function close(
        int $supplierId,
        string $environment,
        int $attemptId,
        string $variableSymbol,
    ): void {
        $attempt = $this->requireAttempt($supplierId, $environment, $attemptId);
        $correlation = (string) ($attempt['correlation_reference'] ?? '');
        if ($correlation === '') {
            return;
        }
        $this->pollOnce($environment, $correlation, $variableSymbol, true);
    }

    private function pollOnce(
        string $environment,
        string $correlation,
        string $variableSymbol,
        bool $close,
    ): JmhzVrepPollResult {
        $request = (new JmhzGovTalkEnvelope(JmhzGovTalkRequestShape::documented()))
            ->pollRequest($correlation, $variableSymbol, self::SUBMISSION_CLASS, $close);

        return $this->client($environment)->poll($correlation, $request);
    }

    private function signer(int $supplierId, string $environment): JmhzVaultEnvelopeSigner
    {
        $profile = $this->requireProfile($supplierId, $environment);

        return new JmhzVaultEnvelopeSigner(
            $this->vault,
            $this->secrets,
            (int) $profile['credential_id'],
            (int) $profile['owner_user_id'],
            $supplierId,
            $this->registeredSerial($profile),
        );
    }

    /** @return array<string,mixed> */
    private function requireProfile(int $supplierId, string $environment): array
    {
        $profile = $this->profiles->find($supplierId, $environment);
        if ($profile === null) {
            throw new JmhzTransportException(
                'jmhz_signing_profile_missing',
                'Pro tuhle firmu a prostředí není zvolený podpisový certifikát.',
                422,
            );
        }

        return $profile;
    }

    /** @param array<string,mixed> $profile */
    private function registeredSerial(array $profile): ?string
    {
        $serial = $profile['cssz_registered_serial'] ?? null;

        return is_string($serial) && $serial !== '' ? $serial : null;
    }

    /** @return array<string,mixed> */
    private function requireAttempt(int $supplierId, string $environment, int $attemptId): array
    {
        $attempt = $this->attempts->find($supplierId, $environment, $attemptId);
        if ($attempt === null) {
            throw new JmhzTransportException(
                'jmhz_dispatch_attempt_unknown',
                'Pokus o odeslání neexistuje.',
                404,
            );
        }

        return $attempt;
    }

    private function client(string $environment): JmhzVrepClient
    {
        return $this->client ?? new JmhzVrepClient(null, $environment);
    }

    /** @return array{0:string,1:string} */
    private function describeImmediateFailure(string $body): array
    {
        try {
            $report = $this->protocols->parse($body);
        } catch (JmhzTransportException) {
            return [
                'jmhz_dispatch_response_unknown',
                'VREP vrátilo odpověď, která není ani potvrzením převzetí,'
                    . ' ani protokolem o zpracování.',
            ];
        }
        $first = $report->errors[0] ?? null;

        return [
            'jmhz_dispatch_rejected',
            $first instanceof JmhzProtocolError
                ? $first->message
                : 'ČSSZ podání odmítla už při převzetí.',
        ];
    }
}
