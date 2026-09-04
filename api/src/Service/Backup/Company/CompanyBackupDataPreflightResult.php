<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;

/** Neměnný výsledek úplného dvouprůchodového preflightu JSONL dat. */
final readonly class CompanyBackupDataPreflightResult
{
    public const FORMAT = 'myucto-company-data-preflight';
    public const VERSION = 1;

    public string $bindingSha256;

    public function __construct(
        public CompanyBackupExternalReferenceInventory $externalReferences,
        public int $rowCount,
        public int $identityCount,
        public int $sourceKeyCount,
        public int $sourceIndexBytes,
        public int $referenceOccurrenceCount,
        public string $technicalValidationBindingSha256,
    ) {
        if ($rowCount < 0
            || $identityCount !== $rowCount
            || $sourceKeyCount < $identityCount
            || $sourceIndexBytes < 0
            || $referenceOccurrenceCount < $externalReferences->occurrenceCount
            || preg_match(
                '/^[0-9a-f]{64}$/D',
                $technicalValidationBindingSha256,
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Výsledek datového preflightu není platný.',
            );
        }
        $this->bindingSha256 = CanonicalJson::sha256([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'technical_validation_binding_sha256' =>
                $technicalValidationBindingSha256,
            'external_references_sha256' => $externalReferences->sha256(),
            'row_count' => $rowCount,
            'identity_count' => $identityCount,
            'source_key_count' => $sourceKeyCount,
            'source_index_bytes' => $sourceIndexBytes,
            'reference_occurrence_count' => $referenceOccurrenceCount,
        ]);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'technical_validation_binding_sha256' =>
                $this->technicalValidationBindingSha256,
            'external_references' => $this->externalReferences->toArray(),
            'row_count' => $this->rowCount,
            'identity_count' => $this->identityCount,
            'source_key_count' => $this->sourceKeyCount,
            'source_index_bytes' => $this->sourceIndexBytes,
            'reference_occurrence_count' => $this->referenceOccurrenceCount,
            'binding_sha256' => $this->bindingSha256,
        ];
    }
}
