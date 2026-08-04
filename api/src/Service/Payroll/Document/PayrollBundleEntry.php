<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final readonly class PayrollBundleEntry
{
    public function __construct(
        public int $documentId,
        public PayrollDocumentKind $kind,
        public string $bytes,
        public string $fileSha256,
        public string $mimeType,
    ) {
        if ($documentId <= 0 || hash('sha256', $bytes) !== $fileSha256) {
            throw new \InvalidArgumentException('Payroll bundle entry integrity check failed.');
        }
    }
}
