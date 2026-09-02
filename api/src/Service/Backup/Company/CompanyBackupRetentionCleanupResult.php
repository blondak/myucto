<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Souhrn opakovatelného retenčního průchodu vhodný pro log a metriky. */
final readonly class CompanyBackupRetentionCleanupResult
{
    public function __construct(
        public int $candidateCount,
        public int $expiredCount,
        public int $deferredCount,
    ) {
        if ($candidateCount < 0
            || $expiredCount < 0
            || $deferredCount < 0
            || $expiredCount + $deferredCount > $candidateCount
        ) {
            throw new \InvalidArgumentException(
                'Souhrn retenčního úklidu záloh není platný.',
            );
        }
    }

    /** Kandidát už mezitím změnil stav, typicky jej dokončil souběžný cleanup. */
    public function skippedCount(): int
    {
        return $this->candidateCount
            - $this->expiredCount
            - $this->deferredCount;
    }
}
