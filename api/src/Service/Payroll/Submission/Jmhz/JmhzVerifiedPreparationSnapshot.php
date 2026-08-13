<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzVerifiedPreparationSnapshot
{
    /**
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $readiness
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public int $id,
        public int $supplierId,
        public string $environment,
        public int $runId,
        public int $sourceRevisionId,
        public int $revisionNo,
        public string $periodStart,
        public string $periodEnd,
        public string $scenarioKey,
        public string $builderVersion,
        public string $sourceManifestSha256,
        public string $readinessSha256,
        public string $snapshotFingerprint,
        public array $manifest,
        public array $readiness,
        public array $payload,
    ) {}
}
