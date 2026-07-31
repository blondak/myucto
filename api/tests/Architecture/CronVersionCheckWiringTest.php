<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class CronVersionCheckWiringTest extends TestCase
{
    public function testVersionServiceComesFromContainer(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/bin/cron-version-check.php'
        );

        self::assertStringContainsString(
            'Bootstrap::buildApp()->getContainer()',
            $source,
        );
        self::assertStringContainsString(
            '$container->get(VersionService::class)',
            $source,
        );
        self::assertStringNotContainsString('new VersionService(', $source);
    }
}
