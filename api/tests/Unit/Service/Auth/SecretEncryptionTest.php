<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SecretEncryptionTest extends TestCase
{
    /** @param list<string> $previousKeys */
    private function makeWithConfig(
        string $base64Key,
        string $pepper,
        array $previousKeys = [],
    ): SecretEncryption
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        // Inject config table přes reflexi — keep test bez DI containeru
        $prop = new \ReflectionProperty($config, 'data');
        $prop->setValue($config, [
            'app' => [
                'secret_encryption_key' => $base64Key,
                'secret_encryption_previous_keys' => $previousKeys,
                'pepper' => $pepper,
            ],
        ]);
        return new SecretEncryption($config);
    }

    private function makeWithKey(string $base64Key): SecretEncryption
    {
        return $this->makeWithConfig($base64Key, '');
    }

    public function testEncryptDecryptRoundtrip(): void
    {
        $svc = $this->makeWithKey(base64_encode(random_bytes(32)));
        $plain = 'JBSWY3DPEHPK3PXP'; // typický base32 TOTP secret
        $cipher = $svc->encrypt($plain);
        self::assertNotSame($plain, $cipher);
        self::assertStringStartsWith('enc:v1:', $cipher);
        self::assertSame($plain, $svc->decrypt($cipher));
    }

    public function testIsEncrypted(): void
    {
        $svc = $this->makeWithKey(base64_encode(random_bytes(32)));
        self::assertFalse($svc->isEncrypted('plain-text-secret'));
        self::assertTrue($svc->isEncrypted($svc->encrypt('plain-text-secret')));
        self::assertTrue($svc->isEncrypted($svc->encryptFor('plain-text-secret', 'epo:test')));
    }

    public function testContextBoundEncryptionRoundtrip(): void
    {
        $svc = $this->makeWithKey(base64_encode(random_bytes(32)));
        $cipher = $svc->encryptFor('sensitive-payload', 'epo:submitted-payload');

        self::assertStringStartsWith('enc:v2:', $cipher);
        self::assertSame(
            'sensitive-payload',
            $svc->decryptFor($cipher, 'epo:submitted-payload'),
        );
    }

    public function testContextBoundEncryptionRejectsWrongContext(): void
    {
        $svc = $this->makeWithKey(base64_encode(random_bytes(32)));
        $cipher = $svc->encryptFor('sensitive-payload', 'epo:submitted-payload');

        $this->expectException(\RuntimeException::class);
        $svc->decryptFor($cipher, 'epo:confirmation');
    }

    public function testPreviousKeyDecryptsV1AndV2DuringRotation(): void
    {
        $oldKey = base64_encode(random_bytes(32));
        $newKey = base64_encode(random_bytes(32));
        $old = $this->makeWithKey($oldKey);
        $rotated = $this->makeWithConfig($newKey, '', [$oldKey]);

        self::assertSame(
            'legacy-secret',
            $rotated->decrypt($old->encrypt('legacy-secret')),
        );
        self::assertSame(
            'context-secret',
            $rotated->decryptFor(
                $old->encryptFor('context-secret', 'epo:credential-pfx'),
                'epo:credential-pfx',
            ),
        );
        self::assertNotSame(
            explode(':', $old->encryptFor('old', 'epo:test'), 4)[2],
            explode(':', $rotated->encryptFor('new', 'epo:test'), 4)[2],
        );
    }

    public function testLegacyPlaintextPassesThrough(): void
    {
        $svc = $this->makeWithKey(base64_encode(random_bytes(32)));
        // Legacy entries (před šifrováním) jsou bez prefixu — decrypt je vrátí beze změny
        self::assertSame('legacy-plain', $svc->decrypt('legacy-plain'));
    }

    public function testTwoEncryptionsOfSameInputDiffer(): void
    {
        // Random nonce → každý encrypt je jiný blob (důležité pro chosen-plaintext security)
        $svc = $this->makeWithKey(base64_encode(random_bytes(32)));
        $a = $svc->encrypt('same-input');
        $b = $svc->encrypt('same-input');
        self::assertNotSame($a, $b);
        self::assertSame('same-input', $svc->decrypt($a));
        self::assertSame('same-input', $svc->decrypt($b));
    }

    public function testInvalidBase64KeyThrows(): void
    {
        $svc = $this->makeWithKey('not-valid-base64-32-bytes!!!');
        $this->expectException(\RuntimeException::class);
        $svc->encrypt('x');
    }

    public function testTamperingDetected(): void
    {
        $svc = $this->makeWithKey(base64_encode(random_bytes(32)));
        $cipher = $svc->encrypt('original');
        // Změním poslední znak ciphertextu → GCM tag se nesedí → exception
        $tampered = substr($cipher, 0, -1) . (substr($cipher, -1) === 'A' ? 'B' : 'A');
        $this->expectException(\RuntimeException::class);
        $svc->decrypt($tampered);
    }

    public function testValidateKeyWarnsWhenSecretKeyMissing(): void
    {
        $svc = $this->makeWithConfig('', 'pepper-for-hkdf-fallback');
        self::assertSame(
            'secret_encryption_key chybí, používá se HKDF fallback z app.pepper (méně bezpečné).',
            $svc->validateKey(),
        );
    }

    public function testValidateKeyRejectsInvalidBase64(): void
    {
        $svc = $this->makeWithKey('not-valid-base64-32-bytes!!!');
        self::assertSame('cfg.app.secret_encryption_key musí být base64 klíč o délce 32B po dekódování.', $svc->validateKey());
    }

    public function testValidateKeyRejectsWrongLength(): void
    {
        // 24B je častý omyl (AES-192), ale naše AES-256-GCM implementace vyžaduje 32B key.
        $svc = $this->makeWithKey(base64_encode(random_bytes(24)));
        self::assertSame('cfg.app.secret_encryption_key musí být base64 klíč o délce 32B po dekódování.', $svc->validateKey());
    }

    public function testValidateKeyAcceptsValid32ByteKey(): void
    {
        $svc = $this->makeWithKey(base64_encode(random_bytes(32)));
        self::assertNull($svc->validateKey());
    }
}
