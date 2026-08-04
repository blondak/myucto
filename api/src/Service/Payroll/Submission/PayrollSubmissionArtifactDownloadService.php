<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionArtifactDownloadGrantRepository;

final class PayrollSubmissionArtifactDownloadService
{
    public function __construct(
        private readonly PayrollSubmissionArtifactDownloadGrantRepository $grants,
        private readonly PayrollSubmissionService $submissions,
    ) {}

    /**
     * @param null|callable(array{
     *   grant_id:int,submission_id:int,artifact_id:int,environment:string,
     *   ttl_seconds:int
     * }):void $beforeCommit
     * @return array{
     *   grant_id:int,submission_id:int,artifact_id:int,token:string,
     *   expires_at:string
     * }
     */
    public function issue(
        int $supplierId,
        int $submissionId,
        int $artifactId,
        int $userId,
        int $ttlSeconds = 120,
        ?callable $beforeCommit = null,
    ): array {
        if ($supplierId <= 0
            || $submissionId <= 0
            || $artifactId <= 0
            || $userId <= 0
        ) {
            throw new \InvalidArgumentException(
                'Firma, podání, artefakt a uživatel musí být kladná čísla.',
            );
        }
        if ($ttlSeconds < 30 || $ttlSeconds > 900) {
            throw new \InvalidArgumentException(
                'Platnost download grantu musí být 30 až 900 sekund.',
            );
        }
        $token = rtrim(
            strtr(base64_encode(random_bytes(32)), '+/', '-_'),
            '=',
        );
        $tokenHash = hash('sha256', $token, true);

        $issued = $this->grants->transaction(function () use (
            $supplierId,
            $submissionId,
            $artifactId,
            $userId,
            $ttlSeconds,
            $tokenHash,
            $beforeCommit,
        ): array {
            $artifact = $this->grants->lockArtifact(
                $supplierId,
                $submissionId,
                $artifactId,
            );
            if ($artifact === null) {
                throw new \DomainException(
                    'Artefakt podání nebyl nalezen ve stejné firmě.',
                );
            }
            $now = $this->grants->currentUtcDateTime();
            $expiresAt = $now->modify("+{$ttlSeconds} seconds");
            $grantId = $this->grants->insert(
                $supplierId,
                $artifact['environment'],
                $submissionId,
                $artifactId,
                $userId,
                $tokenHash,
                $now->format('Y-m-d H:i:s.u'),
                $expiresAt->format('Y-m-d H:i:s.u'),
            );
            if ($beforeCommit !== null) {
                $beforeCommit([
                    'grant_id' => $grantId,
                    'submission_id' => $submissionId,
                    'artifact_id' => $artifactId,
                    'environment' => $artifact['environment'],
                    'ttl_seconds' => $ttlSeconds,
                ]);
            }

            return [
                'grant_id' => $grantId,
                'expires_at' => $expiresAt,
            ];
        });

        return [
            'grant_id' => $issued['grant_id'],
            'submission_id' => $submissionId,
            'artifact_id' => $artifactId,
            'token' => $token,
            'expires_at' => $issued['expires_at']->format(DATE_ATOM),
        ];
    }

    /**
     * @param null|callable(array{
     *   submission_id:int,artifact_id:int,environment:string,
     *   artifact_kind:string,byte_size:int,mime_type:string
     * }):void $beforeCommit
     * @return array{
     *   submission_id:int,artifact_id:int,bytes:string,byte_size:int,
     *   mime_type:string,suggested_filename:string
     * }
     */
    public function consume(
        int $supplierId,
        int $submissionId,
        int $artifactId,
        int $userId,
        string $token,
        ?callable $beforeCommit = null,
    ): array {
        if ($supplierId <= 0
            || $submissionId <= 0
            || $artifactId <= 0
            || $userId <= 0
        ) {
            throw new \InvalidArgumentException(
                'Firma, podání, artefakt a uživatel musí být kladná čísla.',
            );
        }
        $token = trim($token);
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            throw new \DomainException(
                'Download grant není platný nebo již vypršel.',
            );
        }
        $tokenHash = hash('sha256', $token, true);

        return $this->grants->transaction(function () use (
            $supplierId,
            $submissionId,
            $artifactId,
            $userId,
            $tokenHash,
            $beforeCommit,
        ): array {
            $grant = $this->grants->lockForConsume(
                $supplierId,
                $submissionId,
                $artifactId,
                $userId,
                $tokenHash,
            );
            $now = $this->grants->currentUtcDateTime();
            if ($grant === null
                || $grant['used_at'] !== null
                || $now > new \DateTimeImmutable(
                    $grant['expires_at'],
                    new \DateTimeZone('UTC'),
                )
            ) {
                throw new \DomainException(
                    'Download grant není platný nebo již vypršel.',
                );
            }
            $bytes = $this->submissions->artifactBytes(
                $supplierId,
                $artifactId,
            );
            if (strlen($bytes) !== $grant['byte_size']
                || !hash_equals(
                    $grant['artifact_sha256'],
                    hash('sha256', $bytes),
                )
            ) {
                throw new \DomainException(
                    'Archivovaný artefakt podání nemá platnou integritu.',
                );
            }
            $usedAt = $now->format('Y-m-d H:i:s.u');
            if (!$this->grants->consume($grant['grant_id'], $usedAt)) {
                throw new \DomainException(
                    'Download grant není platný nebo již vypršel.',
                );
            }
            $mimeType = self::safeMimeType($grant['mime_type']);
            $result = [
                'submission_id' => $submissionId,
                'artifact_id' => $artifactId,
                'bytes' => $bytes,
                'byte_size' => $grant['byte_size'],
                'mime_type' => $mimeType,
                'suggested_filename' => self::suggestedFilename(
                    $grant['agenda_code'],
                    $grant['period_start'],
                    $artifactId,
                    $mimeType,
                ),
            ];
            if ($beforeCommit !== null) {
                $beforeCommit([
                    'submission_id' => $submissionId,
                    'artifact_id' => $artifactId,
                    'environment' => $grant['environment'],
                    'artifact_kind' => $grant['artifact_kind'],
                    'byte_size' => $grant['byte_size'],
                    'mime_type' => $mimeType,
                ]);
            }

            return $result;
        });
    }

    private static function safeMimeType(string $mimeType): string
    {
        return match (strtolower(trim($mimeType))) {
            'application/xml', 'text/xml' => 'application/xml',
            'application/zip' => 'application/zip',
            'application/pdf' => 'application/pdf',
            'application/json' => 'application/json',
            'text/plain' => 'text/plain',
            default => 'application/octet-stream',
        };
    }

    private static function suggestedFilename(
        string $agendaCode,
        string $periodStart,
        int $artifactId,
        string $mimeType,
    ): string {
        $agenda = strtolower((string) preg_replace(
            '/[^A-Za-z0-9_-]+/',
            '-',
            $agendaCode,
        ));
        $agenda = trim($agenda, '-_');
        if ($agenda === '') {
            $agenda = 'podani';
        }
        $period = preg_match('/^[0-9]{4}-[0-9]{2}/D', $periodStart) === 1
            ? substr($periodStart, 0, 7)
            : 'obdobi';
        $extension = match ($mimeType) {
            'application/xml' => 'xml',
            'application/zip' => 'zip',
            'application/pdf' => 'pdf',
            'application/json' => 'json',
            'text/plain' => 'txt',
            default => 'bin',
        };

        return "mzdove-podani-{$agenda}-{$period}-artefakt-{$artifactId}.{$extension}";
    }
}
