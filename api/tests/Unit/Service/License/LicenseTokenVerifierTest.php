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
    // ── Rotace podepisovacího klíče (`kid`) ───────────────────────────────

    /**
     * ⚠️ Bez rotace by výměna podepisovacího klíče znamenala vydat novou verzi
     * aplikace — jinak by všechny instalace naráz zahodily platné tokeny.
     * Token proto nese `kid` a instalace může po přechodnou dobu znát oba klíče.
     */
    public function testTokenSignedByASecondKeyVerifiesWhenBothAreKnown(): void
    {
        $second = sodium_crypto_sign_keypair();
        $secondPub = base64_encode(sodium_crypto_sign_publickey($second));
        $secondSec = sodium_crypto_sign_secretkey($second);

        $kid = substr(hash('sha256', $secondPub), 0, 16);
        $token = $this->makeToken(['iid' => 'abc', 'kid' => $kid], $secondSec);

        // Sám o sobě starý klíč nestačí…
        self::assertNull($this->verifier->verify($token, $this->publicKeyBase64));

        // …ale když instalace zná oba, token projde.
        $decoded = $this->verifier->verify($token, [
            substr(hash('sha256', $this->publicKeyBase64), 0, 16) => $this->publicKeyBase64,
            $kid => $secondPub,
        ]);

        self::assertNotNull($decoded);
        self::assertSame('abc', $decoded['iid']);
    }

    /** Starší token bez `kid` musí projít dál — pole je aditivní. */
    public function testTokenWithoutKidStillVerifies(): void
    {
        $token = $this->makeToken(['iid' => 'abc']);

        self::assertNotNull($this->verifier->verify($token, [
            substr(hash('sha256', $this->publicKeyBase64), 0, 16) => $this->publicKeyBase64,
        ]));
    }

    /**
     * ⚠️ `kid` je NÁPOVĚDA, ne autorita — čte se z ještě neověřeného payloadu.
     * Podvržený `kid` ukazující na známý klíč nesmí platný podpis nahradit.
     */
    public function testForgedKidDoesNotBypassTheSignature(): void
    {
        $foreign = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $kid = substr(hash('sha256', $this->publicKeyBase64), 0, 16);

        // Podepsáno CIZÍM klíčem, ale `kid` ukazuje na ten náš.
        $token = $this->makeToken(['iid' => 'abc', 'kid' => $kid], $foreign);

        self::assertNull($this->verifier->verify($token, [$kid => $this->publicKeyBase64]));
    }

    /** Překlep v jednom klíči nesmí zneplatnit ty ostatní. */
    public function testBrokenKeyInTheMapDoesNotBreakTheRest(): void
    {
        $token = $this->makeToken(['iid' => 'abc']);

        self::assertNotNull($this->verifier->verify($token, [
            'rozbity' => 'tohle-neni-base64-klic',
            'spravny' => $this->publicKeyBase64,
        ]));
    }
}