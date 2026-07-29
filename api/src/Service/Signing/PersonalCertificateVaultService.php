<?php

declare(strict_types=1);

namespace MyInvoice\Service\Signing;

use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Epo\EpoSigningCredentialService;
use MyInvoice\Service\Epo\EpoSubmissionException;

final class PersonalCertificateVaultService
{
    public function __construct(
        private readonly EpoSigningCredentialService $credentials,
        private readonly SecretEncryption $secrets,
    ) {}

    /** @return list<array<string,mixed>> */
    public function listForSupplier(int $ownerUserId, int $supplierId): array
    {
        return $this->credentials->listOwnedForSupplier($ownerUserId, $supplierId);
    }

    /**
     * @return array{
     *   pfx:string,password_enc:string,certificate_subject:?string,
     *   certificate_email:?string,certificate_fingerprint:?string,
     *   certificate_valid_from:?string,certificate_valid_to:?string,
     *   certificate_usage:array<string,mixed>,credential:array<string,mixed>
     * }
     */
    public function resolve(int $credentialId, int $ownerUserId, int $supplierId): array
    {
        $unlocked = $this->credentials->unlockForSigning(
            $credentialId,
            $ownerUserId,
            $supplierId,
        );
        $bundle = [];
        if (!@openssl_pkcs12_read($unlocked['pfx'], $bundle, $unlocked['password'])) {
            throw new EpoSubmissionException(
                'invalid_certificate',
                'Osobní certifikát nelze otevřít.',
                422,
            );
        }
        $certificate = (string) ($bundle['cert'] ?? '');
        $parsed = $certificate !== '' ? openssl_x509_parse($certificate, false) : false;
        if ($certificate === '' || !is_array($parsed)) {
            throw new EpoSubmissionException(
                'invalid_certificate',
                'Osobní certifikát nelze přečíst.',
                422,
            );
        }
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
        $extensions = (array) ($parsed['extensions'] ?? []);

        return [
            'pfx' => $unlocked['pfx'],
            'password_enc' => $this->secrets->encrypt($unlocked['password']),
            'certificate_subject' => $this->distinguishedName($parsed['subject'] ?? []),
            'certificate_email' => $this->certificateEmail($parsed),
            'certificate_fingerprint' => is_string($fingerprint) && $fingerprint !== ''
                ? strtolower(str_replace(':', '', $fingerprint))
                : null,
            'certificate_valid_from' => isset($parsed['validFrom_time_t'])
                ? date('Y-m-d H:i:s', (int) $parsed['validFrom_time_t'])
                : null,
            'certificate_valid_to' => isset($parsed['validTo_time_t'])
                ? date('Y-m-d H:i:s', (int) $parsed['validTo_time_t'])
                : null,
            'certificate_usage' => [
                'key_usage' => isset($extensions['keyUsage']) ? (string) $extensions['keyUsage'] : null,
                'extended_key_usage' => isset($extensions['extendedKeyUsage'])
                    ? (string) $extensions['extendedKeyUsage']
                    : null,
            ],
            'credential' => $unlocked['credential'],
        ];
    }

    /** @param mixed $dn */
    private function distinguishedName($dn): ?string
    {
        if (!is_array($dn)) {
            return null;
        }
        $parts = [];
        foreach ($dn as $key => $value) {
            if (is_scalar($value) && $value !== '') {
                $parts[] = (string) $key . '=' . (string) $value;
            }
        }
        return $parts !== [] ? implode(',', $parts) : null;
    }

    /** @param array<string,mixed> $parsed */
    private function certificateEmail(array $parsed): ?string
    {
        $subject = (array) ($parsed['subject'] ?? []);
        foreach (['emailAddress', 'E'] as $field) {
            if (!empty($subject[$field]) && is_scalar($subject[$field])) {
                return (string) $subject[$field];
            }
        }
        $san = (string) (($parsed['extensions']['subjectAltName'] ?? ''));
        return preg_match('/email:([^,\s]+)/i', $san, $match) === 1
            ? $match[1]
            : null;
    }
}
