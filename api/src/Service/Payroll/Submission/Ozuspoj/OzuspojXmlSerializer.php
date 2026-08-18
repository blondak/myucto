<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Ozuspoj;

use DOMDocument;
use DOMElement;

/**
 * Serializace datové věty OZUSPOJ23.
 *
 * Pořadí prvků není volba stylu: `formularOzuspojType` i `zamerType` jsou
 * `xs:sequence`, takže prohozený `datumOd` a `kodOSSZ` neprojde XSD.
 */
final class OzuspojXmlSerializer
{
    public function serialize(OzuspojXmlPayload $payload): string
    {
        $namespace = OzuspojSchemaCatalog::NAMESPACE;
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS($namespace, 'podaniOzuspoj');
        $document->appendChild($root);

        $vendor = $document->createElementNS($namespace, 'VENDOR');
        $vendor->setAttribute('productName', $payload->productName);
        $vendor->setAttribute('productVersion', $payload->productVersion);
        $root->appendChild($vendor);

        $sender = $document->createElementNS($namespace, 'SENDER');
        if ($payload->notificationEmail !== null) {
            $sender->setAttribute(
                'EmailNotifikace',
                $payload->notificationEmail,
            );
        }
        // `ISDSreport="3"` = XML i HTML příloha odpovědi. Pro VREP se atribut
        // ignoruje, pro datovou schránku je to jediná varianta, ze které se dá
        // protokol strojově přečíst i ručně zkontrolovat.
        $sender->setAttribute('ISDSreport', '3');
        $root->appendChild($sender);

        $form = $document->createElementNS($namespace, 'formularOzuspoj');
        $form->appendChild($this->intent($document, $namespace, $payload));
        $form->appendChild($this->employer($document, $namespace, $payload));
        $form->appendChild($this->employee($document, $namespace, $payload));
        $contact = $this->contact($document, $namespace, $payload);
        if ($contact !== null) {
            $form->appendChild($contact);
        }
        $root->appendChild($form);

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('XML OZUSPOJ nelze serializovat.');
        }

        return rtrim($xml, "\r\n");
    }

    private function intent(
        DOMDocument $document,
        string $namespace,
        OzuspojXmlPayload $payload,
    ): DOMElement {
        $intent = $document->createElementNS($namespace, 'zamer');
        $this->text(
            $document,
            $namespace,
            $intent,
            'typPodani',
            (string) $payload->kind->formCode(),
        );
        $this->text(
            $document,
            $namespace,
            $intent,
            'kodOSSZ',
            (string) $payload->osszCode,
        );
        if ($payload->intentFrom !== null) {
            $this->text(
                $document,
                $namespace,
                $intent,
                'datumOd',
                $payload->intentFrom,
            );
        }
        if ($payload->intentTo !== null) {
            $this->text(
                $document,
                $namespace,
                $intent,
                'datumDo',
                $payload->intentTo,
            );
        }

        return $intent;
    }

    private function employer(
        DOMDocument $document,
        string $namespace,
        OzuspojXmlPayload $payload,
    ): DOMElement {
        $employer = $document->createElementNS($namespace, 'zamestnavatel');
        $this->text(
            $document,
            $namespace,
            $employer,
            'vs',
            $payload->employerVariableSymbol,
        );
        if ($payload->employerIdentificationNumber !== null) {
            $this->text(
                $document,
                $namespace,
                $employer,
                'IC',
                $payload->employerIdentificationNumber,
            );
        }
        $this->text(
            $document,
            $namespace,
            $employer,
            'nazev',
            $payload->employerName,
        );

        return $employer;
    }

    private function employee(
        DOMDocument $document,
        string $namespace,
        OzuspojXmlPayload $payload,
    ): DOMElement {
        $employee = $document->createElementNS($namespace, 'zamestnanec');
        $this->text(
            $document,
            $namespace,
            $employee,
            'jmeno',
            $payload->employeeFirstName,
        );
        $this->text(
            $document,
            $namespace,
            $employee,
            'prijmeni',
            $payload->employeeLastName,
        );
        $this->text(
            $document,
            $namespace,
            $employee,
            'datumNarozeni',
            $payload->employeeBirthDate,
        );
        if ($payload->employeeBirthNumber !== null) {
            $this->text(
                $document,
                $namespace,
                $employee,
                'rodneCislo',
                $payload->employeeBirthNumber,
            );
        }

        return $employee;
    }

    private function contact(
        DOMDocument $document,
        string $namespace,
        OzuspojXmlPayload $payload,
    ): ?DOMElement {
        $fields = [
            'jmeno' => $payload->contactFirstName,
            'prijmeni' => $payload->contactLastName,
            'telefon' => $payload->contactPhone,
            'email' => $payload->contactEmail,
        ];
        if (array_filter($fields, static fn (?string $v): bool => $v !== null)
            === []
        ) {
            return null;
        }
        $contact = $document->createElementNS($namespace, 'pracovnik');
        foreach ($fields as $name => $value) {
            if ($value !== null) {
                $this->text($document, $namespace, $contact, $name, $value);
            }
        }

        return $contact;
    }

    private function text(
        DOMDocument $document,
        string $namespace,
        DOMElement $parent,
        string $name,
        string $value,
    ): void {
        $element = $document->createElementNS($namespace, $name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);
    }
}
