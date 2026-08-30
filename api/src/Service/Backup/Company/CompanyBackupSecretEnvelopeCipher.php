<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Argon2id + XChaCha20-Poly1305 obálka přenositelných secret hodnot. */
final class CompanyBackupSecretEnvelopeCipher
{
    private const MIN_PASSWORD_BYTES = 12;
    private const MAX_PASSWORD_BYTES = 1_024;

    public function seal(
        #[\SensitiveParameter] string $plaintext,
        #[\SensitiveParameter] string $password,
        string $backupId,
        string $registryFingerprint,
    ): CompanyBackupSealedSecretEnvelope {
        $this->assertAvailable();
        $plaintextBytes = strlen($plaintext);
        if ($plaintextBytes < 1
            || $plaintextBytes > CompanyBackupSecretEnvelopeDescriptor::MAX_PLAINTEXT_BYTES
        ) {
            throw new CompanyBackupSecretEnvelopeException(
                'secret_envelope_plaintext_invalid',
            );
        }
        if (!$this->validPassword($password)) {
            throw new CompanyBackupSecretEnvelopeException(
                'secret_envelope_password_weak',
            );
        }

        try {
            $salt = random_bytes(
                CompanyBackupSecretEnvelopeDescriptor::KDF_SALT_BYTES,
            );
            $nonce = random_bytes(
                CompanyBackupSecretEnvelopeDescriptor::CIPHER_NONCE_BYTES,
            );
            $bytes = $plaintextBytes
                + CompanyBackupSecretEnvelopeDescriptor::CIPHER_TAG_BYTES;
            $associatedData = CompanyBackupSecretEnvelopeDescriptor::associatedDataFor(
                base64_encode($salt),
                base64_encode($nonce),
                $bytes,
                $backupId,
                $registryFingerprint,
            );
            $key = $this->deriveKey($password, $salt, false);
            try {
                $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                    $plaintext,
                    $associatedData,
                    $nonce,
                    $key,
                );
            } finally {
                $this->wipe($key);
            }
            if (strlen($ciphertext) !== $bytes) {
                throw new \UnexpectedValueException(
                    'XChaCha20-Poly1305 nevrátil očekávaný ciphertext.',
                );
            }
            return CompanyBackupSealedSecretEnvelope::create(
                $salt,
                $nonce,
                $ciphertext,
            );
        } catch (CompanyBackupSecretEnvelopeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CompanyBackupSecretEnvelopeException(
                'secret_envelope_seal_failed',
                $e,
            );
        }
    }

    public function open(
        CompanyBackupSealedSecretEnvelope $sealed,
        #[\SensitiveParameter] string $password,
        string $backupId,
        string $registryFingerprint,
    ): string {
        $this->assertAvailable();
        if (!$this->validPassword($password)) {
            throw new CompanyBackupSecretEnvelopeException(
                'secret_envelope_unlock_failed',
            );
        }
        try {
            $sealed->descriptor->assertCiphertext($sealed->ciphertext);
            $associatedData = $sealed->descriptor->associatedData(
                $backupId,
                $registryFingerprint,
            );
            $key = $this->deriveKey(
                $password,
                $sealed->descriptor->saltBytes(),
                true,
            );
            try {
                $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                    $sealed->ciphertext,
                    $associatedData,
                    $sealed->descriptor->nonceBytes(),
                    $key,
                );
            } finally {
                $this->wipe($key);
            }
            if (!is_string($plaintext)
                || $plaintext === ''
                || strlen($plaintext)
                    > CompanyBackupSecretEnvelopeDescriptor::MAX_PLAINTEXT_BYTES
            ) {
                throw new CompanyBackupSecretEnvelopeException(
                    'secret_envelope_unlock_failed',
                );
            }
            return $plaintext;
        } catch (CompanyBackupSecretEnvelopeException $e) {
            if ($e->errorCode === 'secret_envelope_context_invalid') {
                throw $e;
            }
            throw new CompanyBackupSecretEnvelopeException(
                'secret_envelope_unlock_failed',
                $e,
            );
        } catch (\Throwable $e) {
            throw new CompanyBackupSecretEnvelopeException(
                'secret_envelope_unlock_failed',
                $e,
            );
        }
    }

    private function deriveKey(
        #[\SensitiveParameter] string $password,
        string $salt,
        bool $unlock,
    ): string {
        try {
            $key = sodium_crypto_pwhash(
                CompanyBackupSecretEnvelopeDescriptor::CIPHER_KEY_BYTES,
                $password,
                $salt,
                CompanyBackupSecretEnvelopeDescriptor::KDF_OPSLIMIT,
                CompanyBackupSecretEnvelopeDescriptor::KDF_MEMLIMIT,
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
            );
            if (strlen($key)
                !== CompanyBackupSecretEnvelopeDescriptor::CIPHER_KEY_BYTES
            ) {
                throw new \UnexpectedValueException(
                    'Argon2id nevrátil očekávaný klíč.',
                );
            }
            return $key;
        } catch (\Throwable $e) {
            throw new CompanyBackupSecretEnvelopeException(
                $unlock
                    ? 'secret_envelope_unlock_failed'
                    : 'secret_envelope_seal_failed',
                $e,
            );
        }
    }

    private function assertAvailable(): void
    {
        if (!function_exists('sodium_crypto_pwhash')
            || !function_exists(
                'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt',
            )
            || !function_exists(
                'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt',
            )
            || !defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13')
        ) {
            throw new CompanyBackupSecretEnvelopeException(
                'secret_envelope_crypto_unavailable',
            );
        }
    }

    private function validPassword(string $password): bool
    {
        $bytes = strlen($password);
        return $bytes >= self::MIN_PASSWORD_BYTES
            && $bytes <= self::MAX_PASSWORD_BYTES
            && !str_contains($password, "\0");
    }

    private function wipe(string &$value): void
    {
        $sensitive = $value;
        $value = '';
        if ($sensitive !== '' && function_exists('sodium_memzero')) {
            sodium_memzero($sensitive);
        }
    }
}
