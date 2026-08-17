<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

use DOMDocument;
use DOMElement;

/**
 * Serializér jednotné datové věty zdravotních pojišťoven.
 *
 * Determinismus: `DOMDocument` bez `formatOutput` zvenčí, pevné pořadí prvků,
 * UTF-8 a žádná časová ani náhodná složka. Stejný vstup dá bajt po bajtu
 * stejný dokument, takže se otisk artefaktu dá porovnat proti zmrazenému
 * podání — přesně jako u JMHZ a REGZEL.
 *
 * `interniIdentifikacePodaniPodavatele` je volitelné a pojišťovny ho podle
 * podkladů NEVYHODNOCUJÍ. Nesmí se proto použít jako idempotenční klíč;
 * slouží jen k tomu, aby si podání našel podatel sám.
 *
 * ## Proč se smí serializovat, i když v repu XSD zatím není
 *
 * Namespace, kořen, fixní konstanty a jména prvků `zmenaZamestance`
 * i `udajePlatby` jsou z rešerše doložené. Jména prvků uvnitř
 * `identifikaceZamestnavatele` doložená NEJSOU — rešerše je popisuje česky
 * ({@see self::EMPLOYER_ELEMENTS}). Návrh se ale nikdy nedostane na
 * pojišťovnu bez XSD validace: {@see HealthInsuranceXmlValidator} si vyžádá
 * připnuté schéma a bez něj selže na `zp_schema_bundle_missing`, takže
 * případná chyba v pojmenování spadne hlasitě při zapnutí balíčku a ne tiše
 * v podání.
 */
final readonly class HealthInsuranceXmlSerializer
{
    /**
     * Pořadí je z rešerše doložené (IČO plátce, název, ulice, č. p., PSČ,
     * obec, telefon); přesná jména prvků čekají na připnuté XSD.
     */
    private const EMPLOYER_ELEMENTS = [
        'payer_number' => 'identifikacniCisloPlatce',
        'name' => 'nazev',
        'street' => 'ulice',
        'house_number' => 'cisloPopisne',
        'postal_code' => 'psc',
        'city' => 'obec',
        'phone' => 'telefon',
    ];

    public function __construct(
        private HealthInsuranceSchemaCatalog $schemas,
        private HealthNotificationCodeCatalog $codes,
    ) {}

    public function serializeBulkNotification(
        HealthBulkNotificationPayload $payload,
    ): string {
        $payload->assertValid($this->schemas, $this->codes);
        $manifest = $this->schemas->manifestFor(
            HealthInsuranceSchemaCatalog::HOZ,
        );
        [$document, $root] = $this->document($manifest);

        $this->text(
            $document,
            $root,
            'kodZdravotniPojistovny',
            $payload->insurerCode,
        );
        $root->appendChild(
            $this->employer($document, $manifest, $payload->employer),
        );
        $changes = $this->element(
            $document,
            $manifest,
            'seznamZmenZamestnancu',
        );
        foreach ($payload->changes as $change) {
            $changes->appendChild(
                $this->change($document, $manifest, $change),
            );
        }
        $root->appendChild($changes);

        return $this->finish($document);
    }

    public function serializePaymentOverview(
        HealthPaymentOverviewPayload $payload,
    ): string {
        $payload->assertValid($this->schemas);
        $manifest = $this->schemas->manifestFor(
            HealthInsuranceSchemaCatalog::PPZ,
        );
        [$document, $root] = $this->document($manifest);

        $this->text($document, $root, 'typPrehledu', $payload->overviewKind);
        $this->text(
            $document,
            $root,
            'kodZdravotniPojistovny',
            $payload->insurerCode,
        );
        $root->appendChild(
            $this->employer($document, $manifest, $payload->employer),
        );

        $payment = $this->element($document, $manifest, 'udajePlatby');
        $this->text(
            $document,
            $payment,
            'mesicHlaseni',
            (string) $payload->month,
        );
        $this->text(
            $document,
            $payment,
            'rokHlaseni',
            (string) $payload->year,
        );
        $this->text(
            $document,
            $payment,
            'pocetZamestnancu',
            (string) $payload->employeeCount,
        );
        $this->text(
            $document,
            $payment,
            'soucetZakladuPojistneho',
            $payload->assessmentBaseDecimal(),
        );
        $this->text(
            $document,
            $payment,
            'soucetPojistneho',
            (string) $payload->contributionCzk,
        );
        $root->appendChild($payment);

        return $this->finish($document);
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array{0:DOMDocument,1:DOMElement}
     */
    private function document(array $manifest): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS(
            (string) $manifest['namespace'],
            (string) $manifest['root'],
        );
        $document->appendChild($root);
        $this->text(
            $document,
            $root,
            'identifikacePredmetuPodaniText',
            (string) $manifest['subject_text'],
        );
        $this->text(
            $document,
            $root,
            'identifikacePredmetuPodaniKod',
            (string) $manifest['subject_code'],
        );

        return [$document, $root];
    }

    /** @param array<string,mixed> $manifest */
    private function employer(
        DOMDocument $document,
        array $manifest,
        HealthEmployerIdentification $employer,
    ): DOMElement {
        $element = $this->element(
            $document,
            $manifest,
            'identifikaceZamestnavatele',
        );
        $values = $employer->toArray();
        foreach (self::EMPLOYER_ELEMENTS as $key => $name) {
            $this->text($document, $element, $name, $values[$key]);
        }

        return $element;
    }

    /** @param array<string,mixed> $manifest */
    private function change(
        DOMDocument $document,
        array $manifest,
        HealthNotificationChange $change,
    ): DOMElement {
        $element = $this->element($document, $manifest, 'zmenaZamestance');
        $this->text($document, $element, 'kodzmeny', $change->changeCode);
        $this->text($document, $element, 'datumZmeny', $change->changedOn);
        $this->text(
            $document,
            $element,
            'cisloPojistence',
            $change->insuranceNumber,
        );
        $this->text($document, $element, 'jmeno', $change->firstName);
        $this->text($document, $element, 'prijmeni', $change->lastName);
        if ($change->address !== null) {
            $address = $this->element($document, $manifest, 'adresa');
            $this->text($document, $address, 'ulice', $change->address->street);
            $this->text(
                $document,
                $address,
                'cisloPopisne',
                $change->address->houseNumber,
            );
            $this->text($document, $address, 'psc', $change->address->postalCode);
            $this->text($document, $address, 'obec', $change->address->city);
            $element->appendChild($address);
        }

        return $element;
    }

    /** @param array<string,mixed> $manifest */
    private function element(
        DOMDocument $document,
        array $manifest,
        string $name,
    ): DOMElement {
        return $document->createElementNS(
            (string) $manifest['namespace'],
            $name,
        );
    }

    private function text(
        DOMDocument $document,
        DOMElement $parent,
        string $name,
        string $value,
    ): void {
        $element = $document->createElementNS(
            $parent->namespaceURI ?? '',
            $name,
        );
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);
    }

    private function finish(DOMDocument $document): string
    {
        $xml = $document->saveXML();
        if ($xml === false) {
            throw new HealthNotificationException(
                'zp_xml_serialization_failed',
                'Datovou větu zdravotní pojišťovny se nepodařilo serializovat.',
            );
        }

        return rtrim($xml, "\r\n");
    }
}
