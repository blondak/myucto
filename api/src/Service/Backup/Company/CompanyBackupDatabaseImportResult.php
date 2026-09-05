<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Souhrn dokončeného databázového zápisu před commitem volající transakce. */
final readonly class CompanyBackupDatabaseImportResult
{
    public function __construct(
        public int $supplierId,
        public int $mappedGlobalRows,
        public int $insertedRows,
        public int $deferredRows,
        public int $updatedRows,
        public int $identityCount,
        public int $sourceKeyCount,
        public int $hashMappingCount,
        public int $protectedSecretCount,
    ) {
        if ($supplierId < 1
            || min(
                $mappedGlobalRows,
                $insertedRows,
                $deferredRows,
                $updatedRows,
                $identityCount,
                $sourceKeyCount,
                $hashMappingCount,
                $protectedSecretCount,
            ) < 0
            || $updatedRows > $deferredRows
            || $mappedGlobalRows + $insertedRows !== $identityCount
            || $sourceKeyCount < $identityCount
        ) {
            throw new \InvalidArgumentException(
                'Výsledek databázového importu není platný.',
            );
        }
    }
}
