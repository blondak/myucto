<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final readonly class RenderedPayslipDocument
{
    private function __construct(
        public string $pdfBytes,
        public string $fileSha256,
        public int $sizeBytes,
        public string $mimeType,
        public string $suggestedFilename,
        public string $revisionId,
        public string $sourceSnapshotSha256,
        public string $rendererVersion,
    ) {}

    public static function fromPdf(
        string $pdfBytes,
        PayslipDocumentData $source,
        string $rendererVersion,
    ): self {
        if (!str_starts_with($pdfBytes, '%PDF-')) {
            throw new \InvalidArgumentException('Rendered payslip is not a PDF document.');
        }

        $fileSha256 = hash('sha256', $pdfBytes);

        return new self(
            $pdfBytes,
            $fileSha256,
            strlen($pdfBytes),
            'application/pdf',
            sprintf(
                'vyplatni-paska-%s-%s.pdf',
                $source->period,
                substr($fileSha256, 0, 12),
            ),
            $source->revisionId,
            $source->sourceSnapshotSha256,
            $rendererVersion,
        );
    }

    /**
     * @return array{
     *   revision_id:string,
     *   source_snapshot_sha256:string,
     *   renderer_version:string,
     *   file_sha256:string,
     *   size_bytes:int,
     *   mime_type:string,
     *   suggested_filename:string
     * }
     */
    public function metadata(): array
    {
        return [
            'revision_id' => $this->revisionId,
            'source_snapshot_sha256' => $this->sourceSnapshotSha256,
            'renderer_version' => $this->rendererVersion,
            'file_sha256' => $this->fileSha256,
            'size_bytes' => $this->sizeBytes,
            'mime_type' => $this->mimeType,
            'suggested_filename' => $this->suggestedFilename,
        ];
    }
}
