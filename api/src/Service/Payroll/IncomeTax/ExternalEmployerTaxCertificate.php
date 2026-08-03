<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use InvalidArgumentException;
use JsonSerializable;

final readonly class ExternalEmployerTaxCertificate implements JsonSerializable
{
    public function __construct(
        public string $certificateReference,
        public int $advanceBaseMinorUnits,
        public int $advanceTaxMinorUnits,
        public TaxEvidenceStatus $evidenceStatus,
        public ?string $evidenceReference = null,
    ) {
        if (trim($certificateReference) === '') {
            throw new InvalidArgumentException('External tax certificate reference must not be empty.');
        }
        if ($advanceBaseMinorUnits < 0 || $advanceTaxMinorUnits < 0) {
            throw new InvalidArgumentException('External tax certificate amounts cannot be negative.');
        }
        if (
            $evidenceStatus === TaxEvidenceStatus::Verified
            && trim((string) $evidenceReference) === ''
        ) {
            throw new InvalidArgumentException(
                'Verified external tax certificate requires an evidence reference.',
            );
        }
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'certificate_reference' => $this->certificateReference,
            'advance_base_minor_units' => $this->advanceBaseMinorUnits,
            'advance_tax_minor_units' => $this->advanceTaxMinorUnits,
            'evidence_status' => $this->evidenceStatus->value,
            'evidence_reference' => $this->evidenceReference,
        ];
    }
}
