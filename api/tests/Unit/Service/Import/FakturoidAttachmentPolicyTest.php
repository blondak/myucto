<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Http\OutboundRequestException;
use MyInvoice\Service\Http\OutboundUrlGuard;
use MyInvoice\Service\Import\FakturoidClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SEC-13 — attachment URL přichází z odpovědi poskytovatele, ne od nás.
 *
 * Ověřujeme tři samostatné brány:
 *   1) Authorization smí odejít jen na vlastní API origin (jinak exfiltrace tokenu),
 *   2) host musí být na allowlistu download hostů,
 *   3) obsah musí být reálné PDF (Content-Type + magic bytes + velikost).
 */
final class FakturoidAttachmentPolicyTest extends TestCase
{
    // --- 1) Authorization jen na API origin --------------------------------

    public function testAuthorizationAllowedOnApiOrigin(): void
    {
        self::assertTrue(FakturoidClient::mayReceiveAuthorization('app.fakturoid.cz'));
    }

    public function testAuthorizationAllowedIsCaseAndDotInsensitive(): void
    {
        self::assertTrue(FakturoidClient::mayReceiveAuthorization('APP.Fakturoid.CZ.'));
    }

    /**
     * @return list<array{0:string}>
     */
    public static function foreignHostProvider(): array
    {
        return [
            ['files.fakturoid.cz'],                 // povolený download host, ale ne API origin
            ['evil.example.com'],
            ['app.fakturoid.cz.evil.example.com'],  // suffix trik
            ['fakturoid.cz'],
            ['app-fakturoid.cz'],
            ['169.254.169.254'],
            [''],
        ];
    }

    #[DataProvider('foreignHostProvider')]
    public function testAuthorizationRefusedOutsideApiOrigin(string $host): void
    {
        self::assertFalse(FakturoidClient::mayReceiveAuthorization($host));
    }

    // --- 2) allowlist download hostů ---------------------------------------

    public function testDefaultAttachmentHostsAreFakturoidOnly(): void
    {
        foreach (FakturoidClient::defaultAttachmentHosts() as $host) {
            self::assertMatchesRegularExpression('/\.fakturoid\.cz$/', $host, "Cizí host v allowlistu: $host");
        }
    }

    public function testGuardRejectsAttachmentFromForeignHost(): void
    {
        $this->expectException(OutboundRequestException::class);
        (new OutboundUrlGuard())->assertSyntax(
            'https://evil.example.com/attachment.pdf',
            FakturoidClient::defaultAttachmentHosts()
        );
    }

    public function testGuardRejectsAttachmentOverPlainHttp(): void
    {
        $this->expectException(OutboundRequestException::class);
        (new OutboundUrlGuard())->assertSyntax(
            'http://app.fakturoid.cz/attachment.pdf',
            FakturoidClient::defaultAttachmentHosts()
        );
    }

    public function testGuardRejectsAttachmentWithUserinfoSpoof(): void
    {
        $this->expectException(OutboundRequestException::class);
        (new OutboundUrlGuard())->assertSyntax(
            'https://app.fakturoid.cz@evil.example.com/attachment.pdf',
            FakturoidClient::defaultAttachmentHosts()
        );
    }

    // --- 3) obsah musí být PDF ---------------------------------------------

    public function testAcceptsRealPdf(): void
    {
        self::assertTrue(FakturoidClient::isAcceptablePdf("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n", 'application/pdf'));
    }

    public function testAcceptsPdfWithOctetStreamContentType(): void
    {
        // Storage servery často posílají octet-stream — magic bytes rozhodnou.
        self::assertTrue(FakturoidClient::isAcceptablePdf('%PDF-1.7 obsah', 'application/octet-stream'));
    }

    public function testAcceptsPdfWithEmptyContentType(): void
    {
        self::assertTrue(FakturoidClient::isAcceptablePdf('%PDF-1.7 obsah', ''));
    }

    public function testRejectsHtmlErrorPage(): void
    {
        self::assertFalse(FakturoidClient::isAcceptablePdf('<!doctype html><html>…', 'text/html'));
    }

    public function testRejectsHtmlDisguisedAsPdfContentType(): void
    {
        // Správný Content-Type sám nestačí — magic bytes chybí.
        self::assertFalse(FakturoidClient::isAcceptablePdf('<!doctype html>', 'application/pdf'));
    }

    public function testRejectsExecutable(): void
    {
        self::assertFalse(FakturoidClient::isAcceptablePdf("MZ\x90\x00", 'application/octet-stream'));
    }

    public function testRejectsZipDisguisedAsPdf(): void
    {
        self::assertFalse(FakturoidClient::isAcceptablePdf("PK\x03\x04", 'application/pdf'));
    }

    public function testRejectsEmptyBody(): void
    {
        self::assertFalse(FakturoidClient::isAcceptablePdf('', 'application/pdf'));
    }

    public function testRejectsOversizedBody(): void
    {
        // 20 MiB je strop; o bajt víc už neprojde (DoS na RAM i storage).
        $body = '%PDF-1.4' . str_repeat('A', 20 * 1024 * 1024);
        self::assertFalse(FakturoidClient::isAcceptablePdf($body, 'application/pdf'));
    }

    public function testContentTypeParametersAreIgnored(): void
    {
        // mimeType() ořezává parametry — sem přichází už holý typ.
        self::assertTrue(FakturoidClient::isAcceptablePdf('%PDF-1.4 x', 'application/pdf'));
    }
}
