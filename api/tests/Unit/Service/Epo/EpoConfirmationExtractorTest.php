<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Epo;

use MyInvoice\Service\Epo\EpoConfirmationExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Rozbalení dodejky EPO na čitelné části.
 *
 * Dodejka je binární CMS — účetní z ní bez nástroje nepřečte nic. U asistovaného
 * podání nahraje jednotlivé soubory ručně, u přímého je aplikace umí vytáhnout sama.
 */
final class EpoConfirmationExtractorTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    public function testExtractsSignedContentEchoAndBothCertificates(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }

        $submission = '<?xml version="1.0"?><Pisemnost><DPHKH1 verzePis="03.01"/></Pisemnost>';
        // `<Certifikaty>` vrací EPO jako base64 nad PKCS#7 v BER; pro test stačí, že
        // uvnitř je certifikát v DER — scanner hledá právě ten.
        $submitterPem = $this->selfSignedCertificate(['commonName' => 'Podepsala Ucetni']);
        $submitterDer = $this->derFromPem($submitterPem);

        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data>'
            . '<Kontrola><Soubor Delka="%d" KC="%s" Nazev="DPHKH1-test"/>'
            . '<Certifikaty>%s</Certifikaty></Kontrola>'
            . '<Podani Cislo="568467011" Datum="2026-08-21T10:36:43" Heslo="tajne123" ZAREP="true"/>'
            . '</Pisemnost>',
            bin2hex($submission),
            strlen($submission),
            md5($submission),
            base64_encode($submitterDer),
        );

        $signed = (string) file_get_contents($this->signDer($confirmationXml));
        $parts = (new EpoConfirmationExtractor())->extract($signed);

        self::assertNotNull($parts['confirmation_xml']);
        self::assertStringContainsString('568467011', (string) $parts['confirmation_xml']);

        self::assertNotNull($parts['echo']);
        self::assertSame(
            $submission,
            $parts['echo']['bytes'],
            '`<Data>` je hexem kódované podání a musí se dekódovat bajt po bajtu.'
        );
        self::assertSame('xml', $parts['echo']['suffix']);

        self::assertNotNull($parts['seal_certificate_pem'], 'Pečeť se bere z podpisu CMS.');
        self::assertIsArray(openssl_x509_parse((string) $parts['seal_certificate_pem'], false));

        self::assertNotNull(
            $parts['submission_certificate_pem'],
            'Certifikát, kterým bylo podáno, vrací EPO v <Certifikaty> — je to doklad, kdo podal.'
        );
        $parsed = openssl_x509_parse((string) $parts['submission_certificate_pem'], false);
        self::assertIsArray($parsed);
        self::assertStringContainsString(
            'Podepsala Ucetni',
            (string) ($parsed['name'] ?? ''),
            'Vytažený certifikát musí být ten z <Certifikaty>, ne pečeť z podpisu CMS.'
        );
    }

    public function testReturnsNothingForBytesThatAreNotACmsEnvelope(): void
    {
        $parts = (new EpoConfirmationExtractor())->extract('tohle rozhodne neni CMS');

        foreach ($parts as $key => $value) {
            self::assertNull($value, $key . ' nesmí nic vrátit pro nesmyslný vstup.');
        }
    }

    public function testEmptyInputIsHandledWithoutTouchingOpenssl(): void
    {
        $parts = (new EpoConfirmationExtractor())->extract('');

        self::assertNull($parts['confirmation_xml']);
        self::assertNull($parts['echo']);
        self::assertNull($parts['seal_certificate_pem']);
        self::assertNull($parts['submission_certificate_pem']);
    }

    /** @param array<string,string> $dn */
    private function selfSignedCertificate(array $dn): string
    {
        $options = $this->opensslOptions();
        $key = openssl_pkey_new($options);
        self::assertNotFalse($key);
        $csr = openssl_csr_new($dn, $key, $options);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_x509_export($certificate, $pem));
        return $pem;
    }

    private function derFromPem(string $pem): string
    {
        self::assertSame(1, preg_match('#-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----#s', $pem, $m));
        $der = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
        self::assertIsString($der);
        return $der;
    }

    private function signDer(string $content): string
    {
        $options = $this->opensslOptions();
        $key = openssl_pkey_new($options);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'Synthetic EPO Seal'], $key, $options);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
        self::assertNotFalse($certificate);

        $input = $this->tempFile($content);
        $output = $this->tempFile('');
        self::assertTrue(openssl_cms_sign(
            $input,
            $output,
            $certificate,
            $key,
            [],
            OPENSSL_CMS_BINARY,
            OPENSSL_ENCODING_DER,
        ));
        return $output;
    }

    /** @return array<string,mixed> */
    private function opensslOptions(): array
    {
        $options = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ];
        foreach ([
            getenv('OPENSSL_CONF') ?: null,
            'C:/inetpub/php/extras/ssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
        ] as $config) {
            if (is_string($config) && is_file($config)) {
                $options['config'] = $config;
                break;
            }
        }
        return $options;
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'epo-extract-');
        self::assertNotFalse($path);
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }
}
