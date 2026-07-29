<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Epo;

use MyInvoice\Service\Epo\EpoConfirmationParser;
use PHPUnit\Framework\TestCase;

final class EpoConfirmationParserTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    public function testVerifiesCmsAndReadsSubmissionMetadata(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }

        $sourceXml = '<?xml version="1.0" encoding="UTF-8"?><Pisemnost><DPHDP3><VetaD dokument="DP3"/></DPHDP3></Pisemnost>';
        $confirmationXml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><Pisemnost><Data>%s</Data><Podani Cislo="123456789" Datum="2026-07-25T10:15:30+02:00" Heslo="secret"/></Pisemnost>',
            bin2hex($sourceXml),
        );
        $p7s = $this->signDer($confirmationXml);

        $result = (new EpoConfirmationParser())->parse($p7s, $sourceXml, 'dphdp3');

        self::assertTrue($result['signature_valid']);
        self::assertTrue($result['is_confirmation']);
        self::assertSame('123456789', $result['reference']);
        self::assertSame('dphdp3', $result['embedded_form_code']);
        self::assertTrue($result['form_match']);
        self::assertTrue($result['content_match']);
        self::assertFalse($result['epo_signer_valid']);
        self::assertNotNull($result['submitted_at']);
        self::assertArrayNotHasKey('password', $result);
        self::assertArrayNotHasKey('heslo', $result);
    }

    public function testRejectsUnsignedBytes(): void
    {
        $path = $this->tempFile('not-a-cms');
        $result = (new EpoConfirmationParser())->parse($path, '<Pisemnost/>', 'dphdp3');

        self::assertFalse($result['signature_valid']);
        self::assertFalse($result['is_confirmation']);
        self::assertNull($result['reference']);
    }

    private function signDer(string $content): string
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
        $csr = openssl_csr_new(['commonName' => 'Synthetic EPO Test'], $key, $options);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
        self::assertNotFalse($certificate);

        $input = $this->tempFile($content);
        $output = $this->tempFile('');
        $ok = openssl_cms_sign(
            $input,
            $output,
            $certificate,
            $key,
            [],
            OPENSSL_CMS_BINARY,
            OPENSSL_ENCODING_DER,
        );
        self::assertTrue($ok);
        return $output;
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'epo-test-');
        self::assertNotFalse($path);
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }
}
