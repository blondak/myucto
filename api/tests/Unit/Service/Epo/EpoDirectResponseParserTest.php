<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Epo;

use MyInvoice\Service\Epo\EpoDirectResponseParser;
use PHPUnit\Framework\TestCase;

final class EpoDirectResponseParserTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];
    private ?string $lastCertificatePath = null;

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    public function testAcceptsOfficialTestMarkerAndPreservesWarnings(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Chyby>
  <Chyba Typ="P" Zkr="WARN"><Text>Propustná kontrola.</Text></Chyba>
  <Chyba Typ="I" Zkr="TEST_REZIM"><Text>Podání nebylo přijato, protože bylo odesláno v testovacím režimu.</Text></Chyba>
</Chyby>
XML;

        $result = (new EpoDirectResponseParser())->testResult($xml);

        self::assertTrue($result['passed']);
        self::assertCount(2, $result['messages']);
        self::assertSame('WARN', $result['messages'][0]['code']);
    }

    public function testBlocksStructuralAndCriticalTestErrors(): void
    {
        $xml = <<<'XML'
<Chyby>
  <Chyba Typ="S" Zkr="SCHEMA"><Text>Chyba struktury.</Text></Chyba>
  <Chyba Typ="K" Zkr="FIELD" Polozka="dic"><Text>Chybí DIČ.</Text></Chyba>
  <Chyba Typ="I" Zkr="TEST_REZIM"><Text>Testovací režim.</Text></Chyba>
</Chyby>
XML;

        $result = (new EpoDirectResponseParser())->testResult($xml);

        self::assertFalse($result['passed']);
        self::assertSame('dic', $result['messages'][1]['field']);
    }

    public function testRecognizesOfflineReceiptWithoutExposingItAsError(): void
    {
        $result = (new EpoDirectResponseParser())->submitEnvelope(
            '<Odpoved><Potvrzeni ID_predani="ABC123" Heslo="secret"/></Odpoved>',
        );

        self::assertSame('offline', $result['kind']);
        self::assertSame('ABC123', $result['transfer_id']);
        self::assertSame('secret', $result['transfer_password']);
    }

    public function testVerifiesSignedConfirmationAndMatchesSentCmsBytes(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }
        $sent = random_bytes(200);
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data><Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/></Pisemnost>',
            bin2hex($sent),
        );
        $path = $this->signDer($confirmationXml);
        $bytes = (string) file_get_contents($path);

        $result = (new EpoDirectResponseParser())->confirmation($bytes, $sent);

        self::assertTrue($result['signature_valid']);
        self::assertTrue($result['is_confirmation']);
        self::assertTrue($result['content_match']);
        self::assertFalse($result['epo_signer_valid']);
        self::assertSame('123456', $result['reference']);
        self::assertSame('state-secret', $result['state_password']);
    }

    public function testDoesNotDowngradeEmbeddedContentMismatch(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data><Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/></Pisemnost>',
            bin2hex('different signed payload'),
        );
        $path = $this->signDer($confirmationXml);
        $bytes = (string) file_get_contents($path);

        $result = (new EpoDirectResponseParser())->confirmation($bytes, 'expected payload');

        self::assertTrue($result['signature_valid']);
        self::assertFalse($result['content_match']);
    }

    public function testMatchesEmbeddedOriginalXmlAgainstSubmittedCms(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }
        $sentXml = '<Pisemnost><DPHDP3 dic="CZ00000019" rok="2026"/></Pisemnost>';
        $sentCms = (string) file_get_contents($this->signDer($sentXml));
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data><Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/></Pisemnost>',
            bin2hex($sentXml),
        );
        $confirmation = (string) file_get_contents($this->signDer($confirmationXml));

        $result = (new EpoDirectResponseParser())->confirmation($confirmation, $sentCms);

        self::assertTrue($result['content_match']);
    }

    public function testMatchesEmbeddedKhZipAgainstSubmittedCms(): void
    {
        if (!function_exists('openssl_cms_sign') || !class_exists(\ZipArchive::class)) {
            self::markTestSkipped('OpenSSL CMS nebo ZIP není dostupné.');
        }
        $sentXml = '<Pisemnost><DPHKH1 dic="CZ00000019" rok="2026"/></Pisemnost>';
        $sentZip = $this->zipXml($sentXml);
        $sentCms = (string) file_get_contents($this->signDer($sentZip));
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data><Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/></Pisemnost>',
            bin2hex($sentZip),
        );
        $confirmation = (string) file_get_contents($this->signDer($confirmationXml));

        $result = (new EpoDirectResponseParser())->confirmation($confirmation, $sentCms);

        self::assertTrue($result['content_match']);
    }

    public function testRecognizesOfficialEpoSignerIdentity(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }
        $sent = 'signed payload';
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data><Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/></Pisemnost>',
            bin2hex($sent),
        );
        $path = $this->signDer($confirmationXml, [
            'commonName' => 'Spolecne technicke zarizeni spravcu dane',
            'organizationName' => 'Česká republika - Generální finanční ředitelství',
            'countryName' => 'CZ',
        ]);
        $bytes = (string) file_get_contents($path);

        $result = (new EpoDirectResponseParser($this->lastCertificatePath))
            ->confirmation($bytes, $sent);

        self::assertTrue($result['epo_signer_valid']);
        self::assertTrue($result['chain_valid']);
    }

    public function testRecognizesSandboxSignerOnlyInTestEnvironment(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }
        $sent = 'signed payload';
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data><Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/></Pisemnost>',
            bin2hex($sent),
        );
        $path = $this->signDer($confirmationXml, [
            'commonName' => 'Testovací zařízení - nelze učinit platné podání',
            'organizationName' => 'Česká republika - Generální finanční ředitelství',
            'countryName' => 'CZ',
        ]);
        $bytes = (string) file_get_contents($path);
        $fingerprint = openssl_x509_fingerprint(
            (string) file_get_contents((string) $this->lastCertificatePath),
            'sha256',
        );
        self::assertIsString($fingerprint);
        $parser = new EpoDirectResponseParser(null, [], [$fingerprint]);

        $test = $parser->confirmation($bytes, $sent, 'test');
        $production = $parser->confirmation($bytes, $sent, 'production');
        $withoutTrustAnchor = (new EpoDirectResponseParser())
            ->confirmation($bytes, $sent, 'test');
        $wrongTrustAnchor = (new EpoDirectResponseParser(
            null,
            [],
            [str_repeat('0', 64)],
        ))->confirmation($bytes, $sent, 'test');

        self::assertTrue($test['signature_valid']);
        self::assertTrue($test['epo_signer_valid']);
        self::assertFalse($production['epo_signer_valid']);
        self::assertFalse($withoutTrustAnchor['epo_signer_valid']);
        self::assertFalse($wrongTrustAnchor['epo_signer_valid']);
    }

    public function testConfiguredSignerFingerprintIsEnforced(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }
        $sent = 'signed payload';
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data><Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/></Pisemnost>',
            bin2hex($sent),
        );
        $path = $this->signDer($confirmationXml, [
            'commonName' => 'Spolecne technicke zarizeni spravcu dane',
            'organizationName' => 'Česká republika - Generální finanční ředitelství',
            'countryName' => 'CZ',
        ]);
        $bytes = (string) file_get_contents($path);
        $fingerprint = openssl_x509_fingerprint(
            (string) file_get_contents((string) $this->lastCertificatePath),
            'sha256',
        );
        self::assertIsString($fingerprint);

        $accepted = (new EpoDirectResponseParser(
            $this->lastCertificatePath,
            [$fingerprint],
        ))->confirmation($bytes, $sent);
        $rejected = (new EpoDirectResponseParser(
            $this->lastCertificatePath,
            [str_repeat('0', 64)],
        ))->confirmation($bytes, $sent);

        self::assertTrue($accepted['epo_signer_valid']);
        self::assertFalse($rejected['epo_signer_valid']);
    }

    public function testMissingConfiguredCaBundleFailsClosed(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }
        $sent = 'signed payload';
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data><Podani Cislo="123456" Datum="2026-07-25T10:15:30+02:00" Heslo="state-secret"/></Pisemnost>',
            bin2hex($sent),
        );
        $bytes = (string) file_get_contents($this->signDer($confirmationXml));

        $result = (new EpoDirectResponseParser(
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-epo-ca-bundle.pem',
        ))->confirmation($bytes, $sent);

        self::assertFalse($result['chain_valid']);
    }

    public function testParsesRemoteStatusWithoutDynamicXmlExpansion(): void
    {
        $result = (new EpoDirectResponseParser())->status(
            '<Stav><por_podani>123</por_podani><stav_podapl>3</stav_podapl><stav_podapl_text>Přijato</stav_podapl_text></Stav>',
        );

        self::assertSame('123', $result['por_podani']);
        self::assertSame('3', $result['stav_podapl']);
        self::assertSame('Přijato', $result['stav_podapl_text']);
    }

    /** @param array<string,string>|null $distinguishedName */
    private function signDer(string $content, ?array $distinguishedName = null): string
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
        $key = openssl_pkey_new($options);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(
            $distinguishedName ?? ['commonName' => 'Synthetic EPO Test'],
            $key,
            $options,
        );
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_x509_export($certificate, $certificatePem));
        $this->lastCertificatePath = $this->tempFile($certificatePem);
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

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'epo-direct-test-');
        self::assertNotFalse($path);
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function zipXml(string $xml): string
    {
        $path = $this->tempFile('');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('DPHKH1.xml', $xml));
        self::assertTrue($zip->close());
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        return $bytes;
    }
}
