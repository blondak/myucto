<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Tenantově omezené čtení a řízení existujících zálohových jobů. */
interface CompanyBackupJobManager
{
    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, int $limit = 20): array;

    /** @return array<string,mixed> */
    public function detail(string $backupId, int $supplierId): array;

    /** @return array{job:array<string,mixed>,changed:bool} */
    public function cancel(string $backupId, int $supplierId): array;

    /**
     * @return array{
     *   job:array<string,mixed>,
     *   changed:bool,
     *   sha256:?string
     * }
     */
    public function deleteArtifact(string $backupId, int $supplierId): array;
}
