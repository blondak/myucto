<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

final class EpoCertificateInspector
{
    private const IK_MPSV_OID = '1.3.6.1.4.1.11801.2.1';
    private const IK_MPSV_OID_DER = "\x06\x09\x2b\x06\x01\x04\x01\xdc\x19\x02\x01";

    /** @param array<string,mixed> $parsed */
    public function containsIkMpsv(string $certificatePem, array $parsed = []): bool
    {
        $encoded = preg_replace(
            '/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/',
            '',
            $certificatePem,
        );
        $der = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (is_string($der) && str_contains($der, self::IK_MPSV_OID_DER)) {
            return true;
        }

        $stack = [$parsed['extensions'] ?? []];
        while ($stack !== []) {
            $value = array_pop($stack);
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $stack[] = $key;
                    $stack[] = $item;
                }
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $text = mb_strtolower((string) $value);
            if (str_contains($text, self::IK_MPSV_OID) || str_contains($text, 'mpsv')) {
                return true;
            }
        }
        return false;
    }
}
