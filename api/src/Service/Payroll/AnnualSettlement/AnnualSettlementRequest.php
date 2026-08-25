<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use DateTimeImmutable;

/**
 * Evidence žádosti o roční zúčtování a podkladů k ní.
 *
 * Odpovídá jednomu řádku `payroll_annual_settlement_requests`. Je to jediné
 * místo, kde je zaznamenané, co poplatník doložil — a proto taky jediné místo,
 * odkud se dá zjistit, PROČ se zúčtování neprovedlo.
 */
final readonly class AnnualSettlementRequest
{
    public function __construct(
        public int $taxYear,
        public AnnualSettlementRequestStatus $status,
        public ?DateTimeImmutable $requestedOn,
        public ?string $requestEvidenceReference,
        public AnnualSettlementPriorEmployers $priorEmployers,
        public ?DateTimeImmutable $priorDocumentsReceivedOn,
        public AnnualSettlementFilingObligation $filingObligation,
        public ?string $filingObligationReason,
        public AnnualSettlementAnnualClaims $annualClaims,
        public ?string $annualClaimsNote,
        public ?string $note,
        public int $rowVersion = 1,
    ) {
        if ($taxYear < 2000 || $taxYear > 2199) {
            throw new \InvalidArgumentException('Rok žádosti není platný.');
        }
        if ($status === AnnualSettlementRequestStatus::Requested && $requestedOn === null) {
            throw new \InvalidArgumentException(
                'Podaná žádost musí mít datum.',
            );
        }
        if ($status !== AnnualSettlementRequestStatus::Requested
            && $requestedOn !== null
        ) {
            throw new \InvalidArgumentException(
                'Datum žádosti smí nést jen podaná žádost.',
            );
        }
        if ($priorEmployers === AnnualSettlementPriorEmployers::AllDocumented
            && $priorDocumentsReceivedOn === null
        ) {
            throw new \InvalidArgumentException(
                'Doložené doklady předchozích plátců musí mít datum převzetí.',
            );
        }
        if ($priorEmployers !== AnnualSettlementPriorEmployers::AllDocumented
            && $priorDocumentsReceivedOn !== null
        ) {
            throw new \InvalidArgumentException(
                'Datum převzetí dokladů smí nést jen doložený stav.',
            );
        }
        if ($filingObligation === AnnualSettlementFilingObligation::Required
            && trim((string) $filingObligationReason) === ''
        ) {
            throw new \InvalidArgumentException(
                'Povinnost podat přiznání musí mít uvedený důvod.',
            );
        }
        if ($annualClaims === AnnualSettlementAnnualClaims::PresentUnsupported
            && trim((string) $annualClaimsNote) === ''
        ) {
            throw new \InvalidArgumentException(
                'Ročně uplatňované položky musí být popsané.',
            );
        }
    }

    public static function unknown(int $taxYear): self
    {
        return new self(
            $taxYear,
            AnnualSettlementRequestStatus::Unknown,
            null,
            null,
            AnnualSettlementPriorEmployers::Unknown,
            null,
            AnnualSettlementFilingObligation::Unknown,
            null,
            AnnualSettlementAnnualClaims::Unknown,
            null,
            null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'tax_year' => $this->taxYear,
            'status' => $this->status->value,
            'requested_on' => $this->requestedOn?->format('Y-m-d'),
            'request_evidence_reference' => $this->requestEvidenceReference,
            'prior_employers' => $this->priorEmployers->value,
            'prior_documents_received_on' =>
                $this->priorDocumentsReceivedOn?->format('Y-m-d'),
            'filing_obligation' => $this->filingObligation->value,
            'filing_obligation_reason' => $this->filingObligationReason,
            'annual_claims' => $this->annualClaims->value,
            'annual_claims_note' => $this->annualClaimsNote,
            'note' => $this->note,
            'row_version' => $this->rowVersion,
        ];
    }
}
