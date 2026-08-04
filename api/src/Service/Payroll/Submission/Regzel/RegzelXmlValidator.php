<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Regzel;

use DOMDocument;

final readonly class RegzelXmlValidator
{
    public function __construct(private RegzelSchemaCatalog $schemas) {}

    public function validate(
        RegzelPayloadSnapshot $snapshot,
        string $xml,
    ): void {
        $this->validateSnapshot($snapshot);
        $schema = $this->schemas->schemaFor($snapshot->interaction);
        $expectedXml = (new RegzelXmlGenerator())->generate($snapshot);
        if (!hash_equals(hash('sha256', $expectedXml), hash('sha256', $xml))) {
            throw new RegzelValidationException(
                'regzel_xml_snapshot_mismatch',
                'REGZEL XML neodpovídá přesnému zdrojovému snapshotu.',
            );
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        $valid = $loaded
            && $document->schemaValidate($schema['path']);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$valid) {
            $messages = array_map(
                static fn (\LibXMLError $error): string =>
                    trim($error->message),
                $errors,
            );
            throw new RegzelValidationException(
                'regzel_xsd_validation_failed',
                'REGZEL XML neprošlo připnutým XSD: '
                    . implode('; ', array_unique($messages)),
            );
        }
    }

    public function validateSnapshot(RegzelPayloadSnapshot $snapshot): void
    {
        if ($snapshot->supplierId <= 0 || $snapshot->officeId <= 0) {
            $this->invalid('regzel_source_scope_invalid', 'Firma nebo účtárna není platná.');
        }
        if (!in_array($snapshot->environment, ['production', 'test'], true)) {
            $this->invalid('regzel_environment_invalid', 'Prostředí REGZEL není platné.');
        }
        $this->schemas->schemaFor($snapshot->interaction);
        if (!preg_match('/^[1-9][0-9]{2}$/', $snapshot->csszWorkplaceCode)
            || (int) $snapshot->csszWorkplaceCode < 100
            || (int) $snapshot->csszWorkplaceCode > 999
        ) {
            $this->invalid('regzel_cssz_workplace_invalid', 'Kód pracoviště ČSSZ není platný.');
        }
        $this->validateTaxOfficeCode(
            $snapshot->taxOfficeCode,
            'regzel_tax_office_invalid',
        );
        if ($snapshot->taxOfficeWorkplaceCode !== null) {
            $this->validateTaxOfficeCode(
                $snapshot->taxOfficeWorkplaceCode,
                'regzel_tax_office_workplace_invalid',
            );
        }
        if (!preg_match('/^[0-9]{10}$/', $snapshot->socialSecurityVariableSymbol)) {
            $this->invalid(
                'regzel_variable_symbol_invalid',
                'Variabilní symbol zaměstnavatele musí mít přesně deset číslic.',
            );
        }
        $testVariableSymbol = str_starts_with(
            $snapshot->socialSecurityVariableSymbol,
            '999',
        );
        if ($snapshot->environment === 'test' && !$testVariableSymbol) {
            $this->invalid(
                'regzel_test_variable_symbol_required',
                'Testovací REGZEL vyžaduje fiktivní VS začínající 999.',
            );
        }
        if ($snapshot->environment === 'production' && $testVariableSymbol) {
            $this->invalid(
                'regzel_test_variable_symbol_forbidden',
                'Fiktivní testovací VS nesmí být použit v produkčním REGZEL.',
            );
        }
        if ($snapshot->payerReferenceNumber !== null
            && !preg_match('/^[1-9][0-9]{8}$/', $snapshot->payerReferenceNumber)
        ) {
            $this->invalid(
                'regzel_payer_reference_invalid',
                'Vlastní číslo plátce musí mít devět číslic a nesmí začínat nulou.',
            );
        }
        if ($snapshot->notificationDataBoxId !== null
            && !preg_match('/^[0-9A-Za-z]{7}$/', $snapshot->notificationDataBoxId)
        ) {
            $this->invalid(
                'regzel_data_box_invalid',
                'ID datové schránky pro notifikace musí mít sedm alfanumerických znaků.',
            );
        }
        if ($snapshot->employerSettingsRowVersion <= 0
            || $snapshot->officeRowVersion <= 0
            || $snapshot->profileRowVersion <= 0
            || trim($snapshot->supplierUpdatedAt) === ''
        ) {
            $this->invalid(
                'regzel_source_version_invalid',
                'REGZEL snapshot nemá úplnou verzi všech zdrojů.',
            );
        }
    }

    private function validateTaxOfficeCode(string $code, string $errorCode): void
    {
        if (!preg_match('/^[2-7][0-9]{3}$/', $code)
            || (int) $code < 2000
            || (int) $code > 7000
        ) {
            $this->invalid($errorCode, 'Kód finančního úřadu není platný pro REGZEL.');
        }
    }

    private function invalid(string $code, string $message): never
    {
        throw new RegzelValidationException($code, $message);
    }
}
