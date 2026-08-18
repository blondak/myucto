<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Ozuspoj;

use DOMDocument;

/**
 * Validace datové věty OZUSPOJ proti připnutému XSD a proti těm pravidlům
 * popisu datové věty, která XSD vyjádřit neumí.
 *
 * XSD dovoluje `datumOd` i `datumDo` u kteréhokoli typu podání — obojí je
 * `minOccurs="0"`. Popis datové věty OZUSPOJ23 je ale váže na `typPodani`:
 * `datumOd` je povinné pro 1 a 3 a nesmí být vyplněné pro 2, `datumDo` je
 * povinné pro 2 a musí být >= `datumOd`. Bez téhle vrstvy by prošlo oznámení
 * skončení bez data skončení, které ČSSZ odmítne až protokolem.
 */
final readonly class OzuspojXmlValidator
{
    public function __construct(
        private OzuspojSchemaCatalog $schemas,
    ) {}

    public function validate(OzuspojXmlPayload $payload, string $xml): void
    {
        $this->validateBusinessBoundary($payload);
        $expected = (new OzuspojXmlSerializer())->serialize($payload);
        if (!hash_equals(hash('sha256', $expected), hash('sha256', $xml))) {
            $this->invalid(
                'ozuspoj_xml_snapshot_mismatch',
                'XML byteově neodpovídá zdrojovému payloadu OZUSPOJ.',
            );
        }
        $schema = $this->schemas->schemaFor(
            OzuspojSchemaCatalog::DOCUMENT_TYPE,
        );
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        $valid = $loaded && $document->schemaValidate($schema['path']);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$valid) {
            $messages = array_map(
                static fn (\LibXMLError $error): string => trim($error->message),
                $errors,
            );
            $this->invalid(
                'ozuspoj_xsd_validation_failed',
                'XML OZUSPOJ neprošlo připnutým XSD: '
                    . implode('; ', array_unique($messages)),
            );
        }
    }

    private function validateBusinessBoundary(OzuspojXmlPayload $payload): void
    {
        if ($payload->kind->requiresIntentFrom()
            && $payload->intentFrom === null
        ) {
            $this->invalid(
                'ozuspoj_intent_from_required',
                'Oznámení i storno záměru musí uvádět den, od kterého se sleva uplatňuje.',
            );
        }
        if (!$payload->kind->requiresIntentFrom()
            && $payload->intentFrom !== null
        ) {
            $this->invalid(
                'ozuspoj_intent_from_forbidden',
                'Oznámení skončení uplatňování slevy nesmí uvádět den zahájení.',
            );
        }
        if ($payload->kind->requiresIntentTo() && $payload->intentTo === null) {
            $this->invalid(
                'ozuspoj_intent_to_required',
                'Oznámení skončení uplatňování slevy musí uvádět den skončení.',
            );
        }
        if ($payload->intentFrom !== null
            && $payload->intentTo !== null
            && $payload->intentTo < $payload->intentFrom
        ) {
            $this->invalid(
                'ozuspoj_intent_period_invalid',
                'Den skončení záměru nesmí předcházet dni jeho zahájení.',
            );
        }
        if ($payload->osszCode < 100 || $payload->osszCode > 999) {
            $this->invalid(
                'ozuspoj_ossz_code_invalid',
                'Kód OSSZ musí být tříciferný podle číselníku pracovišť ČSSZ. Doplňte ho v Nastavení mezd → Zaměstnavatel.',
            );
        }
        if (preg_match('/^\d{10}$/D', $payload->employerVariableSymbol) !== 1) {
            $this->invalid(
                'ozuspoj_variable_symbol_invalid',
                'OZUSPOJ vyžaduje desetimístný variabilní symbol zaměstnavatele. Doplňte ho v Nastavení mezd → Účtárny.',
            );
        }
        if ($payload->employeeBirthNumber !== null
            && preg_match('/^\d{9,10}$/D', $payload->employeeBirthNumber) !== 1
        ) {
            $this->invalid(
                'ozuspoj_birth_number_invalid',
                'Rodné číslo nebo evidenční číslo pojištěnce musí mít 9 nebo 10 číslic.',
            );
        }
        foreach ([
            'ozuspoj_employer_name_missing' => $payload->employerName,
            'ozuspoj_employee_first_name_missing' => $payload->employeeFirstName,
            'ozuspoj_employee_last_name_missing' => $payload->employeeLastName,
        ] as $code => $value) {
            if (trim($value) === '') {
                $this->invalid(
                    $code,
                    'Oznámení záměru nemá vyplněné povinné identifikační údaje.',
                );
            }
        }
        $this->exactDate($payload->employeeBirthDate);
        if ($payload->intentFrom !== null) {
            $this->exactDate($payload->intentFrom);
        }
        if ($payload->intentTo !== null) {
            $this->exactDate($payload->intentTo);
        }
    }

    private function exactDate(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            $this->invalid(
                'ozuspoj_date_invalid',
                'Datum v oznámení záměru musí být ve tvaru RRRR-MM-DD.',
            );
        }
    }

    private function invalid(string $code, string $message): never
    {
        throw new OzuspojException($code, $message);
    }
}
