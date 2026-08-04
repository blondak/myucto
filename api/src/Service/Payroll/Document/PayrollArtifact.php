<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final readonly class PayrollArtifact
{
    /** @param array<string,mixed>|null $manifest */
    public function __construct(
        public PayrollDocumentKind $kind,
        public string $bytes,
        public string $mimeType,
        public string $suggestedFilename,
        public string $sourceSnapshotHash,
        public string $templateVersion,
        public string $rendererVersion,
        public ?array $manifest = null,
    ) {
        if ($bytes === '') {
            throw new \InvalidArgumentException('Payroll artifact must not be empty.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceSnapshotHash) !== 1) {
            throw new \InvalidArgumentException('Payroll artifact source hash is invalid.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,159}$/D', $suggestedFilename) !== 1) {
            throw new \InvalidArgumentException('Payroll artifact filename is not opaque and safe.');
        }
        if (
            $kind !== PayrollDocumentKind::MonthlyBundle
            && !str_starts_with($bytes, '%PDF-')
        ) {
            throw new \InvalidArgumentException('Payroll PDF artifact has invalid bytes.');
        }
        if (
            $kind === PayrollDocumentKind::MonthlyBundle
            && !str_starts_with($bytes, "PK")
        ) {
            throw new \InvalidArgumentException('Payroll bundle has invalid ZIP bytes.');
        }
    }

    public function fileSha256(): string
    {
        return hash('sha256', $this->bytes);
    }
}
