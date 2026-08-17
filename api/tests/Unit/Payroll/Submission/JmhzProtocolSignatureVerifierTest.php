<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolSignatureVerifier;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use MyInvoice\Tests\Support\OpensslConfigTrait;
use PHPUnit\Framework\TestCase;

/**
 * Ověření podepsané časové značky ČSSZ.
 *
 * Podepisuje se **syntetickým** certifikátem vyrobeným v testu; skutečným
 * klíčem ČSSZ nikdo podepsat nemůže a reálný protokol do repozitáře nepatří —
 * jsou v něm identifikátory zaměstnavatele. Ověřuje se proto POSTUP, ne vzorek:
 * kotva se verifieru předá jako testovací a všechno ostatní (kanonizace,
 * vyprázdnění `SignatureValue`, vložený PKCS#7 s otiskem v `eContent`) je
 * shodné s tím, co dělá ČSSZ.
 */
final class JmhzProtocolSignatureVerifierTest extends TestCase
{
    use OpensslConfigTrait;

    private const SHA512 = 'http://www.w3.org/2001/04/xmlenc#sha512';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    public function testSignedProtocolIsVerifiedAndCarriesTheProcessingResult(): void
    {
        $issuer = $this->issuer();
        $xml = $this->sign($this->protocol(), $issuer);

        $verified = $this->verifier($issuer)->verifiedProtocolXml($xml, 'test');

        self::assertStringContainsString('ProcessingResult', $verified);
        self::assertStringContainsString(JmhzTransportSample::FORM_GUID, $verified);
    }

    public function testTamperedProtocolFailsClosed(): void
    {
        $issuer = $this->issuer();
        $xml = $this->sign($this->protocol(), $issuer);
        // Zamítnutá součást se přepíše na přijatou — přesně ta změna, kvůli
        // které se podpis ověřuje.
        $tampered = str_replace('result="ERROR"', 'result="OK"', $xml);
        self::assertNotSame($xml, $tampered);

        $this->assertFailsWith(
            'jmhz_protocol_digest_mismatch',
            fn (): string => $this->verifier($issuer)->verifiedProtocolXml($tampered, 'test'),
        );
    }

    /**
     * ČSSZ posílá protokol odsazený. Kdyby se otisk počítal nad dokumentem
     * s bílými místy, neseděl by nikdy — proto se načítá s `LIBXML_NOBLANKS`.
     */
    public function testIndentedProtocolStillVerifies(): void
    {
        $issuer = $this->issuer();
        $xml = $this->sign($this->protocol(), $issuer);
        // Odsazuje se jen před otevírací značkou, ať prázdné elementy zůstanou
        // prázdné — přesně tak, jak vypadá protokol od ČSSZ.
        $indented = preg_replace('/>(<[A-Za-z])/', ">\n\t\t$1", $xml) ?? '';

        $verified = $this->verifier($issuer)->verifiedProtocolXml($indented, 'test');

        self::assertStringContainsString('ProcessingResult', $verified);
    }

    public function testForeignSignerIsRejected(): void
    {
        $foreign = $this->issuer('Cizi podepisujici');
        $xml = $this->sign($this->protocol(), $foreign);

        $this->assertFailsWith(
            'jmhz_protocol_signer_untrusted',
            fn (): string => $this->verifier($this->issuer())
                ->verifiedProtocolXml($xml, 'test'),
        );
    }

    public function testCertificateExpiredAtSigningTimeIsRejected(): void
    {
        $issuer = $this->issuer();
        // Certifikát v testu platí ode dneška; protokol se tváří, že vznikl
        // dávno po jeho konci.
        $xml = $this->sign($this->protocol(), $issuer, timestamp: '20990101 10:00:00');

        $this->assertFailsWith(
            'jmhz_protocol_certificate_expired',
            fn (): string => $this->verifier($issuer)->verifiedProtocolXml($xml, 'test'),
        );
    }

    public function testCertificateNotYetValidAtSigningTimeIsRejected(): void
    {
        $issuer = $this->issuer();
        $xml = $this->sign($this->protocol(), $issuer, timestamp: '20000101 10:00:00');

        $this->assertFailsWith(
            'jmhz_protocol_certificate_not_yet_valid',
            fn (): string => $this->verifier($issuer)->verifiedProtocolXml($xml, 'test'),
        );
    }

    public function testUnsignedProtocolIsNeverVerified(): void
    {
        $this->assertFailsWith(
            'jmhz_protocol_signature_missing',
            fn (): string => $this->verifier($this->issuer())
                ->verifiedProtocolXml($this->protocol(), 'test'),
        );
    }

    public function testCorruptSignatureValueFailsClosed(): void
    {
        $issuer = $this->issuer();
        $xml = $this->sign($this->protocol(), $issuer);
        $broken = preg_replace(
            '#<SignatureValue>[^<]+</SignatureValue>#',
            '<SignatureValue>' . base64_encode('rozbity podpis') . '</SignatureValue>',
            $xml,
        ) ?? '';

        $this->assertFailsWith(
            'jmhz_protocol_signature_unreadable',
            fn (): string => $this->verifier($issuer)->verifiedProtocolXml($broken, 'test'),
        );
    }

    public function testBrokenSignatureBytesFailClosed(): void
    {
        $issuer = $this->issuer();
        $xml = $this->sign($this->protocol(), $issuer);
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
            fn (): string => $this->verifier($issuer)->verifiedProtocolXml($broken, 'test'),
        );
    }

    public function testDetachedSignatureIsRejected(): void
    {
        $issuer = $this->issuer();
        $xml = $this->sign($this->protocol(), $issuer, detached: true);

        $this->assertFailsWith(
            'jmhz_protocol_signature_detached',
            fn (): string => $this->verifier($issuer)->verifiedProtocolXml($xml, 'test'),
        );
    }

    public function testUnknownDigestAlgorithmIsRejected(): void
    {
        $issuer = $this->issuer();
        $xml = $this->sign($this->protocol(), $issuer);
        $swapped = str_replace(self::SHA512, 'http://example.invalid/md5', $xml);

        $this->assertFailsWith(
            'jmhz_protocol_digest_algorithm_unknown',
            fn (): string => $this->verifier($issuer)->verifiedProtocolXml($swapped, 'test'),
        );
    }

    public function testClassOutsideTheSignatureMustMatchTheSignedContent(): void
    {
        $issuer = $this->issuer();
        $xml = $this->sign($this->protocol(), $issuer);
        // `Class` leží v obálce GovTalk, tedy MIMO podpis. Bez křížové kontroly
        // by šlo protokol vydávat za jiný druh podání a podpis by dál platil.
        $retyped = str_replace(
            '<Class>CSSZ_JMHZ</Class>',
            '<Class>CSSZ_REGZEC</Class>',
            $xml,
        );

        $this->assertFailsWith(
            'jmhz_protocol_class_unsigned_mismatch',
            fn (): string => $this->verifier($issuer)->verifiedProtocolXml($retyped, 'test'),
        );
    }

    public function testUnknownEnvironmentIsRejected(): void
    {
        $issuer = $this->issuer();
        $xml = $this->sign($this->protocol(), $issuer);

        $this->assertFailsWith(
            'jmhz_protocol_environment_unknown',
            fn (): string => $this->verifier($issuer)->verifiedProtocolXml($xml, 'sandbox'),
        );
    }

    public function testEmptyProtocolIsRejected(): void
    {
        $this->assertFailsWith(
            'jmhz_protocol_unreadable',
            fn (): string => $this->verifier($this->issuer())->verifiedProtocolXml('   ', 'test'),
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

    /** @param array{certificate:\OpenSSLCertificate,key:\OpenSSLAsymmetricKey,pem:string} $issuer */
    private function verifier(array $issuer): JmhzProtocolSignatureVerifier
    {
        return new JmhzProtocolSignatureVerifier(trustAnchorPem: $issuer['pem']);
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

    /**
     * Vyrobí protokol podepsaný stejným postupem, jaký předepisuje podací
     * protokol ČSSZ: kanonizace `Message` s vyprázdněným `SignatureValue`,
     * otisk, a ten se vloží do PKCS#7 jako obsah.
     *
     * @param array{certificate:\OpenSSLCertificate,key:\OpenSSLAsymmetricKey,pem:string} $issuer
     */
    private function sign(
        string $protocolXml,
        array $issuer,
        ?string $timestamp = null,
        bool $detached = false,
    ): string {
        // Testovací certifikát platí ode dneška, takže výchozí značka musí být
        // „teď“ — jinak by každý pozitivní test padal na dosud neplatný podpis.
        $timestamp ??= gmdate('Ymd H:i:s');
        [$date, $time] = explode(' ', $timestamp);
        $signature = '<Signature Version="1.0" xmlns="http://www.cssz.cz/emp/timestamp">'
            . '<DigestMethod Algorithm="' . self::SHA512 . '" />'
            . '<TimeStamp><date>' . $date . '</date><time>' . $time . '</time></TimeStamp>'
            . '<SignatureValue></SignatureValue>'
            . '</Signature>';
        $withSignature = str_replace('<Header /><Body>', '<Header>' . $signature . '</Header><Body>', $protocolXml);
        self::assertNotSame($protocolXml, $withSignature);

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($withSignature, LIBXML_NONET | LIBXML_NOBLANKS));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('c', 'http://www.cssz.cz/XMLSchema/envelope');
        $message = $xpath->query('//c:Message')?->item(0);
        self::assertInstanceOf(\DOMElement::class, $message);
        $digest = hash('sha512', (string) $message->C14N(), true);

        return str_replace(
            '<SignatureValue></SignatureValue>',
            '<SignatureValue>' . base64_encode($this->cms($digest, $issuer, $detached))
                . '</SignatureValue>',
            $withSignature,
        );
    }

    /** @param array{certificate:\OpenSSLCertificate,key:\OpenSSLAsymmetricKey,pem:string} $issuer */
    private function cms(string $content, array $issuer, bool $detached): string
    {
        $input = $this->tempFile($content);
        $output = $this->tempFile('');
        self::assertTrue(openssl_cms_sign(
            $input,
            $output,
            $issuer['certificate'],
            $issuer['key'],
            [],
            $detached ? OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED : OPENSSL_CMS_BINARY,
            OPENSSL_ENCODING_DER,
        ), self::opensslErrors());
        $der = file_get_contents($output);
        self::assertIsString($der);
        self::assertNotSame('', $der);

        return $der;
    }

    /** @return array{certificate:\OpenSSLCertificate,key:\OpenSSLAsymmetricKey,pem:string} */
    private function issuer(string $commonName = 'DIS.CSSZ.TEST'): array
    {
        static $cache = [];
        if (isset($cache[$commonName])) {
            return $cache[$commonName];
        }
        $options = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ] + self::opensslConfigArgs();

        $key = openssl_pkey_new($options);
        self::assertNotFalse($key, self::opensslErrors());
        $csr = openssl_csr_new(
            ['commonName' => $commonName, 'countryName' => 'CZ'],
            $key,
            $options,
        );
        self::assertNotFalse($csr, self::opensslErrors());
        $certificate = openssl_csr_sign($csr, null, $key, 3650, $options);
        self::assertNotFalse($certificate, self::opensslErrors());
        $pem = '';
        self::assertTrue(openssl_x509_export($certificate, $pem));

        return $cache[$commonName] = [
            'certificate' => $certificate,
            'key' => $key,
            'pem' => $pem,
        ];
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'jmhz-protocol-test-');
        self::assertNotFalse($path);
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
