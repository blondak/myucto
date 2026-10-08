<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Epo;

use MyInvoice\Service\Epo\EpoConfirmationExtractor;
use MyInvoice\Service\Epo\EpoConfirmationParser;
use MyInvoice\Service\Epo\EpoDirectResponseParser;
use MyInvoice\Service\Epo\EpoSubmissionXmlComparator;
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

    /**
     * Podání přes portál EPO: portál písemnost přegeneruje, takže `@KC` je MD5 jeho
     * XML, ne exportu. Platná dodejka se stejnými hodnotami nesmí skončit jako
     * „neplatná" — rozhoduje věcné porovnání echa (issue #134).
     */
    public function testPortalReserializedEchoMatchesSnapshotByContent(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }

        $result = $this->parser()->parse(
            $this->signDer($this->portalConfirmation($this->portalEcho('100000.0'))),
            self::EXPORT_DPHDP3,
            'dphdp3',
        );

        self::assertTrue($result['form_match']);
        self::assertTrue($result['content_match']);
        self::assertNull($result['content_diff']);
    }

    /** Hodnota změněná ve formuláři portálu se naopak musí ukázat jako neshoda. */
    public function testPortalEchoWithChangedAmountStaysMismatch(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('OpenSSL CMS není dostupné.');
        }

        $result = $this->parser()->parse(
            $this->signDer($this->portalConfirmation($this->portalEcho('100001'))),
            self::EXPORT_DPHDP3,
            'dphdp3',
        );

        self::assertFalse($result['content_match']);
        self::assertSame(1, $result['content_diff']['difference_count'] ?? null);
        self::assertSame(
            ['path' => 'Pisemnost/DPHDP3[1]/Veta1[1]@obrat23', 'expected' => '100000', 'actual' => '100001'],
            $result['content_diff']['differences'][0] ?? null,
        );
    }

    private const EXPORT_DPHDP3 = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <Pisemnost nazevSW="MyÚčto.cz" verzeSW="6.31.0">
          <DPHDP3 verzePis="03.01">
            <VetaD k_uladis="DPH" rok="2026" mesic="9" dapdph_forma="B" dokument="DP3" typ_platce="P" c_okec="621000" d_poddp="10.10.2026" trans="A"/>
            <VetaP c_ufo="461" c_pracufo="3201" dic="1234567890" typ_ds="F" jmeno="Jan" prijmeni="Vzorový" ulice="Vzorová" c_pop="1" naz_obce="Praha" psc="11000" stat="ČESKÁ REPUBLIKA"/>
            <Veta1 obrat23="100000" dan23="21000"/>
            <Veta4 pln23="10000" odp_tuz23_nar="2100" odp_sum_nar="2100"/>
            <Veta6 dan_zocelk="21000" odp_zocelk="2100" dano_da="18900"/>
            <VetaR poradi="1"/>
          </DPHDP3>
        </Pisemnost>
        XML;

    private function portalEcho(string $obrat23): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<Pisemnost nazevSW="EPO MF ČR" verzeSW="51.2.1">' . "\n"
            . '<DPHDP3 verzePis="03.01">' . "\n"
            . '<VetaD c_okec="621000" d_poddp="10.10.2026" dapdph_forma="B" dokument="DP3" k_uladis="DPH" mesic="9" rok="2026" trans="A" typ_platce="P" />' . "\n"
            . '<VetaP c_pop="1" c_pracufo="3201" c_ufo="461" dic="1234567890" jmeno="Jan" naz_obce="Praha 1" prijmeni="Vzorový" psc="11000" stat="ČESKÁ REPUBLIKA" typ_ds="F" ulice="Vzorová" />' . "\n"
            . '<Veta1 dan23="21000" obrat23="' . $obrat23 . '" />' . "\n"
            . '<Veta4 odp_sum_nar="2100" odp_tuz23_nar="2100" pln23="10000" />' . "\n"
            . '<Veta6 dan_zocelk="21000" dano_da="18900" odp_zocelk="2100" />' . "\n"
            . '</DPHDP3>' . "\n"
            . '<Kontrola><Soubor Delka="590" KC="' . md5('portal') . '" Nazev="DPHDP3-1234567890-20261010-120000" c_ufo="461" /></Kontrola></Pisemnost>';
    }

    private function portalConfirmation(string $echo): string
    {
        return sprintf(
            '<Pisemnost><Data>%s</Data>'
            . '<Kontrola><Soubor Delka="590" KC="%s" Nazev="DPHDP3-1234567890-20261010-120000" c_ufo="461"/></Kontrola>'
            . '<Podani Cislo="568467012" Datum="2026-10-10T12:00:00" Heslo="tajne"/></Pisemnost>',
            bin2hex($echo),
            md5('portal'),
        );
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
            new EpoSubmissionXmlComparator(),
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
