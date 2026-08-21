<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

use MyInvoice\Repository\EpoDirectSubmissionRepository;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Document\DocumentStorage;

/**
 * Co si aplikace odnese z ručně nahraných výstupů asistovaného podání.
 *
 * Asistované předání končí mimo aplikaci: účetní dokončí podání ve formuláři EPO
 * a zpátky přinese soubory. Dřív se z nich uložil jen otisk a barevný odznak, takže
 * všechno ostatní — podací číslo, rozhodný čas, identita pečeti, heslo pro dotaz na
 * stav — zůstalo zamčené v binární obálce, přestože přímý kanál to z TÉŽE potvrzenky
 * běžně čte. Tahle služba ten rozdíl smazává:
 *
 *   - dodejku rozbalí na čitelné části a shrnutí ({@see EpoConfirmationPartsArchiver}),
 *     takže detail podání ukáže „co je v dodejce" i tady;
 *   - podací číslo, rozhodný čas a HESLO pro `epo_stav` zapíše k pokusu, čímž se
 *     asistovanému podání zpřístupní „Obnovit stav" a odhalení hesla pro opis na portálu.
 *
 * Co ZÁMĚRNĚ nedělá: neoznačuje podání za podané. To je právní úkon účetní (audit §2.4)
 * a zůstává na tlačítku „Označit jako podané" — aplikace k němu jen předvyplní, co
 * v dodejce našla.
 */
final class EpoAssistedConfirmationService
{
    public function __construct(
        private readonly TaxSubmissionEpoRepository $epo,
        private readonly TaxSubmissionDocumentService $documents,
        private readonly EpoConfirmationPartsArchiver $confirmationParts,
        private readonly EpoDirectSubmissionRepository $events,
        private readonly DocumentStorage $storage,
        private readonly SecretEncryption $crypto,
    ) {}

    /**
     * @param array<string,mixed> $submission
     * @return array{
     *   artifact:array<string,mixed>,
     *   confirmation:?array<string,mixed>,
     *   hint:?array<string,mixed>
     * }
     */
    public function ingest(
        string $tmpPath,
        string $originalName,
        array $submission,
        int $supplierId,
        ?int $attemptId,
        ?int $userId,
        string $environment,
    ): array {
        $kind = $this->documents->artifactKind($originalName);
        // Bajty se čtou PŘED archivací — `ingestArtifact()` soubor z dočasného místa
        // přesune do Dokumentů, takže potom už tahle cesta neexistuje.
        $bytes = $kind === 'confirmation_p7s' ? (string) file_get_contents($tmpPath) : '';

        $result = $this->documents->ingestArtifact(
            $tmpPath,
            $originalName,
            $submission,
            $supplierId,
            $attemptId,
            $userId,
            $environment,
        );

        // Heslo pro dotaz na stav se odděluje HNED a na jednom místě, ať se nemůže stát,
        // že ho některá větev vrátí volajícímu. Odpověď z uploadu jde přímo do prohlížeče
        // a heslo má vlastní, auditovanou cestu ven.
        $confirmation = is_array($result['confirmation'] ?? null) ? $result['confirmation'] : null;
        $statePassword = is_string($confirmation['state_password'] ?? null)
            ? (string) $confirmation['state_password']
            : null;
        if ($confirmation !== null) {
            unset($confirmation['state_password']);
            $confirmation['attempt_id'] = null;
            $confirmation['status_query_available'] = false;
            $result['confirmation'] = $confirmation;
        }

        if ($kind !== 'confirmation_p7s' || $bytes === '' || $confirmation === null) {
            return $result;
        }
        if ((string) ($confirmation['verification_status'] ?? '') === 'invalid') {
            // Neověřená dodejka se archivuje jako každý jiný soubor, ale nic z ní se
            // nepřebírá. Vytáhnout podací číslo z něčeho, co neprošlo ověřením podpisu,
            // by znamenalo tvářit se, že o podání víme víc, než víme.
            return $result;
        }

        $this->archiveParts($bytes, $submission, $supplierId, $attemptId, $userId, $environment);

        $result['confirmation'] = $this->rememberOnAttempt(
            $confirmation,
            $statePassword,
            $submission,
            $supplierId,
            $attemptId,
            $userId,
        );

        return $result;
    }

    /**
     * Doplní části dodejky k podání, které je má nahrané jen jako P7S.
     *
     * Rozbalování běží až od jisté verze a jen při nahrání souboru — dřív archivovaná
     * podání by proto zůstala navždy bez shrnutí. Doplnit se to nemá jak jinak, protože
     * účetní už soubor podruhé nahrávat nebude. Idempotentní: podle hashe se tytéž
     * soubory podruhé nezaloží.
     *
     * @param array<string,mixed> $submission
     * @return array{stored:list<string>,failed:list<string>,receipt:array<string,mixed>}|null
     */
    public function backfillParts(
        array $submission,
        int $supplierId,
        ?int $userId,
        string $environment,
    ): ?array {
        $bytes = $this->archivedConfirmationBytes((int) $submission['id'], $supplierId);
        if ($bytes === null) {
            return null;
        }
        $artifact = $this->confirmationArtifact((int) $submission['id'], $supplierId);
        $attemptId = $artifact !== null && $artifact['attempt_id'] !== null
            ? (int) $artifact['attempt_id']
            : null;

        return $this->archiveParts(
            $bytes,
            $submission,
            $supplierId,
            $attemptId,
            $userId,
            $environment,
        );
    }

    /**
     * @param array<string,mixed> $submission
     * @return array{stored:list<string>,failed:list<string>,receipt:array<string,mixed>}
     */
    private function archiveParts(
        string $bytes,
        array $submission,
        int $supplierId,
        ?int $attemptId,
        ?int $userId,
        string $environment,
    ): array {
        try {
            $parts = $this->confirmationParts->archive(
                $bytes,
                $submission,
                $supplierId,
                $attemptId,
                $userId,
                $environment,
            );
        } catch (\Throwable) {
            return ['stored' => [], 'failed' => ['confirmation_parts'], 'receipt' => []];
        }

        if ($parts['failed'] !== [] && $attemptId !== null) {
            $this->events->addEvent(
                $supplierId,
                (int) $submission['id'],
                $attemptId,
                'confirmation_parts_incomplete',
                'awaiting_confirmation',
                null,
                ['stored' => $parts['stored'], 'failed' => $parts['failed']],
                $userId,
            );
        }

        return $parts;
    }

    /**
     * Zapíše k pokusu, co dodejka prokazuje — a heslo pro dotaz na stav zašifrovaně.
     *
     * Heslo chodí zvlášť od zbytku shrnutí právě proto, aby se nedalo omylem vrátit
     * volajícímu. Do UI ani do auditu nepatří; ven vede jen samostatná akce se step-up
     * ověřením ({@see EpoDirectSubmissionService::revealStatePassword()}).
     *
     * @param array<string,mixed> $confirmation
     * @param array<string,mixed> $submission
     * @return array<string,mixed>
     */
    private function rememberOnAttempt(
        array $confirmation,
        ?string $statePassword,
        array $submission,
        int $supplierId,
        ?int $attemptId,
        ?int $userId,
    ): array {
        $reference = trim((string) ($confirmation['reference'] ?? ''));
        $submittedAt = trim((string) ($confirmation['submitted_at'] ?? ''));
        if ($reference === '' || $submittedAt === '') {
            return $confirmation;
        }

        // Bez ID pokusu se dodejka váže jen k podání — typicky když účetní podala mimo
        // aplikaci a předání si tu nezaložila. Vlastní pokus se kvůli tomu nevymýšlí
        // (byla by to falešná historie); přijde vhod aspoň to poslední asistované.
        $attemptId ??= $this->latestAssistedAttemptId((int) $submission['id'], $supplierId);
        if ($attemptId === null) {
            return $confirmation;
        }

        $ciphertext = null;
        if ($statePassword !== null && $statePassword !== '') {
            try {
                $ciphertext = $this->crypto->encryptFor($statePassword, 'epo:state-password');
            } catch (\Throwable) {
                $ciphertext = null;
            }
        }

        $stored = $this->epo->recordAssistedConfirmation(
            $attemptId,
            (int) $submission['id'],
            $supplierId,
            $reference,
            $submittedAt,
            $ciphertext,
        );
        if (!$stored) {
            return $confirmation;
        }

        $confirmation['attempt_id'] = $attemptId;
        $confirmation['status_query_available'] = $ciphertext !== null;
        $this->events->addEvent(
            $supplierId,
            (int) $submission['id'],
            $attemptId,
            'assisted_confirmation_read',
            'awaiting_confirmation',
            null,
            [
                'reference' => $reference,
                'state_password_stored' => $ciphertext !== null,
                'verification_status' => $confirmation['verification_status'] ?? null,
            ],
            $userId,
        );

        return $confirmation;
    }

    private function latestAssistedAttemptId(int $submissionId, int $supplierId): ?int
    {
        $attempt = $this->epo->latestAssistedAttempt($submissionId, $supplierId);
        return $attempt !== null ? (int) $attempt['id'] : null;
    }

    /** @return array<string,mixed>|null */
    private function confirmationArtifact(int $submissionId, int $supplierId): ?array
    {
        foreach ($this->epo->artifacts($submissionId, $supplierId) as $artifact) {
            if (
                (string) ($artifact['artifact_kind'] ?? '') === 'confirmation_p7s'
                && (string) ($artifact['verification_status'] ?? '') !== 'invalid'
            ) {
                return $artifact;
            }
        }
        return null;
    }

    private function archivedConfirmationBytes(int $submissionId, int $supplierId): ?string
    {
        $artifact = $this->confirmationArtifact($submissionId, $supplierId);
        if ($artifact === null) {
            return null;
        }
        $path = $this->storage->pathFor(
            $supplierId,
            (string) $artifact['document_sha256'],
            (string) $artifact['filename'],
        );
        if (!is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);
        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }
}
