<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionConflictException;
use MyInvoice\Repository\Payroll\PayrollSubmissionManualAcceptanceRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use Psr\Clock\ClockInterface;

/**
 * Ruční potvrzení, že úřad podání přijal.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč to existuje
 * ═══════════════════════════════════════════════════════════════════════════
 * Účetní vidí v aplikaci ČSSZ, že hlášení JMHZ přijato bylo — nebo ho tam ČSSZ
 * či ona sama opravila — ale do MyÚčta žádný ověřený protokol nedorazil:
 * nešel stáhnout, protokol hlásil chybu, kterou ČSSZ potom vyřešila u sebe,
 * nebo podání zůstalo viset ve stavu „zpracovává se". Aplikace pak dál tvrdila,
 * že měsíc hotový není, a nabízela opakované odeslání.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co dělá a co ne
 * ═══════════════════════════════════════════════════════════════════════════
 * Podání přejde na `accepted` se VŠEMI následky ověřeného přijetí
 * ({@see PayrollSubmissionService::acceptManually()}): splněná povinnost,
 * uzavřený termín, nahrazený předchůdce opravy. Nevzniká ale žádný protokol —
 * výrok člověka se drží zvlášť v neměnné evidenci (kdo, kdy v UTC, povinná
 * poznámka, varianta, datum přijetí podle ČSSZ, volitelně snímek z aplikace
 * ČSSZ jako příloha podání), aby ho nikdo nezaměnil s výrokem úřadu.
 *
 * Pozdější ověřený protokol má přednost: zapíše se běžně, a když s ručním
 * přijetím nesouhlasí, vznikne u podání nález a povinnost se vrátí ke
 * kontrole ({@see PayrollSubmissionService::importReceipt()}). Nové ruční
 * potvrzení po takovém rozporu je vědomé přebití nálezu, proto ho uzavře.
 */
final readonly class PayrollSubmissionManualAcceptanceService
{
    public const VARIANTS = ['unchanged', 'changed_by_authority'];
    public const NOTE_MIN_LENGTH = 10;
    public const NOTE_MAX_LENGTH = 1000;
    public const ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024;
    public const ATTACHMENT_MIME_TYPES = ['application/pdf', 'image/png', 'image/jpeg'];

    public function __construct(
        private PayrollSubmissionManualAcceptanceRepository $acceptances,
        private PayrollSubmissionRepository $submissionRepository,
        private PayrollSubmissionService $submissions,
        private PayrollSubmissionManualAcceptancePolicy $policy,
        private PayrollSubmissionManualAcceptanceReader $reader,
        private ClockInterface $clock,
    ) {}

    /**
     * @return array<string,mixed> výsledek s klíči `acceptance`, `submission`, `created`
     */
    public function accept(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $expectedRowVersion,
        string $variant,
        string $note,
        ?string $acceptedOn,
        ?string $attachmentBytes,
        string $idempotencyKey,
        int $recordedBy,
    ): array {
        if ($supplierId <= 0 || $submissionId <= 0 || $expectedRowVersion <= 0 || $recordedBy <= 0) {
            throw new \InvalidArgumentException('Firma, podání, verze a uživatel musí být kladná čísla.');
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException('Prostředí podání musí být test nebo production.');
        }
        if (!in_array($variant, self::VARIANTS, true)) {
            throw new \InvalidArgumentException('Zvolte, zda ČSSZ podání přijala beze změny, nebo upravené.');
        }
        $note = trim($note);
        $noteLength = mb_strlen($note, 'UTF-8');
        if ($noteLength < self::NOTE_MIN_LENGTH || $noteLength > self::NOTE_MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Interní poznámka musí mít %d až %d znaků — popište, co jste v aplikaci ČSSZ viděli.',
                self::NOTE_MIN_LENGTH,
                self::NOTE_MAX_LENGTH,
            ));
        }
        $acceptedOn = $acceptedOn === null || trim($acceptedOn) === '' ? null : trim($acceptedOn);
        if ($acceptedOn !== null) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $acceptedOn);
            if ($date === false || $date->format('Y-m-d') !== $acceptedOn) {
                throw new \InvalidArgumentException('Datum přijetí uveďte ve tvaru RRRR-MM-DD.');
            }
            if ($acceptedOn > PayrollSubmissionCalendar::today($this->clock->now())) {
                throw new \InvalidArgumentException('Datum přijetí nemůže být v budoucnu.');
            }
        }
        $attachmentSha256 = null;
        $attachmentMimeType = null;
        if ($attachmentBytes !== null) {
            if ($attachmentBytes === '' || strlen($attachmentBytes) > self::ATTACHMENT_MAX_BYTES) {
                throw new \InvalidArgumentException('Příloha musí mít 1 B až 10 MB.');
            }
            $attachmentMimeType = self::sniffMimeType($attachmentBytes);
            if ($attachmentMimeType === null) {
                throw new \InvalidArgumentException('Příloha musí být PDF, PNG nebo JPEG.');
            }
            $attachmentSha256 = hash('sha256', $attachmentBytes);
        }
        $idempotencyKey = trim($idempotencyKey);
        if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 180) {
            throw new \InvalidArgumentException('Idempotency klíč musí mít 8 až 180 bajtů.');
        }
        $idempotencyHash = hash('sha256', $idempotencyKey, true);
        $fingerprint = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-submission-manual-acceptance.v1',
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'submission_id' => $submissionId,
            'variant' => $variant,
            'note' => $note,
            'accepted_on' => $acceptedOn,
            'attachment_sha256' => $attachmentSha256,
            'attachment_mime_type' => $attachmentMimeType,
        ]));

        return $this->acceptances->transaction(function () use (
            $supplierId,
            $environment,
            $submissionId,
            $expectedRowVersion,
            $variant,
            $note,
            $acceptedOn,
            $attachmentBytes,
            $attachmentMimeType,
            $idempotencyKey,
            $idempotencyHash,
            $fingerprint,
            $recordedBy,
        ): array {
            $submission = $this->submissionRepository->lockSubmission($supplierId, $submissionId);
            if ($submission === null || $submission['environment'] !== $environment) {
                throw new \DomainException('Podání nebylo nalezeno v této firmě a prostředí.');
            }
            $replay = $this->acceptances->byIdempotencyForUpdate($supplierId, $environment, $idempotencyHash);
            if ($replay !== null) {
                if ($replay['submission_id'] !== $submissionId
                    || !hash_equals((string) $replay['request_fingerprint'], $fingerprint)
                ) {
                    throw new \DomainException('Idempotency klíč už patří jinému ručnímu potvrzení.');
                }

                return $this->result($replay, $submission, false);
            }
            $obligation = $this->submissionRepository->lockObligation(
                $supplierId,
                $submission['obligation_id'],
                $environment,
            );
            if ($obligation === null) {
                throw new \DomainException('Povinnost podání nebyla nalezena.');
            }
            $blocked = $this->policy->blockedReason(
                (string) $obligation['agenda_code'],
                $submission['status'],
                (string) $obligation['status'],
            );
            if ($blocked !== null) {
                throw new \DomainException($blocked);
            }
            if ($submission['row_version'] !== $expectedRowVersion) {
                throw new PayrollSubmissionConflictException('Podání se mezitím změnilo. Načtěte ho znovu.');
            }

            $version = $expectedRowVersion;
            $attachmentArtifactId = null;
            if ($attachmentBytes !== null && $attachmentMimeType !== null) {
                $artifact = $this->submissions->storeArtifact(
                    $supplierId,
                    $submissionId,
                    $version,
                    null,
                    'manual_attachment',
                    'internal',
                    $attachmentMimeType,
                    $attachmentBytes,
                    null,
                    null,
                    $submission['channel'],
                    $idempotencyKey . ':attachment',
                    $recordedBy,
                );
                $attachmentArtifactId = $artifact['id'];
                $version = $artifact['submission_row_version'];
            }
            $accepted = $this->submissions->acceptManually($supplierId, $submissionId, $version);
            $now = self::utc($this->clock->now());
            $this->acceptances->resolveOpenContradictions(
                $supplierId,
                $environment,
                $submissionId,
                $recordedBy,
                $now,
            );
            $row = [
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'submission_id' => $submissionId,
                'obligation_id' => $submission['obligation_id'],
                'variant' => $variant,
                'status_before' => $accepted['previous_status'],
                'submission_row_version_before' => $expectedRowVersion,
                'note' => $note,
                'authority_accepted_on' => $acceptedOn,
                'attachment_artifact_id' => $attachmentArtifactId,
                'superseded_predecessor' => $accepted['superseded_predecessor'] ? 1 : 0,
                'request_fingerprint' => $fingerprint,
                'idempotency_key_hash' => $idempotencyHash,
                'recorded_by' => $recordedBy,
                'recorded_at' => $now,
            ];
            $id = $this->acceptances->insert($row);
            $stored = $this->acceptances->latest($supplierId, $environment, $submissionId);
            if ($stored === null || $stored['id'] !== $id) {
                throw new \LogicException('Uložené ruční potvrzení se nepodařilo znovu přečíst.');
            }

            return $this->result($stored, [
                'id' => $submissionId,
                'status' => $accepted['status'],
                'row_version' => $accepted['row_version'],
            ], true);
        });
    }

    /**
     * Stav podání pro dialog: smí se potvrdit a co se už potvrdilo.
     *
     * @return array<string,mixed>|null
     */
    public function overview(int $supplierId, string $environment, int $submissionId): ?array
    {
        $submission = $this->submissionRepository->findSubmission($supplierId, $submissionId);
        if ($submission === null || (string) $submission['environment'] !== $environment) {
            return null;
        }
        $obligation = $this->submissionRepository->findObligationOfSubmission(
            $supplierId,
            $environment,
            $submissionId,
        );
        $agendaCode = (string) ($obligation['agenda_code'] ?? '');
        $blocked = $obligation === null
            ? 'Povinnost podání nebyla nalezena.'
            : $this->policy->blockedReason(
                $agendaCode,
                (string) $submission['status'],
                (string) $obligation['status'],
            );
        return [
            'submission' => [
                'id' => $submissionId,
                'environment' => $environment,
                'agenda_code' => $agendaCode,
                'status' => (string) $submission['status'],
                'row_version' => (int) $submission['row_version'],
            ],
            'supported' => $this->policy->supportsAgenda($agendaCode),
            'can_accept' => $blocked === null,
            'blocked_reason' => $blocked,
            'history' => $this->reader->history($supplierId, $environment, $submissionId),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array{id:int,status:string,row_version:int} $submission
     * @return array<string,mixed>
     */
    private function result(array $row, array $submission, bool $created): array
    {
        return [
            'acceptance' => $this->reader->present($row),
            'submission' => [
                'id' => (int) $submission['id'],
                'status' => (string) $submission['status'],
                'row_version' => (int) $submission['row_version'],
            ],
            'created' => $created,
        ];
    }

    private static function sniffMimeType(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, '%PDF-') => 'application/pdf',
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            default => null,
        };
    }

    private static function utc(\DateTimeInterface $now): string
    {
        return \DateTimeImmutable::createFromInterface($now)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
