<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\License;

use MyInvoice\Service\License\LicenseTokenVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Fallback ověření licenčního tokenu bez nativního `ext-sodium`.
 *
 * `LicenseTokenVerifier::verifyDetached()` deklaruje fallback na
 * `ParagonIE_Sodium_Compat`, jenže balíček `paragonie/sodium_compat` v repozitáři
 * dlouho vůbec nebyl. `class_exists()` tedy vracelo false, ověření spadlo do
 * fail-closed větve a instalace bez libsodia skončila v licenčním stavu
 * `degraded` — bez komerčních funkcí a bez jediné nápovědy proč.
 *
 * Tenhle test drží obojí: že polyfill je nainstalovaný a že jeho výsledek
 * odpovídá nativnímu ověření nad naším formátem tokenu.
 *
 * Klíče i payload jsou vygenerované za běhu — v repozitáři není žádný materiál
 * skutečné licence.
 */
final class LicenseTokenVerifierSodiumFallbackTest extends TestCase
{
    public function testSodiumCompatPolyfillIsInstalled(): void
    {
        self::assertTrue(
            class_exists(\ParagonIE_Sodium_Compat::class),
            'Bez paragonie/sodium_compat nemá LicenseTokenVerifier na co degradovat.'
        );
    }

    public function testCompatVerifiesTokenSignedWithNativeSodium(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('Test potřebuje libsodium pro vygenerování páru klíčů.');
        }

        [$publicKey, $secretKey] = self::keypair();
        $payloadJson = json_encode(['tier' => 'pro', 'valid_until' => 1893456000], JSON_THROW_ON_ERROR);
        $signature   = sodium_crypto_sign_detached($payloadJson, $secretKey);

        // Přesně to volání, které dělá fallback větev ve verifyDetached().
        self::assertTrue(
            \ParagonIE_Sodium_Compat::crypto_sign_verify_detached($signature, $payloadJson, $publicKey),
            'Polyfill neověřil podpis, který nativní libsodium vytvořilo.'
        );
    }

    public function testCompatRejectsTamperedPayload(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('Test potřebuje libsodium pro vygenerování páru klíčů.');
        }

        [$publicKey, $secretKey] = self::keypair();
        $payloadJson = json_encode(['tier' => 'basic'], JSON_THROW_ON_ERROR);
        $signature   = sodium_crypto_sign_detached($payloadJson, $secretKey);

        $tampered = json_encode(['tier' => 'enterprise'], JSON_THROW_ON_ERROR);

        self::assertFalse(
            \ParagonIE_Sodium_Compat::crypto_sign_verify_detached($signature, $tampered, $publicKey)
        );
    }

    public function testVerifierAcceptsWellFormedToken(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('Test potřebuje libsodium pro vygenerování páru klíčů.');
        }

        [$publicKey, $secretKey] = self::keypair();
        $payload     = ['tier' => 'pro', 'instance_id' => 'test-instance', 'valid_until' => 1893456000];
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $token       = LicenseTokenVerifier::base64UrlEncode($payloadJson)
            . '.' . LicenseTokenVerifier::base64UrlEncode(sodium_crypto_sign_detached($payloadJson, $secretKey));

        $verified = (new LicenseTokenVerifier())->verify($token, base64_encode($publicKey));

        self::assertSame($payload, $verified);
    }

    public function testVerifierRejectsTokenSignedByForeignKey(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('Test potřebuje libsodium pro vygenerování páru klíčů.');
        }

        [, $secretKey]     = self::keypair();
        [$otherPublicKey,] = self::keypair();

        $payloadJson = json_encode(['tier' => 'pro'], JSON_THROW_ON_ERROR);
        $token       = LicenseTokenVerifier::base64UrlEncode($payloadJson)
            . '.' . LicenseTokenVerifier::base64UrlEncode(sodium_crypto_sign_detached($payloadJson, $secretKey));

        self::assertNull((new LicenseTokenVerifier())->verify($token, base64_encode($otherPublicKey)));
    }

    /** @return array{0:string,1:string} [veřejný klíč, tajný klíč] */
    private static function keypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [sodium_crypto_sign_publickey($pair), sodium_crypto_sign_secretkey($pair)];
    }
}
