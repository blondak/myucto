<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Epo;

use MyInvoice\Service\Epo\EpoPkcs7Signer;
use PHPUnit\Framework\TestCase;

final class EpoPkcs7SignerTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    public function testCreatesAttachedDerCmsWithOriginalXml(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }
        [$pfx, $password] = $this->pfx();
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Pisemnost><DPHDP3/></Pisemnost>';

        $signed = (new EpoPkcs7Signer())->sign($xml, $pfx, $password);

        $input = $this->tempFile($signed);
        $content = $this->tempFile('');
        $certificates = $this->tempFile('');
        self::assertTrue(openssl_cms_verify(
            $input,
            OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY,
            $certificates,
            [],
            null,
            $content,
            null,
            null,
            OPENSSL_ENCODING_DER,
        ));
        self::assertSame($xml, file_get_contents($content));
    }

    /** @return array{string,string} */
    private function pfx(): array
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
        $csr = openssl_csr_new(['commonName' => 'Synthetic EPO Signer'], $key, $options);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
        self::assertNotFalse($certificate);
        $password = 'synthetic-pfx-password';
        $pfx = '';
        self::assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, $password));
        return [$pfx, $password];
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'epo-signer-test-');
        self::assertNotFalse($path);
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }
}
