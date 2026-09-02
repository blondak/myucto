<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use DateTimeImmutable;
use DateTimeZone;
use MyInvoice\Service\Backup\Company\CompanyBackupJobRetentionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupJobRetentionPolicyTest extends TestCase
{
    public function testUsesShortDefaultRetention(): void
    {
        $policy = CompanyBackupJobRetentionPolicy::defaults();

        self::assertSame(24, $policy->hours);
        self::assertSame(
            '2026-09-03T10:15:30+00:00',
            $policy->expiresAt(
                new DateTimeImmutable('2026-09-02T10:15:30+00:00'),
            )->format(DATE_ATOM),
        );
    }

    public function testRetentionIsElapsedTimeAcrossDaylightSavingChange(): void
    {
        $zone = new DateTimeZone('Europe/Prague');
        $completedAt = new DateTimeImmutable('2026-03-28 12:00:00', $zone);
        $expiresAt = (new CompanyBackupJobRetentionPolicy(24))->expiresAt($completedAt);

        self::assertSame(24 * 3600, $expiresAt->getTimestamp() - $completedAt->getTimestamp());
        self::assertSame('2026-03-29T13:00:00+02:00', $expiresAt->format(DATE_ATOM));
        self::assertSame($zone->getName(), $expiresAt->getTimezone()->getName());
    }

    #[DataProvider('invalidRetentionHours')]
    public function testRejectsRetentionOutsideShortBoundedWindow(int $hours): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Retence zálohy firmy');

        new CompanyBackupJobRetentionPolicy($hours);
    }

    /** @return iterable<string,array{int}> */
    public static function invalidRetentionHours(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'more than seven days' => [169];
    }
}
