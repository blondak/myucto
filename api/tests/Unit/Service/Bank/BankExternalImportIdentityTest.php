<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Bank\BankExternalImportIdentity;
use PHPUnit\Framework\TestCase;

final class BankExternalImportIdentityTest extends TestCase
{
    public function testEmailIdentitySurvivesConfigurationRemapping(): void
    {
        self::assertSame(
            BankExternalImportIdentity::email('imap-7:<test@example.test>'),
            BankExternalImportIdentity::email('imap-901:<test@example.test>'),
        );
        self::assertNotSame(
            BankExternalImportIdentity::email('imap-7:<test@example.test>'),
            BankExternalImportIdentity::email('imap-7:<other@example.test>'),
        );
        $fallback = hash('sha256', 'synthetic message without message-id');
        self::assertSame('email:' . hash('sha256', $fallback), BankExternalImportIdentity::email('imap-9:' . $fallback));
    }

    public function testIdokladUsesExternalMovementOnly(): void
    {
        self::assertSame('idoklad:123', BankExternalImportIdentity::idoklad(123));
        $this->expectException(\InvalidArgumentException::class);
        BankExternalImportIdentity::idoklad(0);
    }
}
