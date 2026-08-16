<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

use DOMDocument;

/**
 * Validace evidenčního listu proti připnutému oficiálnímu schématu.
 *
 * Validace nikdy nesahá na síť — `LIBXML_NONET` a lokální `file://`
 * schemaLocation na ověřený balíček. Kromě XSD se ověřuje i bajtová
 * stabilita: XML musí být přesně to, co ze stejného snapshotu znovu
 * vygeneruje serializér.
 */
final readonly class EldpXmlValidator
{
    public function __construct(
        private EldpSchemaCatalog $schemas = new EldpSchemaCatalog(),
        private EldpXmlSerializer $serializer = new EldpXmlSerializer(),
    ) {}

    /**
     * @return array{package_key:string,data_version:string,bundle_sha256:string}
     */
    public function validate(EldpAnnualStatement $statement, string $xml): array
    {
        $expected = $this->serializer->serialize($statement);
        if (!hash_equals(hash('sha256', $expected), hash('sha256', $xml))) {
            throw new EldpValidationException(
                'eldp_xml_snapshot_mismatch',
                'XML evidenčního listu neodpovídá přesnému zdrojovému snapshotu.',
            );
        }

        return $this->validateBytes($xml);
    }

    /**
     * @return array{package_key:string,data_version:string,bundle_sha256:string}
     */
    public function validateBytes(string $xml): array
    {
        $schema = $this->schemas->evidenceSchema();
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        $valid = $loaded
            && $document->schemaValidateSource($schema['schema_source']);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$valid) {
            $messages = array_unique(array_map(
                static fn (\LibXMLError $error): string => trim($error->message),
                $errors,
            ));
            throw new EldpValidationException(
                'eldp_xsd_validation_failed',
                'XML evidenčního listu neprošlo připnutým XSD: '
                    . implode('; ', $messages),
            );
        }

        return [
            'package_key' => $schema['package_key'],
            'data_version' => $schema['data_version'],
            'bundle_sha256' => $schema['bundle_sha256'],
        ];
    }
}
