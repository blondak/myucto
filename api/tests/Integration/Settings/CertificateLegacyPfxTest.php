<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Bootstrap;
use MyInvoice\Service\Epo\EpoSigningCredentialService;
use MyInvoice\Service\Epo\EpoSubmissionException;
use MyInvoice\Tests\Support\OpensslConfigTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Soubor P12 šifrovaný RC2 (výchozí starší export Windows) OpenSSL 3 bez legacy
 * provideru neotevře. Účetní to nesmí vidět jako špatné heslo.
 */
#[Group('integration')]
final class CertificateLegacyPfxTest extends TestCase
{
    use OpensslConfigTrait;

    private const PASSWORD = 'Synteticke-heslo-1';

    /** Samopodepsaný syntetický certifikát exportovaný přes openssl pkcs12 -export -legacy. */
    private const LEGACY_PFX =
        'MIIDmgIBAzCCA2AGCSqGSIb3DQEHAaCCA1EEggNNMIIDSTCCAj8GCSqGSIb3DQEHBqCCAjAwggIs'
        . 'AgEAMIICJQYJKoZIhvcNAQcBMBwGCiqGSIb3DQEMAQYwDgQIws9YewEDRggCAggAgIIB+I7CPpRu'
        . 'dS5GfQy96RZqbbDcR9ks18T3NHe6Tg0JvjEQjp+OqlqmGAa0rsdaB0RbaqtOQCmy7+iJz0r2uY3h'
        . 'IFZzYoEZuuW3E2/l82CbhvG6ihjqT4c8rf56x1f0YfxMb5OYPgwfCrhQs6GAIqzYcFBC2sPtfsXx'
        . 'nr2X/Hm0R74vuCgqJghOtLLlPNWqZf0RFSKPvKYcuJqg8QQB4dw72SU8nGYKy2496ez/TeSiq57G'
        . 'BkWGYRSC4qoy+bXpzmpISbcvOEpzvfVRX/mgOMGfQB5ANchTt+5A1MDMvEcsErM51ITEfinurrWv'
        . 'riLp0o6JkZEL3KhN/Os/KjXfuqp1euAbdZYTaOKtCwvOWNkKAJ/6lTkSjEmdi6o/8o8DDssMNghJ'
        . 'yeNPptWMLTqqrPPk7PSR0cljoJ6YXUvrjXqlxvkB7g4MybZuRS5G2oN36eRN02QLNze88xrGHhwr'
        . 'cKVMD97t5dulAXWpoVhWFeuguG7Ui/oMx52c8WBSBsC1lC7f8xOsiaTE/s4nSePHofTRlGIHvaC+'
        . 'P20sBBtApq9o62E+G+a8yEoU9Nd1Rm6qMMJhoYWbgyPUzI+r24ty7da7sn59J/cJcI6AA6tgGnTk'
        . 'Q2wf9EVTGtIabd7rSmu/rEdgMiuTDM1NJ/ZU37PciyyepnHUocNcSebEeDCCAQIGCSqGSIb3DQEH'
        . 'AaCB9ASB8TCB7jCB6wYLKoZIhvcNAQwKAQKggbQwgbEwHAYKKoZIhvcNAQwBAzAOBAi0XsjRZINM'
        . '7AICCAAEgZA5BVXRM9NW05nZslTtqTyQrQIxJey/aNamuFi3xhDFkWgrh7L6NyrSrlDdgHs8geUI'
        . 'pASB102gjBrhs5n7Mrk6wys1LSzT11EWvxWIeV+7ARJjAFGgEHs2Ne9f+1NVutEESFDE2UqIyUtL'
        . '/BXwdAUJEYe/sN6sAzfVTOBN2pjemjM+BitnDQlE8twhzXRhHE0xJTAjBgkqhkiG9w0BCRUxFgQU'
        . 'f9UgJzKS5Zp3CwtitTZDZ1dIvuwwMTAhMAkGBSsOAwIaBQAEFLrMpLr8k+b7+q7eqdUFuC/cEaI6'
        . 'BAgm+r4ZXqzAKwICCAA=';

    private EpoSigningCredentialService $credentials;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje konfiguraci.');
        }
        $this->credentials = Bootstrap::buildContainer()->get(EpoSigningCredentialService::class);
    }

    public function testLegacyEncryptionIsNotReportedAsWrongPassword(): void
    {
        $bundle = [];
        if (@openssl_pkcs12_read((string) base64_decode(self::LEGACY_PFX, true), $bundle, self::PASSWORD)) {
            self::markTestSkipped('Server má zapnutý legacy provider OpenSSL, RC2 soubor otevře.');
        }

        try {
            $this->credentials->import(1, 1, 'Syntetický legacy certifikát', (string) base64_decode(self::LEGACY_PFX, true), self::PASSWORD);
            self::fail('Import RC2 souboru má selhat.');
        } catch (EpoSubmissionException $e) {
            self::assertSame('certificate_legacy_encryption', $e->errorCode);
        }
    }

    public function testWrongPasswordStaysInvalidCertificate(): void
    {
        $config = self::opensslConfigArgs();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $config);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'Synteticky test'], $key, ['digest_alg' => 'sha256'] + $config);
        self::assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256'] + $config);
        self::assertNotFalse($cert);
        $pfx = '';
        self::assertTrue(openssl_pkcs12_export($cert, $pfx, $key, self::PASSWORD));

        try {
            $this->credentials->import(1, 1, 'Syntetický certifikát', $pfx, 'jine-heslo');
            self::fail('Import se špatným heslem má selhat.');
        } catch (EpoSubmissionException $e) {
            self::assertSame('invalid_certificate', $e->errorCode);
        }
    }
}
