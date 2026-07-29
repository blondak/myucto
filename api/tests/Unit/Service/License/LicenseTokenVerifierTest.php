<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\License;

use MyInvoice\Service\License\LicenseTokenVerifier;
use PHPUnit\Framework\TestCase;

final class LicenseTokenVerifierTest extends TestCase
{
    private LicenseTokenVerifier $verifier;
    private string $publicKeyBase64;
    private string $secretKey;

    protected function setUp(): void
    {
        $this->verifier = new LicenseTokenVerifier();
        $keypair = sodium_crypto_sign_keypair();
        $this->publicKeyBase64 = base64_encode(sodium_crypto_sign_publickey($keypair));
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
    }

    /** @param array<string,mixed> $payload */
    private function makeToken(array $payload, ?string $secretKey = null): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = sodium_crypto_sign_detached($json, $secretKey ?? $this->secretKey);
        $b64u = static fn (string $d): string => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        return $b64u($json) . '.' . $b64u($signature);
    }

    public function testValidTokenVerifiesAndDecodes(): void
    {
        $payload = ['lic' => 1, 'iid' => 'abc', 'tier' => 'single', 'valid_until' => 123, 'status' => 'ok'];
        $token = $this->makeToken($payload);

        $decoded = $this->verifier->verify($token, $this->publicKeyBase64);

        self::assertNotNull($decoded);
        self::assertSame('abc', $decoded['iid']);
        self::assertSame('single', $decoded['tier']);
    }

    public function testWrongPublicKeyRejects(): void
    {
        $token = $this->makeToken(['iid' => 'abc']);
        $otherKey = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

        self::assertNull($this->verifier->verify($token, $otherKey));
    }

    public function testTamperedPayloadRejects(): void
    {
        $token = $this->makeToken(['iid' => 'abc', 'users' => 5]);
        [$payloadPart, $sig] = explode('.', $token, 2);
        // Přepiš payload jiným (validní base64url, ale nepodepsaný) obsahem.
        $tampered = rtrim(strtr(base64_encode('{"iid":"abc","users":9999}'), '+/', '-_'), '=') . '.' . $sig;

        self::assertNull($this->verifier->verify($tampered, $this->publicKeyBase64));
    }

    public function testMalformedTokenRejects(): void
    {
        self::assertNull($this->verifier->verify('garbage', $this->publicKeyBase64));
        self::assertNull($this->verifier->verify('a.b.c', $this->publicKeyBase64));
        self::assertNull($this->verifier->verify('', $this->publicKeyBase64));
    }

    public function testSignedByWrongKeyRejects(): void
    {
        $otherSecret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $token = $this->makeToken(['iid' => 'abc'], $otherSecret);

        self::assertNull($this->verifier->verify($token, $this->publicKeyBase64));
    }
}
