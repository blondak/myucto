<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds\Gateway;

/**
 * Skládá SOAP 1.1 tělo požadavku `SetConcept` (kap. 3.4, `SetConcept.wsdl`).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč se to píše ručně přes XMLWriter a ne serializérem
 * ═══════════════════════════════════════════════════════════════════════════
 * Kvůli jedné pasti, která je v `SetConcept.xsd` a kterou většina implementací
 * míjí. Skupina `gMessageEnvelopeSub` deklaruje VŠECHNY prvky obálky
 * (`dmSenderOrgUnit`, `dmSenderOrgUnitNum`, `dbIDRecipient`, `dmRecipientOrgUnit`,
 * …, `dmAllowSubstDelivery`) **bez `minOccurs="0"`**, tedy jako POVINNÉ — jsou
 * jen `nillable="true"`.
 *
 * Znamená to, že prázdná hodnota se NESMÍ vynechat ani zapsat jako prázdný
 * element:
 *   `<dmSenderOrgUnit xsi:nil="true"/>`      → XSD OK
 *   element úplně vynechaný                   → XSD chyba („This element is not expected")
 *   `<dmSenderOrgUnitNum></dmSenderOrgUnitNum>` → XSD chyba
 *      (`'' is not a valid value of the atomic type 'xs:integer'`)
 *
 * Ta třetí varianta je pikantní tím, že ji používá sama oficiální příručka
 * v příkladu. Dokumentace si tedy protiřečí a jediná prokazatelně správná cesta
 * je `xsi:nil`. Ověřuje to
 * {@see \MyInvoice\Tests\Unit\Submission\SetConceptRequestWriterTest} proti
 * originálnímu `SetConcept.xsd` z Technické přílohy 2.
 *
 * Druhá past je pořadí: obálka je `xs:sequence`, takže prohození prvků je
 * rovnou XSD chyba. Pořadí volání níž tedy NENÍ kosmetika a nesmí se měnit.
 *
 * Třetí past: `dmMimeType`, `dmFileMetaType` a `dmFileDescr` jsou ATRIBUTY
 * elementu `dmFile`, ne jeho potomci, a `dmFileMetaType` je povinný s enumerací
 * (`main` | `enclosure` | `signature` | `meta`).
 *
 * A poslední: atribut `dmType` se ZÁMĚRNĚ nevypisuje. Kap. 3.4 říká „Není
 * povoleno uvádět typ zprávy jako komerční, typ zprávy je automaticky označen
 * až v okamžiku odsouhlasení konceptu."
 */
final readonly class SetConceptRequestWriter
{
    public const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';
    public const NS_CONCEPT = 'http://isds.czechpoint.cz/v20/koncept';
    public const NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    /** Hodnota HTTP hlavičky `SOAPAction` podle `SetConceptSoap11` bindingu. */
    public const SOAP_ACTION = 'SetConcept';

    /** Kompletní SOAP obálka požadavku. */
    public function envelope(IsdsConceptMessage $message): string
    {
        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');

        $writer->startElementNS('soap', 'Envelope', self::NS_SOAP);
        $writer->startElementNS('soap', 'Body', null);
        $this->writeSetConcept($writer, $message);
        $writer->endElement(); // Body
        $writer->endElement(); // Envelope

        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Samotný prvek `SetConcept` bez SOAP obálky.
     *
     * Existuje kvůli testu: `DOMDocument::schemaValidate()` validuje proti
     * `SetConcept.xsd`, které SOAP obálku nezná.
     */
    public function body(IsdsConceptMessage $message): string
    {
        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $this->writeSetConcept($writer, $message);
        $writer->endDocument();

        return $writer->outputMemory();
    }

    private function writeSetConcept(\XMLWriter $writer, IsdsConceptMessage $message): void
    {
        $writer->startElementNS('p', 'SetConcept', self::NS_CONCEPT);
        $writer->writeAttributeNS('xmlns', 'xsi', null, self::NS_XSI);

        // ── dmEnvelope ── pořadí je dané xs:sequence a nesmí se měnit ──
        $writer->startElementNS('p', 'dmEnvelope', null);
        $this->nilElement($writer, 'dmSenderOrgUnit');
        $this->nilElement($writer, 'dmSenderOrgUnitNum');
        $this->textElement($writer, 'dbIDRecipient', $message->recipientBoxId);
        $this->nilElement($writer, 'dmRecipientOrgUnit');
        $this->nilElement($writer, 'dmRecipientOrgUnitNum');
        $this->nilElement($writer, 'dmToHands');
        $this->textElement($writer, 'dmAnnotation', $message->annotation);
        $this->nilElement($writer, 'dmRecipientRefNumber');
        $this->nilElement($writer, 'dmSenderRefNumber');
        $this->nilElement($writer, 'dmRecipientIdent');
        // Naše spisová značka. Jediná stopa, podle které jde po přerušeném
        // volání dohledat, co se stalo — ISDS žádný idempotency token nemá.
        $this->textElement($writer, 'dmSenderIdent', $message->senderIdent);
        $this->nilElement($writer, 'dmLegalTitleLaw');
        $this->nilElement($writer, 'dmLegalTitleYear');
        $this->nilElement($writer, 'dmLegalTitleSect');
        $this->nilElement($writer, 'dmLegalTitlePar');
        $this->nilElement($writer, 'dmLegalTitlePoint');
        $this->nilElement($writer, 'dmPersonalDelivery');
        $this->nilElement($writer, 'dmAllowSubstDelivery');
        $writer->endElement(); // dmEnvelope

        // ── dmFiles ──
        $writer->startElementNS('p', 'dmFiles', null);
        foreach ($message->files as $index => $file) {
            $writer->startElementNS('p', 'dmFile', null);
            $writer->writeAttribute('dmMimeType', $file['mime']);
            // „typ přílohy, první by měla být main" (SetConcept.xsd).
            $writer->writeAttribute('dmFileMetaType', $index === 0 ? 'main' : 'enclosure');
            $writer->writeAttribute('dmFileDescr', $file['filename']);
            // xs:choice — buď base64, nebo dmXMLContent, nikdy obojí. Base64
            // volíme i pro XML přílohy: `dmXMLContent` by vyžadovalo vložit cizí
            // XML do naší obálky a jakákoliv jeho vada by rozbila celý request.
            $writer->startElementNS('p', 'dmEncodedContent', null);
            $writer->text(base64_encode($file['bytes']));
            $writer->endElement();
            $writer->endElement(); // dmFile
        }
        $writer->endElement(); // dmFiles

        $writer->endElement(); // SetConcept
    }

    private function textElement(\XMLWriter $writer, string $name, string $value): void
    {
        $writer->startElementNS('p', $name, null);
        $writer->text($value);
        $writer->endElement();
    }

    /**
     * Povinný, ale prázdný prvek. Viz komentář u třídy — tohle je ten rozdíl,
     * na kterém padá výstup běžných serializérů.
     */
    private function nilElement(\XMLWriter $writer, string $name): void
    {
        $writer->startElementNS('p', $name, null);
        $writer->writeAttributeNS('xsi', 'nil', null, 'true');
        $writer->endElement();
    }
}
