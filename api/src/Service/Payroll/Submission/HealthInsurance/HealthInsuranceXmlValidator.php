<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

use DOMDocument;

/**
 * Dvojí kontrola datové věty: proti připnutému XSD a proti doméně.
 *
 * Doménová část není zdvojení schématu, je to jeho DOPLNĚK. Enum
 * `kodZmenyZamestnaceTyp` obsahuje i po 1. 1. 2026 všech 25 kódů, takže XSD
 * propustí i to, co zaměstnavatel podle zákona hlásit nemá. Kdo se spolehne
 * jen na schéma, podá platný dokument s nezákonným obsahem.
 *
 * Validátor si dokument serializuje znovu a porovná bajty — uložit se smí jen
 * to, co ze zdroje skutečně vzniklo.
 */
final readonly class HealthInsuranceXmlValidator
{
    public function __construct(
        private HealthInsuranceSchemaCatalog $schemas,
        private HealthNotificationCodeCatalog $codes,
        private HealthInsuranceXmlSerializer $serializer,
    ) {}

    public function validateBulkNotification(
        HealthBulkNotificationPayload $payload,
        string $xml,
    ): void {
        $payload->assertValid($this->schemas, $this->codes);
        $this->assertMatchesSource(
            $this->serializer->serializeBulkNotification($payload),
            $xml,
        );
        $this->validateAgainstSchema(
            HealthInsuranceSchemaCatalog::HOZ,
            $xml,
        );
    }

    public function validatePaymentOverview(
        HealthPaymentOverviewPayload $payload,
        string $xml,
    ): void {
        $payload->assertValid($this->schemas);
        $this->assertMatchesSource(
            $this->serializer->serializePaymentOverview($payload),
            $xml,
        );
        $this->validateAgainstSchema(
            HealthInsuranceSchemaCatalog::PPZ,
            $xml,
        );
    }

    private function assertMatchesSource(string $expected, string $actual): void
    {
        if (!hash_equals(
            hash('sha256', $expected),
            hash('sha256', $actual),
        )) {
            throw new HealthNotificationException(
                'zp_xml_snapshot_mismatch',
                'Datová věta neodpovídá přesnému zdrojovému snapshotu.',
            );
        }
    }

    private function validateAgainstSchema(
        string $documentType,
        string $xml,
    ): void {
        $schema = $this->schemas->schemaFor($documentType);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        $valid = $loaded && $document->schemaValidate($schema['path']);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($valid) {
            return;
        }
        $messages = array_unique(array_map(
            static fn (\LibXMLError $error): string => trim($error->message),
            $errors,
        ));

        throw new HealthNotificationException(
            'zp_xsd_validation_failed',
            'Datová věta neprošla připnutým XSD: '
                . implode('; ', $messages),
        );
    }
}
