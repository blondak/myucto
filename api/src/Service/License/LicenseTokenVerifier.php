<?php

declare(strict_types=1);

namespace MyInvoice\Service\License;

/**
 * Ověření licenčního tokenu vydaného licenčním serverem.
 *
 * Token = base64url(JSON payload) . "." . base64url(Ed25519 detached podpis
 * syrových JSON bytů payloadu). Podpis ověřujeme zabudovaným veřejným klíčem —
 * nativním `sodium_crypto_sign_verify_detached` (PHP má libsodium v jádře od 7.2),
 * s fallbackem na `ParagonIE\Sodium\Compat`, pokud by nativní rozšíření chybělo.
 */
final class LicenseTokenVerifier
{
    /**
     * Dekóduje a ověří token. Vrací dekódovaný payload (asociativní pole) když
     * podpis sedí a payload je validní JSON objekt; jinak null (neplatný/poškozený
     * token → volající s ním zachází jako s degradovaným stavem).
     *
     * @return array<string,mixed>|null
     */
    public function verify(string $token, string $publicKeyBase64): ?array
    {
        $token = trim($token);
        if ($token === '' || substr_count($token, '.') !== 1) {
            return null;
        }

        [$payloadPart, $signaturePart] = explode('.', $token, 2);
        $payloadJson = self::base64UrlDecode($payloadPart);
        $signature   = self::base64UrlDecode($signaturePart);
        $publicKey   = base64_decode($publicKeyBase64, true);

        if ($payloadJson === null || $signature === null || $publicKey === false) {
            return null;
        }
        // Ed25519: 64B podpis, 32B veřejný klíč. Špatná délka = odmítnout dřív,
        // než sodium hodí výjimku.
        if (strlen($signature) !== 64 || strlen($publicKey) !== 32) {
            return null;
        }

        if (!$this->verifyDetached($signature, $payloadJson, $publicKey)) {
            return null;
        }

        try {
            $payload = json_decode($payloadJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    private function verifyDetached(string $signature, string $message, string $publicKey): bool
    {
        try {
            if (function_exists('sodium_crypto_sign_verify_detached')) {
                return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
            }
            if (class_exists(\ParagonIE_Sodium_Compat::class)) {
                return \ParagonIE_Sodium_Compat::crypto_sign_verify_detached($signature, $message, $publicKey);
            }
        } catch (\Throwable) {
            return false;
        }
        // Bez libsodium ani sodium_compat nedokážeme podpis ověřit → fail-closed.
        return false;
    }

    private static function base64UrlDecode(string $data): ?string
    {
        $data = strtr($data, '-_', '+/');
        $pad  = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($data, true);
        return $decoded === false ? null : $decoded;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
