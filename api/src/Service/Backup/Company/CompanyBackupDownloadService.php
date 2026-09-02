<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use Psr\Clock\ClockInterface;

/** Doménová příprava tenantově omezeného a bezpečně navazatelného stažení. */
final readonly class CompanyBackupDownloadService implements CompanyBackupDownloadProvider
{
    public function __construct(
        private CompanyBackupJobStore $jobs,
        private CompanyBackupArtifactStorage $storage,
        private ClockInterface $clock,
    ) {}

    public function prepare(
        string $backupId,
        int $supplierId,
        ?string $rangeHeader,
        ?string $ifRangeHeader,
    ): CompanyBackupPreparedDownload {
        if ($supplierId < 1
            || !CompanyBackupManifestHeader::isCanonicalBackupId($backupId)
        ) {
            throw new CompanyBackupDownloadException('not_found');
        }

        $job = $this->jobs->find($backupId, $supplierId);
        if ($job === null) {
            throw new CompanyBackupDownloadException('not_found');
        }

        $status = CompanyBackupJobStatus::tryFrom((string) ($job['status'] ?? ''));
        if ($status === CompanyBackupJobStatus::Expired) {
            throw new CompanyBackupDownloadException('artifact_expired');
        }
        if ($status !== CompanyBackupJobStatus::Completed) {
            throw new CompanyBackupDownloadException('not_ready');
        }

        $downloadable = $this->jobs->findDownloadable(
            $backupId,
            $supplierId,
            $this->clock->now(),
        );
        if ($downloadable === null) {
            throw new CompanyBackupDownloadException('artifact_expired');
        }

        try {
            $artifact = self::artifact($downloadable);
            $plan = CompanyBackupDownloadPlan::forArchive(
                $artifact->bytes,
                $artifact->sha256,
                $rangeHeader,
                $ifRangeHeader,
            );
            $stream = $this->storage->openDownload($artifact, $plan);
        } catch (CompanyBackupJobException|\InvalidArgumentException $e) {
            throw new CompanyBackupDownloadException(
                'artifact_unavailable',
                $e,
            );
        }

        return new CompanyBackupPreparedDownload($artifact, $plan, $stream);
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
}
