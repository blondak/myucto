<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

/**
 * Obsah mzdového výměru (§ 136 ZP) ze schváleného snapshotu revize.
 */
final readonly class WageStatementDocumentData
{
    public const SCHEMA_VERSION = 'wage-statement-document.v1';

    /**
     * @param array<string,mixed> $snapshot
     */
    public function __construct(
        public string $snapshotSha256,
        public int $revisionNo,
        public array $snapshot,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $snapshotSha256) !== 1) {
            throw new \InvalidArgumentException('Otisk mzdového výměru není platný.');
        }
        if ($revisionNo <= 0) {
            throw new \InvalidArgumentException('Číslo revize mzdového výměru není platné.');
        }
    }

    /** @return array<string,mixed> */
    public function toTemplateData(): array
    {
        return $this->snapshot + [
            'source_snapshot_sha256' => $this->snapshotSha256,
            'revision_no' => $this->revisionNo,
        ];
    }
}
