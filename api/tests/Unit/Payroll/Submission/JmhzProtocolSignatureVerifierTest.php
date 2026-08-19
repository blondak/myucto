<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifier;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use MyInvoice\Tests\Support\JmhzSignedProtocolFactory;
use PHPUnit\Framework\TestCase;

/**
 * Ověření podepsané časové značky ČSSZ.
 *
 * Podepisuje se **syntetickým** certifikátem; skutečným klíčem ČSSZ nikdo
 * podepsat nemůže a reálný protokol do repozitáře nepatří — jsou v něm
 * identifikátory zaměstnavatele. Ověřuje se proto POSTUP, ne vzorek: kanonizace
 * `Message`, vyprázdnění textu `SignatureValue` a vložený PKCS#7 s otiskem
 * v obsahu jsou shodné s tím, co dělá ČSSZ.
 */
final class JmhzProtocolSignatureVerifierTest extends TestCase
{
    private const FOREIGN = 'Cizi podepisujici';

    private ?JmhzSignedProtocolFactory $factory = null;

    protected function tearDown(): void
    {
        $this->factory?->cleanUp();
        $this->factory = null;
    }

    public function testSignedProtocolIsVerifiedAndCarriesTheProcessingResult(): void
    {
        $xml = $this->protocols()->sign($this->protocol());

        $verified = $this->verifier()->verifiedProtocolXml($xml, 'test');

        self::assertStringContainsString('ProcessingResult', $verified);
        self::assertStringContainsString(JmhzTransportSample::FORM_GUID, $verified);
    }

    public function testTamperedProtocolFailsClosed(): void
    {
        $xml = $this->protocols()->sign($this->protocol());
        // Zamítnutá součást se přepíše na přijatou — přesně ta změna, kvůli
        // které se podpis ověřuje.
        $tampered = str_replace('result="ERROR"', 'result="OK"', $xml);
        self::assertNotSame($xml, $tampered);

        $this->assertFailsWith(
            'jmhz_protocol_digest_mismatch',
            fn (): string => $this->verifier()->verifiedProtocolXml($tampered, 'test'),
        );
    }

    /**
     * ČSSZ posílá protokol odsazený. Kdyby se otisk počítal nad dokumentem
     * s bílými místy, neseděl by nikdy — proto se načítá s `LIBXML_NOBLANKS`.
     */
    public function testIndentedProtocolStillVerifies(): void
    {
        $xml = $this->protocols()->sign($this->protocol());
        // Odsazuje se jen před otevírací značkou, ať prázdné elementy zůstanou
        // prázdné — přesně tak, jak vypadá protokol od ČSSZ.
        $indented = preg_replace('/>(<[A-Za-z])/', ">\n\t\t$1", $xml) ?? '';

        $verified = $this->verifier()->verifiedProtocolXml($indented, 'test');

        self::assertStringContainsString('ProcessingResult', $verified);
    }

    public function testForeignSignerIsRejected(): void
    {
        $xml = $this->protocols()->sign($this->protocol(), self::FOREIGN);

        $this->assertFailsWith(
            'jmhz_protocol_signer_untrusted',
            fn (): string => $this->verifier()->verifiedProtocolXml($xml, 'test'),
        );
    }

    public function testCertificateExpiredAtSigningTimeIsRejected(): void
    {
        // Syntetický certifikát platí ode dneška; protokol se tváří, že vznikl
        // dávno po jeho konci.
        $xml = $this->protocols()->sign($this->protocol(), timestamp: '20990101 10:00:00');

        $this->assertFailsWith(
            'jmhz_protocol_certificate_expired',
            fn (): string => $this->verifier()->verifiedProtocolXml($xml, 'test'),
        );
    }

    public function testCertificateNotYetValidAtSigningTimeIsRejected(): void
    {
        $xml = $this->protocols()->sign($this->protocol(), timestamp: '20000101 10:00:00');

        $this->assertFailsWith(
            'jmhz_protocol_certificate_not_yet_valid',
            fn (): string => $this->verifier()->verifiedProtocolXml($xml, 'test'),
        );
    }

    public function testUnsignedProtocolIsNeverVerified(): void
    {
        $this->assertFailsWith(
            'jmhz_protocol_signature_missing',
            fn (): string => $this->verifier()->verifiedProtocolXml($this->protocol(), 'test'),
        );
    }

    public function testCorruptSignatureValueFailsClosed(): void
    {
        $xml = $this->protocols()->sign($this->protocol());
        $broken = preg_replace(
            '#<SignatureValue>[^<]+</SignatureValue>#',
            '<SignatureValue>' . base64_encode('rozbity podpis') . '</SignatureValue>',
            $xml,
        ) ?? '';

        $this->assertFailsWith(
            'jmhz_protocol_signature_unreadable',
            fn (): string => $this->verifier()->verifiedProtocolXml($broken, 'test'),
        );
    }

    public function testBrokenSignatureBytesFailClosed(): void
    {
        $xml = $this->protocols()->sign($this->protocol());
        self::assertSame(
            1,
            preg_match('#<SignatureValue>([^<]+)</SignatureValue>#', $xml, $matches),
        );
        // Struktura zůstane čitelná, ale poslední bajt podpisu se otočí —
        // RSA ověření musí padnout.
        $der = (string) base64_decode($matches[1], true);
        $der[strlen($der) - 1] = chr(ord($der[strlen($der) - 1]) ^ 0xFF);
        $broken = str_replace($matches[1], base64_encode($der), $xml);

        $this->assertFailsWith(
            'jmhz_protocol_signature_invalid',
            fn (): string => $this->verifier()->verifiedProtocolXml($broken, 'test'),
        );
    }

    public function testDetachedSignatureIsRejected(): void
    {
        $xml = $this->protocols()->sign($this->protocol(), detached: true);

        $this->assertFailsWith(
            'jmhz_protocol_signature_detached',
            fn (): string => $this->verifier()->verifiedProtocolXml($xml, 'test'),
        );
    }

    public function testUnknownDigestAlgorithmIsRejected(): void
    {
        $xml = $this->protocols()->sign($this->protocol());
        $swapped = str_replace(
            JmhzSignedProtocolFactory::SHA512,
            'http://example.invalid/md5',
            $xml,
        );

        $this->assertFailsWith(
            'jmhz_protocol_digest_algorithm_unknown',
            fn (): string => $this->verifier()->verifiedProtocolXml($swapped, 'test'),
        );
    }

    public function testClassOutsideTheSignatureMustMatchTheSignedContent(): void
    {
        $xml = $this->protocols()->sign($this->protocol());
        // `Class` leží v obálce GovTalk, tedy MIMO podpis. Bez křížové kontroly
        // by šlo protokol vydávat za jiný druh podání a podpis by dál platil.
        $retyped = str_replace(
            '<Class>CSSZ_JMHZ</Class>',
            '<Class>CSSZ_REGZEC</Class>',
            $xml,
        );

        $this->assertFailsWith(
            'jmhz_protocol_class_unsigned_mismatch',
            fn (): string => $this->verifier()->verifiedProtocolXml($retyped, 'test'),
        );
    }

    public function testUnknownEnvironmentIsRejected(): void
    {
        $xml = $this->protocols()->sign($this->protocol());

        $this->assertFailsWith(
            'jmhz_protocol_environment_unknown',
            fn (): string => $this->verifier()->verifiedProtocolXml($xml, 'sandbox'),
        );
    }

    public function testEmptyProtocolIsRejected(): void
    {
        $this->assertFailsWith(
            'jmhz_protocol_unreadable',
            fn (): string => $this->verifier()->verifiedProtocolXml('   ', 'test'),
        );
    }

    /** @param callable():string $call */
    private function assertFailsWith(string $expectedCode, callable $call): void
    {
        try {
            $call();
            self::fail("Očekávané selhání `{$expectedCode}` nenastalo.");
        } catch (JmhzTransportException $exception) {
            self::assertSame($expectedCode, $exception->errorCode);
        }
    }

    private function verifier(): JmhzProtocolSignatureVerifier
    {
        return new JmhzProtocolSignatureVerifier(
            trustAnchorPem: $this->protocols()->anchorPem(),
        );
    }

    private function protocols(): JmhzSignedProtocolFactory
    {
        return $this->factory ??= new JmhzSignedProtocolFactory();
    }

    private function protocol(): string
    {
        return JmhzTransportSample::partialProtocol(
            'ERROR',
            [
                ['guid' => JmhzTransportSample::FORM_GUID, 'result' => 'OK'],
                [
                    'guid' => JmhzTransportSample::OTHER_FORM_GUID,
                    'result' => 'ERROR',
                    'errMsg' => 'JMHZ25_LT: 20118 - Chybná hodnota',
                    'errNum' => '20118',
                ],
            ],
            errMsg: 'JMHZ25_LT: 20118 - Chybná hodnota',
            errNumber: '20118',
            correlationId: 'CID0000000001',
        );
    }
}
