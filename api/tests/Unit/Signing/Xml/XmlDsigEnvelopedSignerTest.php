<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Signing\Xml;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Pdf\SigningConfig;
use MyInvoice\Service\Signing\SigningCredentialUnlocker;
use MyInvoice\Service\Signing\Xml\XmlDsigEnvelopedSigner;
use MyInvoice\Tests\Support\IsdocSignatureVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Podpis ISDOC podle standardu ISDOC 6.0.2, kap. 5.1 (XMLDSig enveloped).
 *
 * Podepisuje se skutečný výstup {@see IsdocExporter} syntetickým certifikátem
 * a ověřuje nezávisle ({@see IsdocSignatureVerifier}): filtr XPath vyhodnocený
 * nad podepsaným dokumentem, otisk reference, hodnota podpisu veřejným klíčem
 * z KeyInfo. Podepsaný doklad musí dál projít oficiálním XSD.
 */
final class XmlDsigEnvelopedSignerTest extends TestCase
{
    private const XSD = __DIR__ . '/../../../../xsd/isdoc-invoice-6.0.2.xsd';

    public function testSignedIsdocVerifiesIndependentlyAndStaysSchemaValid(): void
    {
        $credential = IsdocSignatureVerifier::syntheticPfx();
        $signed = (new XmlDsigEnvelopedSigner(xpathFilter: true))->sign($this->isdoc(), $credential);

        $certificate = IsdocSignatureVerifier::verify($signed);
        self::assertSame(
            openssl_x509_fingerprint($credential['cert'], 'sha256'),
            openssl_x509_fingerprint($certificate, 'sha256'),
        );
        self::assertStringContainsString('Id="Signature-1"', $signed);
        // Prefix dsig na XPath, ne na Signature — jinak podpis neověří .NET SignedXml.
        self::assertStringContainsString(
            '<XPath xmlns:dsig="http://www.w3.org/2000/09/xmldsig#">not(ancestor-or-self::dsig:Signature)</XPath>',
            $signed,
        );
        self::assertStringContainsString('<Signature xmlns="http://www.w3.org/2000/09/xmldsig#" Id="Signature-1">', $signed);
        $this->assertSchemaValid($signed);
    }

    public function testDefaultSignatureHasNoXpathFilterAndVerifies(): void
    {
        // Výchozí podpis je bez XPath: ověří ho i .NET bez úpravy politiky (MS16-035).
        $signed = (new XmlDsigEnvelopedSigner())->sign($this->isdoc(), IsdocSignatureVerifier::syntheticPfx());

        IsdocSignatureVerifier::verify($signed);
        self::assertStringNotContainsString('REC-xpath-19991116', $signed);
        $this->assertSchemaValid($signed);
    }

    public function testSigningKeepsDocumentContentByteForByte(): void
    {
        $unsigned = $this->isdoc();
        $signed = (new XmlDsigEnvelopedSigner())->sign($unsigned, IsdocSignatureVerifier::syntheticPfx());

        $without = preg_replace('#<Signature xmlns="http://www\.w3\.org/2000/09/xmldsig\#".*</Signature>#s', '', $signed);
        self::assertSame(rtrim($unsigned), rtrim((string) $without));
    }

    public function testChangedByteInDocumentBreaksSignature(): void
    {
        $signed = (new XmlDsigEnvelopedSigner())->sign($this->isdoc(), IsdocSignatureVerifier::syntheticPfx());
        $tampered = str_replace('<PayableAmount>3049.20</PayableAmount>', '<PayableAmount>3049.30</PayableAmount>', $signed);
        self::assertNotSame($signed, $tampered, 'Vzorek nemá očekávanou částku k úpravě.');

        $this->expectExceptionMessage('Otisk reference nesouhlasí');
        IsdocSignatureVerifier::verify($tampered);
    }

    public function testChangedSignedInfoBreaksSignatureValue(): void
    {
        $signed = (new XmlDsigEnvelopedSigner())->sign($this->isdoc(), IsdocSignatureVerifier::syntheticPfx());
        $tampered = (string) preg_replace_callback(
            '#<DigestValue>([^<]+)</DigestValue>#',
            static fn (array $m): string => '<DigestValue>' . base64_encode(hash('sha256', $m[1], true)) . '</DigestValue>',
            $signed,
        );

        $this->expectException(\RuntimeException::class);
        IsdocSignatureVerifier::verify($tampered);
    }

    public function testUnsignedIsdocFailsVerification(): void
    {
        $this->expectExceptionMessage('ds:Signature není posledním prvkem kořene.');
        IsdocSignatureVerifier::verify($this->isdoc());
    }

    public function testAlreadySignedDocumentIsRejected(): void
    {
        $signer = new XmlDsigEnvelopedSigner();
        $credential = IsdocSignatureVerifier::syntheticPfx();

        $this->expectExceptionMessage('Dokument už elektronický podpis obsahuje.');
        $signer->sign($signer->sign($this->isdoc(), $credential), $credential);
    }

    public function testCredentialUnlockerOpensPfxFromProfileConfig(): void
    {
        $credential = IsdocSignatureVerifier::syntheticPfx();
        $config = new Config(['app' => ['pepper' => 'unit-test-pepper']]);
        $secrets = new SecretEncryption($config);
        $unlocked = (new SigningCredentialUnlocker($secrets))->unlock(new SigningConfig(
            certPath: '',
            passwordEnc: $secrets->encrypt($credential['password']),
            tsaUrl: null,
            reason: 'Faktura',
            certBytes: $credential['pfx'],
        ));

        IsdocSignatureVerifier::verify((new XmlDsigEnvelopedSigner())->sign($this->isdoc(), $unlocked));
        $this->addToAssertionCount(1);
    }

    public function testWrongPfxPasswordIsReported(): void
    {
        $credential = IsdocSignatureVerifier::syntheticPfx();
        $secrets = new SecretEncryption(new Config(['app' => ['pepper' => 'unit-test-pepper']]));

        $this->expectExceptionMessage('P12 nelze otevřít');
        (new SigningCredentialUnlocker($secrets))->unlock(new SigningConfig(
            certPath: '',
            passwordEnc: $secrets->encrypt('wrong'),
            tsaUrl: null,
            reason: 'Faktura',
            certBytes: $credential['pfx'],
        ));
    }

    private function isdoc(): string
    {
        $exporter = new IsdocExporter(
            $this->createStub(InvoiceRepository::class),
            $this->createStub(Connection::class),
        );

        return $exporter->buildXml([
            'id' => 1,
            'invoice_type' => 'invoice',
            'varsymbol' => '2026001',
            'issue_date' => '2026-05-04',
            'tax_date' => '2026-05-04',
            'due_date' => '2026-05-18',
            'currency' => 'CZK',
            'exchange_rate' => null,
            'reverse_charge' => false,
            'project_number' => null,
            'contract_number' => null,
            'advance_paid_amount' => 0.0,
            'amount_to_pay' => 3049.2,
            'supplier_snapshot' => [
                'ic' => '01698401',
                'dic' => 'CZ01698401',
                'company_name' => 'Dodavatel s.r.o.',
                'street' => 'Zkušební 123/4',
                'city' => 'Vzorov',
                'zip' => '10000',
                'country_iso2' => 'CZ',
            ],
            'client_snapshot' => [
                'ic' => '27140130',
                'company_name' => 'Odběratel a.s.',
                'street' => 'Václavské náměstí 1',
                'city' => 'Praha 1',
                'zip' => '11000',
                'country_iso2' => 'CZ',
            ],
            'bank_snapshot' => ['account_number' => '1000000005', 'bank_code' => '0100', 'bank_name' => 'Komerční banka'],
            'items' => [[
                'description' => 'Vývoj systému',
                'quantity' => 1.0,
                'unit' => 'ks',
                'unit_price_without_vat' => 2520.0,
                'vat_rate_snapshot' => 21.0,
                'total_without_vat' => 2520.0,
                'total_vat' => 529.2,
                'total_with_vat' => 3049.2,
            ]],
            'vat_breakdown' => [['rate' => 21.0, 'base' => 2520.0, 'vat' => 529.2]],
            'totals' => ['without_vat' => 2520.0, 'with_vat' => 3049.2, 'rounding' => 0.0],
        ]);
    }

    private function assertSchemaValid(string $xml): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml));
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = $dom->schemaValidate(self::XSD);
        $errors = array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($ok, "Podepsaný ISDOC neprošel XSD:\n" . implode("\n", $errors));
    }
}
