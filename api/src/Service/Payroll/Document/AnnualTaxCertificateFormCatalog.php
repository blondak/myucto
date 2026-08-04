<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use DomainException;

final class AnnualTaxCertificateFormCatalog
{
    /**
     * @return array{
     *   tax_year:int,
     *   document_kind:PayrollDocumentKind,
     *   form_number:string,
     *   ministry_form:string,
     *   pattern_number:int,
     *   valid_from:string,
     *   valid_to:string,
     *   official_url:string
     * }
     */
    public static function resolve(int $taxYear, PayrollDocumentKind $kind): array
    {
        if ($taxYear !== 2026) {
            throw new DomainException(
                "Nepodporovaný rok daňového potvrzení: {$taxYear}.",
            );
        }

        return match ($kind) {
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate => [
                'tax_year' => 2026,
                'document_kind' => PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
                'form_number' => '25 5460',
                'ministry_form' => 'MFin 5460',
                'pattern_number' => 33,
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-12-31',
                'official_url' =>
                    'https://financnisprava.gov.cz/assets/tiskopisy/5460_33.pdf',
            ],
            PayrollDocumentKind::TaxableIncomeWithholdingCertificate => [
                'tax_year' => 2026,
                'document_kind' => PayrollDocumentKind::TaxableIncomeWithholdingCertificate,
                'form_number' => '25 5460/A',
                'ministry_form' => 'MFin 5460/A',
                'pattern_number' => 12,
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-12-31',
                'official_url' =>
                    'https://financnisprava.gov.cz/assets/tiskopisy/5460-A_12.pdf',
            ],
            default => throw new DomainException(
                "Nepodporovaný druh ročního daňového potvrzení: {$kind->value}.",
            ),
        };
    }
}
