<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use DateTimeImmutable;

/** Fyzické smazání archivu před atomickým zneplatněním jeho DB metadat. */
final readonly class CompanyBackupRetentionCleanup
{
    public function __construct(
        private CompanyBackupJobStore $jobs,
        private CompanyBackupArtifactStorage $artifacts,
    ) {}

    public function run(
        DateTimeImmutable $now,
        int $limit = 200,
    ): CompanyBackupRetentionCleanupResult {
        $candidates = $this->jobs->expiredArtifacts($now, $limit);
        $expired = 0;
        $deferred = 0;

        foreach ($candidates as $candidate) {
            try {
                $artifact = self::artifactFromJob($candidate);
                $this->artifacts->remove($artifact);
            } catch (\InvalidArgumentException|CompanyBackupJobException) {
                $deferred++;
                continue;
            }

            if ($this->jobs->markArtifactRemoved($artifact)) {
                $expired++;
            }
        }

        return new CompanyBackupRetentionCleanupResult(
            count($candidates),
            $expired,
            $deferred,
        );
    }

    /** @param array<string,mixed> $job */
    private static function artifactFromJob(
        array $job,
    ): CompanyBackupStoredArtifact {
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
