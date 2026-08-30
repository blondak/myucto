<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupSealedSecretEnvelope;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeCipher;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeDescriptor;
use MyInvoice\Service\Backup\Company\CompanyBackupSecretEnvelopeException;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSecretEnvelopeCipherTest extends TestCase
{
    private const PASSWORD = 'synthetic-backup-password-42';
    private const BACKUP_ID = '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a1';
    private const REGISTRY_FINGERPRINT =
        'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testRoundTripsWithVersionedArgonAndXChaChaDescriptor(): void
    {
        $plaintext = CanonicalJson::encode([
            'format' => 'synthetic-secret-payload',
            'value_base64' => base64_encode("synthetic-secret\0bytes"),
            'version' => 1,
        ]);
        $cipher = new CompanyBackupSecretEnvelopeCipher();

        $first = $cipher->seal(
            $plaintext,
            self::PASSWORD,
            self::BACKUP_ID,
            self::REGISTRY_FINGERPRINT,
        );
        $second = $cipher->seal(
            $plaintext,
            self::PASSWORD,
            self::BACKUP_ID,
            self::REGISTRY_FINGERPRINT,
        );

        self::assertSame(
            [
                'algorithm' => 'xchacha20-poly1305-ietf',
                'nonce' => $first->descriptor->cipherNonce,
            ],
            $first->descriptor->toArray()['cipher'],
        );
        self::assertSame(
            [
                'algorithm' => 'argon2id13',
                'opslimit' => 2,
                'memlimit' => 67_108_864,
                'salt' => $first->descriptor->kdfSalt,
            ],
            $first->descriptor->toArray()['kdf'],
        );
        self::assertSame(
            'secrets/tenant.sealed',
            $first->descriptor->path,
        );
        self::assertSame(strlen($first->ciphertext), $first->descriptor->bytes);
        self::assertSame(
            hash('sha256', $first->ciphertext),
            $first->descriptor->sha256,
        );
        self::assertNotSame(
            $first->descriptor->kdfSalt,
            $second->descriptor->kdfSalt,
        );
        self::assertNotSame(
            $first->descriptor->cipherNonce,
            $second->descriptor->cipherNonce,
        );
        self::assertNotSame($first->ciphertext, $second->ciphertext);
        self::assertStringNotContainsString('synthetic-secret', $first->ciphertext);
        self::assertSame(
            $plaintext,
            $cipher->open(
                $first,
                self::PASSWORD,
                self::BACKUP_ID,
                self::REGISTRY_FINGERPRINT,
            ),
        );
    }

    public function testWrongPasswordTamperingAndContextMismatchShareOneError(): void
    {
        $cipher = new CompanyBackupSecretEnvelopeCipher();
        $sealed = $cipher->seal(
            '{"synthetic":true}',
            self::PASSWORD,
            self::BACKUP_ID,
            self::REGISTRY_FINGERPRINT,
        );
        $tamperedCiphertext = $sealed->ciphertext;
        $tamperedCiphertext[0] = $tamperedCiphertext[0] === "\0" ? "\1" : "\0";
        $descriptor = $sealed->descriptor->toArray();
        $descriptor['sha256'] = hash('sha256', $tamperedCiphertext);
        $tampered = CompanyBackupSealedSecretEnvelope::fromArray(
            $descriptor,
            $tamperedCiphertext,
        );

        foreach ([
            'wrong password' => [
                $sealed,
                'synthetic-wrong-password-42',
                self::BACKUP_ID,
                self::REGISTRY_FINGERPRINT,
            ],
            'changed ciphertext' => [
                $tampered,
                self::PASSWORD,
                self::BACKUP_ID,
                self::REGISTRY_FINGERPRINT,
            ],
            'different backup' => [
                $sealed,
                self::PASSWORD,
                '0191f7a0-7c22-7bd1-8cd4-6e18cb55b8a2',
                self::REGISTRY_FINGERPRINT,
            ],
            'different registry' => [
                $sealed,
                self::PASSWORD,
                self::BACKUP_ID,
                'sha256:' . str_repeat('f', 64),
            ],
        ] as $case) {
            try {
                $cipher->open(...$case);
                self::fail('Neplatné odemčení nesmí vrátit secret payload.');
            } catch (CompanyBackupSecretEnvelopeException $e) {
                self::assertSame('secret_envelope_unlock_failed', $e->errorCode);
            }
        }
    }

    public function testRejectsNonCanonicalOrHostileDescriptorBeforeKdf(): void
    {
        $cipher = new CompanyBackupSecretEnvelopeCipher();
        $valid = $cipher->seal(
            '{"synthetic":true}',
            self::PASSWORD,
            self::BACKUP_ID,
            self::REGISTRY_FINGERPRINT,
        )->descriptor->toArray();

        foreach ([
            [...$valid, 'path' => 'secrets/other.sealed'],
            [...$valid, 'extra' => true],
            [...$valid, 'kdf' => [...$valid['kdf'], 'opslimit' => 1]],
            [...$valid, 'kdf' => [...$valid['kdf'], 'memlimit' => 1_073_741_824]],
            [...$valid, 'kdf' => [...$valid['kdf'], 'salt' => 'not-base64']],
            [...$valid, 'cipher' => [...$valid['cipher'], 'nonce' => 'not-base64']],
        ] as $descriptor) {
            try {
                CompanyBackupSecretEnvelopeDescriptor::fromArray($descriptor);
                self::fail('Neplatný descriptor nesmí řídit KDF ani cipher.');
            } catch (CompanyBackupSecretEnvelopeException $e) {
                self::assertSame(
                    'secret_envelope_descriptor_invalid',
                    $e->errorCode,
                );
            }
        }
    }

    public function testRejectsWeakPasswordAndEmptyPayload(): void
    {
        $cipher = new CompanyBackupSecretEnvelopeCipher();
        try {
            $cipher->seal(
                '{"synthetic":true}',
                'short',
                self::BACKUP_ID,
                self::REGISTRY_FINGERPRINT,
            );
            self::fail('Slabé heslo nesmí odvodit klíč envelope.');
        } catch (CompanyBackupSecretEnvelopeException $e) {
            self::assertSame('secret_envelope_password_weak', $e->errorCode);
        }

        try {
            $cipher->seal(
                '',
                self::PASSWORD,
                self::BACKUP_ID,
                self::REGISTRY_FINGERPRINT,
            );
            self::fail('Prázdný secret payload nesmí vytvořit envelope.');
        } catch (CompanyBackupSecretEnvelopeException $e) {
            self::assertSame('secret_envelope_plaintext_invalid', $e->errorCode);
        }
    }
}
