<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/** Doménová správa jobů bez zveřejnění interního hesla, cest a diagnostiky. */
final readonly class CompanyBackupJobManagementService implements CompanyBackupJobManager
{
    public function __construct(
        private CompanyBackupJobStore $jobs,
        private CompanyBackupArtifactStorage $storage,
        private ClockInterface $clock,
    ) {}

    public function list(int $supplierId, int $limit = 20): array
    {
        if ($supplierId < 1) {
            throw new \InvalidArgumentException('Firma zálohových jobů není platná.');
        }

        return array_map(
            fn (array $job): array => $this->present($job),
            $this->jobs->listForSupplier($supplierId, $limit),
        );
    }

    public function detail(string $backupId, int $supplierId): array
    {
        return $this->present($this->owned($backupId, $supplierId));
    }

    public function cancel(string $backupId, int $supplierId): array
    {
        $job = $this->owned($backupId, $supplierId);
        $status = self::status($job);
        if (!$status->isProcessing()) {
            throw new CompanyBackupManagementException('not_cancellable');
        }
        if ((bool) ($job['cancel_requested'] ?? false)) {
            return ['job' => $this->present($job), 'changed' => false];
        }

        $changed = $this->jobs->requestCancel($backupId, $supplierId);
        $fresh = $this->jobs->find($backupId, $supplierId);
        if ($fresh === null) {
            throw new CompanyBackupManagementException('not_found');
        }
        if (!$changed) {
            if (!self::status($fresh)->isProcessing()) {
                throw new CompanyBackupManagementException('not_cancellable');
            }
            if (!(bool) ($fresh['cancel_requested'] ?? false)) {
                throw new CompanyBackupManagementException('state_conflict');
            }
        }

        return ['job' => $this->present($fresh), 'changed' => $changed];
    }

    public function deleteArtifact(string $backupId, int $supplierId): array
    {
        $job = $this->owned($backupId, $supplierId);
        $status = self::status($job);
        if ($status === CompanyBackupJobStatus::Expired) {
            return [
                'job' => $this->present($job),
                'changed' => false,
                'sha256' => null,
            ];
        }
        if ($status !== CompanyBackupJobStatus::Completed) {
            throw new CompanyBackupManagementException('not_deletable');
        }

        try {
            $artifact = self::artifact($job);
            $this->storage->remove($artifact);
        } catch (\InvalidArgumentException|CompanyBackupJobException $e) {
            throw new CompanyBackupManagementException(
                'artifact_delete_deferred',
                $e,
            );
        }

        if ($this->jobs->markArtifactRemoved($artifact)) {
            return [
                'job' => $this->present($this->owned($backupId, $supplierId)),
                'changed' => true,
                'sha256' => $artifact->sha256,
            ];
        }

        $fresh = $this->jobs->find($backupId, $supplierId);
        if ($fresh === null) {
            throw new CompanyBackupManagementException('not_found');
        }
        if (self::status($fresh) !== CompanyBackupJobStatus::Expired) {
            throw new CompanyBackupManagementException('state_conflict');
        }

        return [
            'job' => $this->present($fresh),
            'changed' => false,
            'sha256' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function owned(string $backupId, int $supplierId): array
    {
        if ($supplierId < 1
            || !CompanyBackupManifestHeader::isCanonicalBackupId($backupId)
        ) {
            throw new CompanyBackupManagementException('not_found');
        }

        $job = $this->jobs->find($backupId, $supplierId);
        if ($job === null) {
            throw new CompanyBackupManagementException('not_found');
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function present(array $job): array
    {
        $status = self::status($job);
        $artifact = null;
        if ($status === CompanyBackupJobStatus::Completed) {
            try {
                $artifact = self::artifact($job);
            } catch (\InvalidArgumentException) {
                // Nekonzistentní metadata nesmějí vytvořit download ani delete affordance.
            }
        }
        $expiresAt = $this->timestamp($job, 'expires_at');
        $downloadable = $artifact !== null
            && $expiresAt !== null
            && $expiresAt > $this->clock->now();
        $errorCode = $job['last_error_code'] ?? null;
        if (!is_string($errorCode)
            || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $errorCode) !== 1
        ) {
            $errorCode = null;
        }

        return [
            'backup_id' => (string) ($job['backup_id'] ?? ''),
            'status' => $status->value,
            'processed_steps' => (int) ($job['processed_steps'] ?? 0),
            'total_steps' => isset($job['total_steps'])
                ? (int) $job['total_steps']
                : null,
            'cancel_requested' => (bool) ($job['cancel_requested'] ?? false),
            'error_code' => $errorCode,
            'artifact_name' => $artifact?->downloadName,
            'size_bytes' => $artifact?->bytes,
            'sha256' => $artifact?->sha256,
            'entry_count' => $artifact?->entryCount,
            'expires_at' => self::formatTimestamp($expiresAt),
            'started_at' => self::formatTimestamp(
                $this->timestamp($job, 'started_at'),
            ),
            'finished_at' => self::formatTimestamp(
                $this->timestamp($job, 'finished_at'),
            ),
            'created_at' => self::formatTimestamp(
                $this->requiredTimestamp($job, 'created_at'),
            ),
            'updated_at' => self::formatTimestamp(
                $this->requiredTimestamp($job, 'updated_at'),
            ),
            'downloadable' => $downloadable,
            'cancellable' => $status->isProcessing()
                && !(bool) ($job['cancel_requested'] ?? false),
            'deletable' => $artifact !== null,
        ];
    }

    /** @param array<string,mixed> $job */
    private static function status(array $job): CompanyBackupJobStatus
    {
        $status = CompanyBackupJobStatus::tryFrom((string) ($job['status'] ?? ''));
        if ($status === null) {
            throw new \UnexpectedValueException(
                'Zálohový job obsahuje neplatný stav.',
            );
        }
        return $status;
    }

    /** @param array<string,mixed> $job */
    private static function artifact(array $job): CompanyBackupStoredArtifact
    {
        return new CompanyBackupStoredArtifact(
            (int) ($job['supplier_id'] ?? 0),
            (string) ($job['backup_id'] ?? ''),
            (string) ($job['artifact_path'] ?? ''),
            (string) ($job['artifact_name'] ?? ''),
            (int) ($job['artifact_bytes'] ?? 0),
            (string) ($job['artifact_sha256'] ?? ''),
            (int) ($job['artifact_entry_count'] ?? 0),
        );
    }

    /** @param array<string,mixed> $job */
    private function timestamp(array $job, string $field): ?DateTimeImmutable
    {
        $value = $job[$field] ?? null;
        $epoch = $job[$field . '_epoch'] ?? null;
        if ($value === null && $epoch === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '' || $epoch === null) {
            throw new \UnexpectedValueException(
                'Zálohový job obsahuje neplatný čas.',
            );
        }
        $parsed = DateTimeImmutable::createFromFormat(
            '!U.u',
            self::normalizedEpoch($epoch),
            new \DateTimeZone('UTC'),
        );
        if ($parsed === false) {
            throw new \UnexpectedValueException(
                'Zálohový job obsahuje neplatný čas.',
            );
        }
        return $parsed->setTimezone($this->clock->now()->getTimezone());
    }

    /** @param array<string,mixed> $job */
    private function requiredTimestamp(
        array $job,
        string $field,
    ): DateTimeImmutable {
        return $this->timestamp($job, $field) ?? throw new \UnexpectedValueException(
            'Zálohový job neobsahuje povinný čas.',
        );
    }

    private static function normalizedEpoch(mixed $value): string
    {
        if (is_int($value)) {
            return $value . '.000000';
        }
        if (is_float($value)) {
            $value = sprintf('%.6F', $value);
        }
        if (!is_string($value)
            || preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,6}))?$/D', $value, $match) !== 1
        ) {
            throw new \UnexpectedValueException(
                'Zálohový job obsahuje neplatný epoch čas.',
            );
        }
        return $match[1] . '.' . str_pad($match[2] ?? '', 6, '0');
    }

    private static function formatTimestamp(?DateTimeImmutable $value): ?string
    {
        return $value?->format('Y-m-d\TH:i:s.uP');
    }
}
