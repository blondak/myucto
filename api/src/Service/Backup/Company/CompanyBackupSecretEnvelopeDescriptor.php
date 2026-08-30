<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;

/** Veřejná, přísně verzovaná kryptografická metadata jednoho secret envelope. */
final readonly class CompanyBackupSecretEnvelopeDescriptor
{
    public const FORMAT = 'myucto-company-secret-envelope';
    public const VERSION = 1;
    public const CAPABILITY = 'secret-envelope.v1';
    public const PATH = CompanyBackupArchiveLayout::SECRET_ENVELOPE;
    public const KDF_ALGORITHM = 'argon2id13';
    public const KDF_OPSLIMIT = 2;
    public const KDF_MEMLIMIT = 67_108_864;
    public const KDF_SALT_BYTES = 16;
    public const CIPHER_ALGORITHM = 'xchacha20-poly1305-ietf';
    public const CIPHER_NONCE_BYTES = 24;
    public const CIPHER_KEY_BYTES = 32;
    public const CIPHER_TAG_BYTES = 16;
    public const MAX_PLAINTEXT_BYTES = 16_777_216;

    private function __construct(
        public string $path,
        public int $bytes,
        public string $sha256,
        public string $kdfSalt,
        public string $cipherNonce,
    ) {}

    public static function fromArray(mixed $value): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid();
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'bytes',
            'cipher',
            'format',
            'kdf',
            'path',
            'sha256',
            'version',
        ] || $value['format'] !== self::FORMAT
            || $value['version'] !== self::VERSION
            || $value['path'] !== self::PATH
            || !is_int($value['bytes'])
            || $value['bytes'] < self::CIPHER_TAG_BYTES + 1
            || $value['bytes'] > self::MAX_PLAINTEXT_BYTES + self::CIPHER_TAG_BYTES
            || !is_string($value['sha256'])
            || preg_match('/^[0-9a-f]{64}$/D', $value['sha256']) !== 1
        ) {
            throw self::invalid();
        }
        $kdf = self::object($value['kdf']);
        $kdfKeys = array_keys($kdf);
        sort($kdfKeys, SORT_STRING);
        if ($kdfKeys !== ['algorithm', 'memlimit', 'opslimit', 'salt']
            || $kdf['algorithm'] !== self::KDF_ALGORITHM
            || $kdf['opslimit'] !== self::KDF_OPSLIMIT
            || $kdf['memlimit'] !== self::KDF_MEMLIMIT
            || !is_string($kdf['salt'])
        ) {
            throw self::invalid();
        }
        self::decodeBase64($kdf['salt'], self::KDF_SALT_BYTES);

        $cipher = self::object($value['cipher']);
        $cipherKeys = array_keys($cipher);
        sort($cipherKeys, SORT_STRING);
        if ($cipherKeys !== ['algorithm', 'nonce']
            || $cipher['algorithm'] !== self::CIPHER_ALGORITHM
            || !is_string($cipher['nonce'])
        ) {
            throw self::invalid();
        }
        self::decodeBase64($cipher['nonce'], self::CIPHER_NONCE_BYTES);

        return new self(
            self::PATH,
            $value['bytes'],
            $value['sha256'],
            $kdf['salt'],
            $cipher['nonce'],
        );
    }

    public static function fromCiphertext(
        string $salt,
        string $nonce,
        string $ciphertext,
    ): self {
        return self::fromArray([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'path' => self::PATH,
            'bytes' => strlen($ciphertext),
            'sha256' => hash('sha256', $ciphertext),
            'kdf' => [
                'algorithm' => self::KDF_ALGORITHM,
                'opslimit' => self::KDF_OPSLIMIT,
                'memlimit' => self::KDF_MEMLIMIT,
                'salt' => base64_encode($salt),
            ],
            'cipher' => [
                'algorithm' => self::CIPHER_ALGORITHM,
                'nonce' => base64_encode($nonce),
            ],
        ]);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'path' => $this->path,
            'bytes' => $this->bytes,
            'sha256' => $this->sha256,
            'kdf' => [
                'algorithm' => self::KDF_ALGORITHM,
                'opslimit' => self::KDF_OPSLIMIT,
                'memlimit' => self::KDF_MEMLIMIT,
                'salt' => $this->kdfSalt,
            ],
            'cipher' => [
                'algorithm' => self::CIPHER_ALGORITHM,
                'nonce' => $this->cipherNonce,
            ],
        ];
    }

    public function saltBytes(): string
    {
        return self::decodeBase64($this->kdfSalt, self::KDF_SALT_BYTES);
    }

    public function nonceBytes(): string
    {
        return self::decodeBase64($this->cipherNonce, self::CIPHER_NONCE_BYTES);
    }

    public function assertCiphertext(string $ciphertext): void
    {
        if (strlen($ciphertext) !== $this->bytes
            || !hash_equals($this->sha256, hash('sha256', $ciphertext))
        ) {
            throw self::invalid();
        }
    }

    public function associatedData(
        string $backupId,
        string $registryFingerprint,
    ): string {
        self::assertContext($backupId, $registryFingerprint);
        return self::associatedDataFor(
            $this->kdfSalt,
            $this->cipherNonce,
            $this->bytes,
            $backupId,
            $registryFingerprint,
        );
    }

    public static function associatedDataFor(
        string $kdfSalt,
        string $cipherNonce,
        int $bytes,
        string $backupId,
        string $registryFingerprint,
    ): string {
        self::decodeBase64($kdfSalt, self::KDF_SALT_BYTES);
        self::decodeBase64($cipherNonce, self::CIPHER_NONCE_BYTES);
        if ($bytes < self::CIPHER_TAG_BYTES + 1
            || $bytes > self::MAX_PLAINTEXT_BYTES + self::CIPHER_TAG_BYTES
        ) {
            throw self::invalid();
        }
        self::assertContext($backupId, $registryFingerprint);
        return CanonicalJson::encode([
            'backup_id' => $backupId,
            'bytes' => $bytes,
            'cipher' => [
                'algorithm' => self::CIPHER_ALGORITHM,
                'nonce' => $cipherNonce,
            ],
            'domain' => 'myucto-company-secret-envelope-aad-v1',
            'format' => self::FORMAT,
            'kdf' => [
                'algorithm' => self::KDF_ALGORITHM,
                'memlimit' => self::KDF_MEMLIMIT,
                'opslimit' => self::KDF_OPSLIMIT,
                'salt' => $kdfSalt,
            ],
            'path' => self::PATH,
            'registry_fingerprint' => $registryFingerprint,
            'version' => self::VERSION,
        ]);
    }

    private static function assertContext(
        string $backupId,
        string $registryFingerprint,
    ): void {
        if (preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}'
                . '-[0-9a-f]{12}$/D',
            $backupId,
        ) !== 1
            || preg_match('/^sha256:[0-9a-f]{64}$/D', $registryFingerprint) !== 1
        ) {
            throw new CompanyBackupSecretEnvelopeException(
                'secret_envelope_context_invalid',
            );
        }
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid();
        }
        return $value;
    }

    private static function decodeBase64(string $value, int $bytes): string
    {
        $decoded = base64_decode($value, true);
        if (!is_string($decoded)
            || strlen($decoded) !== $bytes
            || !hash_equals(base64_encode($decoded), $value)
        ) {
            throw self::invalid();
        }
        return $decoded;
    }

    private static function invalid(): CompanyBackupSecretEnvelopeException
    {
        return new CompanyBackupSecretEnvelopeException(
            'secret_envelope_descriptor_invalid',
        );
    }
}
