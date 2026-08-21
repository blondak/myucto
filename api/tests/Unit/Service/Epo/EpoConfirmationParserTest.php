<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Epo;

use MyInvoice\Service\Epo\EpoConfirmationExtractor;
use MyInvoice\Service\Epo\EpoConfirmationParser;
use MyInvoice\Service\Epo\EpoDirectResponseParser;
use PHPUnit\Framework\TestCase;

/**
 * Ověření ručně nahrané dodejky u ASISTOVANÉHO podání.
 *
 * Vstup je bajtově týž soubor, jaký dostane přímý kanál z API, takže se tu hlídá
 * hlavně to, aby si obě cesty o téže potvrzence nemyslely něco jiného.
 */
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
            '<?xml version="1.0" encoding="UTF-8"?><Pisemnost><Data>%s</Data>'
            . '<Podani Cislo="123456789" Datum="2026-07-25T10:15:30+02:00" Heslo="secret"/></Pisemnost>',
            bin2hex($sourceXml),
        );
        $p7s = $this->signDer($confirmationXml);

        $result = $this->parser()->parse($p7s, $sourceXml, 'dphdp3');

        self::assertTrue($result['signature_valid']);
        self::assertTrue($result['is_confirmation']);
        self::assertSame('123456789', $result['reference']);
        self::assertSame('dphdp3', $result['embedded_form_code']);
        self::assertTrue($result['form_match']);
        self::assertTrue($result['content_match']);
        self::assertFalse($result['epo_signer_valid']);
        self::assertNotNull($result['submitted_at']);
    }

    /**
     * Redukované echo NESMÍ shodit ověření.
     *
     * EPO v dodejce vrací podání zredukované (bez detailních řádků, s přeformátovanými
     * čísly), takže bajtové porovnání vloženého `<Data>` nevyjde nikdy. Vazbu na
     * odeslaný soubor nese `Kontrola/Soubor/@KC`, což je MD5 odeslaného XML. Než se
     * tahle cesta použila i tady, končila pravá dodejka DPH přiznání jako „neplatná".
     */
    public function testReducedEchoStillMatchesViaChecksum(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }

        $sourceXml = '<?xml version="1.0" encoding="UTF-8"?><Pisemnost>'
            . '<DPHDP3><VetaD dokument="DP3"/><VetaP obrat="12345.00"/></DPHDP3></Pisemnost>';
        $reduced = '<?xml version="1.0" encoding="UTF-8"?><Pisemnost>'
            . '<DPHDP3><VetaD dokument="DP3"/></DPHDP3></Pisemnost>';
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data>'
            . '<Kontrola><Soubor Delka="%d" KC="%s" Nazev="DPHDP3-test" c_ufo="007"/></Kontrola>'
            . '<Podani Cislo="568467011" Datum="2026-08-21T10:36:43" Heslo="tajne123" ZAREP="true"/>'
            . '</Pisemnost>',
            bin2hex($reduced),
            strlen($sourceXml),
            md5($sourceXml),
        );

        $result = $this->parser()->parse($this->signDer($confirmationXml), $sourceXml, 'dphdp3');

        self::assertTrue($result['content_match']);
        self::assertTrue($result['form_match']);
        self::assertSame('568467011', $result['reference']);
        self::assertSame('tajne123', $result['state_password']);
        self::assertSame('568467011', $result['receipt']['reference'] ?? null);
        self::assertTrue($result['receipt']['zarep'] ?? false);
        self::assertSame('007', $result['receipt']['office_code'] ?? null);
    }

    /** Dodejka od jiného formuláře se pozná podle echa, i když podpis sedí. */
    public function testDetectsConfirmationOfAnotherForm(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }

        $sourceXml = '<?xml version="1.0"?><Pisemnost><DPHDP3><VetaD dokument="DP3"/></DPHDP3></Pisemnost>';
        $otherForm = '<?xml version="1.0"?><Pisemnost><DPHKH1 verzePis="03.01"/></Pisemnost>';
        $confirmationXml = sprintf(
            '<Pisemnost><Data>%s</Data>'
            . '<Kontrola><Soubor KC="%s" Nazev="DPHKH1-jine"/></Kontrola>'
            . '<Podani Cislo="999" Datum="2026-08-21T10:36:43" Heslo="x"/></Pisemnost>',
            bin2hex($otherForm),
            md5($otherForm),
        );

        $result = $this->parser()->parse($this->signDer($confirmationXml), $sourceXml, 'dphdp3');

        self::assertSame('dphkh1', $result['embedded_form_code']);
        self::assertFalse($result['form_match']);
        self::assertFalse($result['content_match']);
    }

    public function testRejectsUnsignedBytes(): void
    {
        $path = $this->tempFile('not-a-cms');
        $result = $this->parser()->parse($path, '<Pisemnost/>', 'dphdp3');

        self::assertFalse($result['signature_valid']);
        self::assertFalse($result['is_confirmation']);
        self::assertNull($result['reference']);
        self::assertNull($result['state_password']);
        self::assertSame([], $result['receipt']);
    }

    private function parser(): EpoConfirmationParser
    {
        return new EpoConfirmationParser(
            new EpoDirectResponseParser(null, [], []),
            new EpoConfirmationExtractor(),
        );
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
