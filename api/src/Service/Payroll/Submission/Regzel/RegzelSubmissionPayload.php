<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Regzel;

final readonly class RegzelSubmissionPayload
{
    public function __construct(
        public int $supplierId,
        public int $snapshotId,
        public int $officeId,
        public string $environment,
        public string $documentType,
        public string $interactionCode,
        public string $mappingVersion,
        public string $xsdVersion,
        public string $sourceSnapshotHash,
        public string $xmlSha256,
        public string $xml,
    ) {}
}
