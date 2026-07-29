<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Http;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Http\OutboundRequestException;
use MyInvoice\Service\Http\OutboundResponse;
use MyInvoice\Service\Http\OutboundUrlGuard;
use MyInvoice\Service\Http\TsaUrlPolicy;
use PHPUnit\Framework\TestCase;

/**
 * SEC-04 — politika pro TSA URL (validace při ukládání profilu i před odesláním).
 * Žádný test nenavazuje síťové spojení: assertStorable DNS neřeší a zakázané cíle
 * padají už na validaci.
 */
final class TsaUrlPolicyTest extends TestCase
{
    private function policy(array $configData = []): TsaUrlPolicy
    {
        return new TsaUrlPolicy(new OutboundUrlGuard(), new Config($configData));
    }

    private function withAllowlist(array $hosts): TsaUrlPolicy
    {
        return $this->policy(['signing' => ['tsa_allowed_hosts' => $hosts]]);
    }

    // --- bez allowlistu platí obecná pravidla ------------------------------

    public function testAllowlistIsEmptyByDefault(): void
    {
        self::assertSame([], $this->policy()->allowedHosts());
    }

    public function testAcceptsPublicHttpsTsaWithoutAllowlist(): void
    {
        $this->expectNotToPerformAssertions();
        $this->policy()->assertStorable('https://freetsa.org/tsr');
    }

    public function testRejectsHttpTsa(): void
    {
        $this->expectException(OutboundRequestException::class);
        $this->policy()->assertStorable('http://freetsa.org/tsr');
    }

    public function testRejectsLoopbackTsa(): void
    {
        $this->expectException(OutboundRequestException::class);
        $this->policy()->assertStorable('https://127.0.0.1:8080/tsr');
    }

    public function testRejectsPrivateRangeTsa(): void
    {
        $this->expectException(OutboundRequestException::class);
        $this->policy()->assertStorable('https://10.20.30.40/tsr');
    }

    public function testRejectsMetadataTsa(): void
    {
        $this->expectException(OutboundRequestException::class);
        $this->policy()->assertStorable('https://169.254.169.254/tsr');
    }

    public function testRejectsUserinfoTsa(): void
    {
        // Uložené TSA credentials by jinak šly obejít credentials v samotné URL.
        $this->expectException(OutboundRequestException::class);
        $this->policy()->assertStorable('https://user:secret@freetsa.org/tsr');
    }

    public function testRejectsFragmentTsa(): void
    {
        $this->expectException(OutboundRequestException::class);
        $this->policy()->assertStorable('https://freetsa.org/tsr#x');
    }

    public function testRejectsFileScheme(): void
    {
        $this->expectException(OutboundRequestException::class);
        $this->policy()->assertStorable('file:///etc/shadow');
    }

    // --- administrátorský allowlist ----------------------------------------

    public function testAllowlistFromArrayConfig(): void
    {
        self::assertSame(['tsa.example.org'], $this->withAllowlist(['tsa.example.org'])->allowedHosts());
    }

    public function testAllowlistFromStringConfig(): void
    {
        $policy = $this->policy(['signing' => ['tsa_allowed_hosts' => 'tsa.example.org, freetsa.org']]);
        self::assertSame(['tsa.example.org', 'freetsa.org'], $policy->allowedHosts());
    }

    public function testAllowlistIgnoresEmptyEntries(): void
    {
        self::assertSame(['tsa.example.org'], $this->withAllowlist(['', '  ', 'tsa.example.org'])->allowedHosts());
    }

    public function testAllowlistAcceptsListedHost(): void
    {
        $this->expectNotToPerformAssertions();
        $this->withAllowlist(['tsa.example.org'])->assertStorable('https://tsa.example.org/tsr');
    }

    public function testAllowlistRejectsUnlistedHost(): void
    {
        $this->expectException(OutboundRequestException::class);
        $this->withAllowlist(['tsa.example.org'])->assertStorable('https://freetsa.org/tsr');
    }

    public function testAllowlistRejectsSuffixTrick(): void
    {
        $this->expectException(OutboundRequestException::class);
        $this->withAllowlist(['tsa.example.org'])->assertStorable('https://tsa.example.org.evil.test/tsr');
    }

    // --- odeslání razítka na zakázaný cíl ----------------------------------

    public function testRequestTimestampRefusesPrivateTarget(): void
    {
        // Musí padnout na validaci, tedy dřív, než se cokoli (včetně credentials) odešle.
        $this->expectException(OutboundRequestException::class);
        $this->policy()->requestTimestamp('https://192.168.1.1/tsr', 'binary-tsq', 'user:heslo');
    }

    // --- OutboundResponse helper -------------------------------------------

    public function testMimeTypeStripsParameters(): void
    {
        $resp = new OutboundResponse(200, 'x', 'application/timestamp-reply; charset=binary');
        self::assertSame('application/timestamp-reply', $resp->mimeType());
    }
}
