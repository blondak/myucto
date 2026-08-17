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
 * slouží jen k tomu, aby si podání našel podatel sám. Ve schématu stojí
 * hned za oběma fixními konstantami a před `kodZdravotniPojistovny`.
 *
 * ## Struktura je z připnutého XSD, ne z odhadu
 *
 * Pořadí i jména prvků odpovídají `hromadneOznameniZamestnavatele_2025_v8.xsd`
 * a `prehledPlatbyZamestnavatele_2025_v8.xsd` (revize 08, 8. 12. 2025), které
 * jsou v `api/xsd/zp/2025-v8/` připnuté otiskem. Dvě místa, kde se schéma liší
 * od toho, co by čekal čtenář:
 *
 * - `identifikaceZamestnavatele` má prvky adresy prefixované
 *   (`adresaPlatceUlice`, `adresaPlatceCisloPopisneOrientacni`, …), ne holé
 *   `ulice` a `psc`.
 * - `adresa` uvnitř `zmenaZamestance` má jen `ulice`, `obec` a `psc` — číslo
 *   popisné vlastní prvek NEMÁ, takže se připojuje k ulici.
 */
final readonly class HealthInsuranceXmlSerializer
{
    /**
     * Prvky `identifikaceZamestnavateleTyp` v pořadí ze schématu; oba XSD
     * mají tenhle typ shodný. `adresaPlatceTelefon` je `minOccurs="0"`
     * a typu `\d{1,30}`, takže se prázdný NEVYPISUJE — prázdný prvek by
     * schéma shodilo.
     */
    private const EMPLOYER_ELEMENTS = [
        'payer_number' => 'identifikacniCisloPlatce',
        'name' => 'nazevPlatce',
        'street' => 'adresaPlatceUlice',
        'house_number' => 'adresaPlatceCisloPopisneOrientacni',
        'postal_code' => 'adresaPlatcePsc',
        'city' => 'adresaPlatceObec',
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
        [$document, $root] = $this->document(
            $manifest,
            $payload->internalReference,
        );

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
        [$document, $root] = $this->document(
            $manifest,
            $payload->internalReference,
        );

        // Pořadí ze schématu: kód pojišťovny předchází typu přehledu.
        $this->text(
            $document,
            $root,
            'kodZdravotniPojistovny',
            $payload->insurerCode,
        );
        $this->text($document, $root, 'typPrehledu', $payload->overviewKind);
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
    private function document(
        array $manifest,
        ?string $internalReference = null,
    ): array {
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
        if ($internalReference !== null && $internalReference !== '') {
            $this->text(
                $document,
                $root,
                'interniIdentifikacePodaniPodavatele',
                $internalReference,
            );
        }

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
        $phone = $employer->normalizedPhone();
        if ($phone !== '') {
            $this->text($document, $element, 'adresaPlatceTelefon', $phone);
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
            // Schéma zná uvnitř `adresa` jen ulice → obec → psc a číslo popisné
            // vlastní prvek nemá; připojuje se proto k ulici.
            $address = $this->element($document, $manifest, 'adresa');
            $this->text(
                $document,
                $address,
                'ulice',
                $change->address->streetLine(),
            );
            $this->text($document, $address, 'obec', $change->address->city);
            $this->text($document, $address, 'psc', $change->address->postalCode);
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
