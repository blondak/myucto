<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Epo;

use MyInvoice\Service\Epo\EpoCertificateInspector;
use PHPUnit\Framework\TestCase;

final class EpoCertificateInspectorTest extends TestCase
{
    public function testRecognizesOfficialIkMpsvOidInRawCertificateDer(): void
    {
        $der = "\x30\x0b\x06\x09\x2b\x06\x01\x04\x01\xdc\x19\x02\x01";
        $pem = "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE-----\n";

        self::assertTrue((new EpoCertificateInspector())->containsIkMpsv($pem));
    }

    public function testRecognizesOfficialIkMpsvOidExposedByOpenSslParser(): void
    {
        self::assertTrue((new EpoCertificateInspector())->containsIkMpsv(
            '',
            ['extensions' => [
                'subjectAltName' => 'othername:1.3.6.1.4.1.11801.2.1',
            ]],
        ));
    }

    public function testDoesNotAcceptUnrelatedCertificateExtension(): void
    {
        self::assertFalse((new EpoCertificateInspector())->containsIkMpsv(
            '',
            ['extensions' => [
                'subjectAltName' => 'email:signer@example.test',
                'certificatePolicies' => '1.2.203.7064.1.1.11',
            ]],
        ));
    }
}
