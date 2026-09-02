<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Neměnná reprezentace připravená pro tenkou HTTP transportní vrstvu. */
final readonly class CompanyBackupPreparedDownload
{
    public function __construct(
        public CompanyBackupStoredArtifact $artifact,
        public CompanyBackupDownloadPlan $plan,
        public CompanyBackupDownloadStream $stream,
    ) {
        if ($artifact->bytes !== $plan->totalBytes
            || $plan->etag !== '"sha256:' . $artifact->sha256 . '"'
            || $stream->getSize() !== $plan->length
        ) {
            throw new \InvalidArgumentException(
                'Připravené stažení nepatří uloženému archivu.',
            );
        }
    }
}
