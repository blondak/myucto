<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveWriteException;
use MyInvoice\Service\Backup\Company\CompanyBackupPasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupPasswordPolicyTest extends TestCase
{
    public function testAcceptsPortablePasswordWithinBounds(): void
    {
        CompanyBackupPasswordPolicy::assertValid('synthetic-password-42');
        CompanyBackupPasswordPolicy::assertValid(str_repeat('x', 1_024));

        self::addToAssertionCount(2);
    }

    #[DataProvider('invalidPasswords')]
    public function testRejectsPasswordThatArchiveWriterCannotUse(string $password): void
    {
        try {
            CompanyBackupPasswordPolicy::assertValid($password);
            self::fail('Neplatné heslo zálohy nesmí projít společnou politikou.');
        } catch (CompanyBackupArchiveWriteException $e) {
            self::assertSame('archive_password_weak', $e->errorCode);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function invalidPasswords(): iterable
    {
        yield 'empty' => [''];
        yield 'eleven bytes' => [str_repeat('x', 11)];
        yield 'too long' => [str_repeat('x', 1_025)];
        yield 'nul byte' => ["synthetic\0password"];
    }
}
