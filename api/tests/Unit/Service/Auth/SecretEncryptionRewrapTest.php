<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use PHPUnit\Framework\TestCase;

/** Přebalení kontextově šifrované hodnoty na aktuální klíč (rotace klíče). */
final class SecretEncryptionRewrapTest extends TestCase
{
    public function testRewrapMovesValueToCurrentKeyAndKeepsContextBinding(): void
    {
        $oldKey = base64_encode(random_bytes(32));
        $newKey = base64_encode(random_bytes(32));
        $old = $this->make($oldKey);
        $rotated = $this->make($newKey, [$oldKey]);
        $stored = $old->encryptFor('rodne cislo', 'payroll:1:2:personal_identifier');

        $rewrapped = $rotated->rewrapFor($stored, 'payroll:1:2:personal_identifier');

        self::assertNotNull($rewrapped);
        self::assertSame($rotated->currentKeyId(), $rotated->keyIdOf($rewrapped));
        self::assertNotSame($old->currentKeyId(), $rotated->keyIdOf($rewrapped));
        self::assertSame(
            'rodne cislo',
            $this->make($newKey)->decryptFor($rewrapped, 'payroll:1:2:personal_identifier'),
        );
    }

    public function testValueUnderCurrentKeyIsLeftAlone(): void
    {
        $svc = $this->make(base64_encode(random_bytes(32)));

        self::assertNull($svc->rewrapFor($svc->encryptFor('x', 'ctx'), 'ctx'));
    }

    /** Jiný kontext se nesmí „přebalit" - AES-GCM ho odmítne dešifrovat. */
    public function testWrongContextFailsInsteadOfRewrapping(): void
    {
        $oldKey = base64_encode(random_bytes(32));
        $stored = $this->make($oldKey)->encryptFor('x', 'payroll:1:2:bank_account');

        $this->expectException(\RuntimeException::class);
        $this->make(base64_encode(random_bytes(32)), [$oldKey])->rewrapFor($stored, 'payroll:1:3:bank_account');
    }

    public function testMissingOldKeyFails(): void
    {
        $stored = $this->make(base64_encode(random_bytes(32)))->encryptFor('x', 'ctx');

        $this->expectException(\RuntimeException::class);
        $this->make(base64_encode(random_bytes(32)))->rewrapFor($stored, 'ctx');
    }

    public function testPlaceholdersAndLegacyFormatsAreNotRewrapped(): void
    {
        $svc = $this->make(base64_encode(random_bytes(32)));

        self::assertNull($svc->keyIdOf('enc:v2:pending'));
        self::assertNull($svc->keyIdOf('pending:v1'));
        self::assertNull($svc->keyIdOf($svc->encrypt('v1')));
        $this->expectException(\RuntimeException::class);
        $svc->rewrapFor('enc:v2:pending', 'ctx');
    }

    public function testHasKeyIdKnowsCurrentAndPreviousKeysOnly(): void
    {
        $oldKey = base64_encode(random_bytes(32));
        $old = $this->make($oldKey);
        $rotated = $this->make(base64_encode(random_bytes(32)), [$oldKey]);

        self::assertTrue($rotated->hasKeyId($rotated->currentKeyId()));
        self::assertTrue($rotated->hasKeyId($old->currentKeyId()));
        self::assertFalse($rotated->hasKeyId('0000000000000000'));
    }

    /** @param list<string> $previous */
    private function make(string $key, array $previous = []): SecretEncryption
    {
        $config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($config, 'data'))->setValue($config, [
            'app' => [
                'secret_encryption_key' => $key,
                'secret_encryption_previous_keys' => $previous,
                'pepper' => '',
            ],
        ]);

        return new SecretEncryption($config);
    }
}
