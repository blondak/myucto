<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Ozuspoj;

use DOMDocument;
use DOMXPath;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojException;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSubmissionKind;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojXmlPayload;

/**
 * Čtečka datové věty OZUSPOJ23 podané předchozím mzdovým programem.
 *
 * Soubor se nejdřív ověří proti připnutému XSD (stejnému, proti kterému se
 * validuje vlastní podání) a potom proti pravidlům popisu datové věty, která
 * XSD nevyjadřuje: `datumOd` je povinné pro typ 1 a 3 a zakázané pro typ 2,
 * `datumDo` je povinné pro typ 2. Co neprojde, se nepřebírá — záměr zakládá
 * nárok na slevu, takže odhad by byl dluh na pojistném.
 */
final readonly class OzuspojXmlReader
{
    public function __construct(
        private OzuspojSchemaCatalog $schemas = new OzuspojSchemaCatalog(),
    ) {}

    /** Je obsah datová věta OZUSPOJ23? Pro rozdělení souborů importu. */
    public static function looksLike(string $xml): bool
    {
        return str_contains($xml, OzuspojSchemaCatalog::NAMESPACE)
            && preg_match('/<(?:[A-Za-z0-9_]+:)?podaniOzuspoj[\s>]/', $xml) === 1;
    }

    public function read(string $xml): OzuspojPredecessorFile
    {
        $schema = $this->schemas->schemaFor(OzuspojSchemaCatalog::DOCUMENT_TYPE);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        $valid = $loaded && $document->schemaValidate($schema['path']);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$valid) {
            throw new OzuspojException(
                'ozuspoj_import_invalid_xml',
                'Soubor není platná datová věta OZUSPOJ23: '
                    . implode('; ', array_unique(array_map(
                        static fn (\LibXMLError $error): string => trim($error->message),
                        $errors,
                    ))),
            );
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('o', OzuspojSchemaCatalog::NAMESPACE);
        $kind = match ($this->text($xpath, '/o:podaniOzuspoj/o:formularOzuspoj/o:zamer/o:typPodani')) {
            '1' => OzuspojSubmissionKind::Start,
            '2' => OzuspojSubmissionKind::End,
            '3' => OzuspojSubmissionKind::Cancellation,
            default => throw new OzuspojException(
                'ozuspoj_import_invalid_xml',
                'Datová věta OZUSPOJ23 nemá platný typ podání.',
            ),
        };
        $intentFrom = $this->text($xpath, '/o:podaniOzuspoj/o:formularOzuspoj/o:zamer/o:datumOd');
        $intentTo = $this->text($xpath, '/o:podaniOzuspoj/o:formularOzuspoj/o:zamer/o:datumDo');
        if ($kind->requiresIntentFrom() !== ($intentFrom !== null)
            || ($kind->requiresIntentTo() && $intentTo === null)
            || ($intentFrom !== null && $intentTo !== null && $intentTo < $intentFrom)
        ) {
            throw new OzuspojException(
                'ozuspoj_import_invalid_xml',
                'Datová věta OZUSPOJ23 nemá datum od a do v souladu s typem podání '
                    . '(zahájení a storno nesou datum od, skončení jen datum do).',
            );
        }

        $payload = new OzuspojXmlPayload(
            kind: $kind,
            osszCode: (int) $this->required($xpath, 'o:zamer/o:kodOSSZ'),
            intentFrom: $intentFrom,
            intentTo: $intentTo,
            employerVariableSymbol: $this->required($xpath, 'o:zamestnavatel/o:vs'),
            employerIdentificationNumber: $this->text($xpath, '/o:podaniOzuspoj/o:formularOzuspoj/o:zamestnavatel/o:IC'),
            employerName: $this->required($xpath, 'o:zamestnavatel/o:nazev'),
            employeeFirstName: $this->required($xpath, 'o:zamestnanec/o:jmeno'),
            employeeLastName: $this->required($xpath, 'o:zamestnanec/o:prijmeni'),
            employeeBirthDate: $this->required($xpath, 'o:zamestnanec/o:datumNarozeni'),
            employeeBirthNumber: $this->text($xpath, '/o:podaniOzuspoj/o:formularOzuspoj/o:zamestnanec/o:rodneCislo'),
            productName: (string) $this->attribute($xpath, 'productName'),
            productVersion: (string) $this->attribute($xpath, 'productVersion'),
        );

        return new OzuspojPredecessorFile($payload, hash('sha256', $xml));
    }

    private function required(DOMXPath $xpath, string $relative): string
    {
        $value = $this->text($xpath, '/o:podaniOzuspoj/o:formularOzuspoj/' . $relative);
        if ($value === null) {
            throw new OzuspojException(
                'ozuspoj_import_invalid_xml',
                'Datové větě OZUSPOJ23 chybí povinný údaj.',
            );
        }

        return $value;
    }

    private function text(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $value = trim((string) $nodes->item(0)?->textContent);

        return $value === '' ? null : $value;
    }

    private function attribute(DOMXPath $xpath, string $name): ?string
    {
        $nodes = $xpath->query('/o:podaniOzuspoj/o:VENDOR/@' . $name);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $value = trim((string) $nodes->item(0)?->nodeValue);

        return $value === '' ? null : $value;
    }
}
