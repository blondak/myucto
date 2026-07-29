<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Service\License\LicenseTokenVerifier;

/**
 * Podepisování licenčních tokenů pro testy. Vygeneruje vlastní Ed25519 keypair —
 * veřejný klíč se testu vstříkne do LicenseService přes cfg `license.public_key`,
 * takže podepsané tokeny projdou ověřením (bez znalosti produkčního privátního
 * klíče k DEFAULT_PUBLIC_KEY).
 */
trait LicenseTokenTrait
{
    private ?string $licSecretKey = null;
    private ?string $licPublicKeyBase64 = null;

    private function licKeypair(): void
    {
        if ($this->licSecretKey === null) {
            $keypair = sodium_crypto_sign_keypair();
            $this->licSecretKey       = sodium_crypto_sign_secretkey($keypair);
            $this->licPublicKeyBase64 = base64_encode(sodium_crypto_sign_publickey($keypair));
        }
    }

    protected function licensePublicKeyBase64(): string
    {
        $this->licKeypair();
        return (string) $this->licPublicKeyBase64;
    }

    /**
     * Podepíše payload testovacím privátním klíčem (nebo předaným cizím klíčem pro
     * scénář neplatného podpisu) ve formátu, který čeká LicenseTokenVerifier:
     * base64url(JSON) . "." . base64url(Ed25519 detached podpis).
     *
     * @param array<string,mixed> $payload
     */
    protected function signLicenseToken(array $payload, ?string $secretKey = null): string
    {
        $this->licKeypair();
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = sodium_crypto_sign_detached($json, $secretKey ?? (string) $this->licSecretKey);

        return LicenseTokenVerifier::base64UrlEncode($json) . '.' . LicenseTokenVerifier::base64UrlEncode($signature);
    }

    /** Privátní klíč jiného keypairu — token jím podepsaný musí ověření selhat. */
    protected function foreignSecretKey(): string
    {
        return sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    }
}
