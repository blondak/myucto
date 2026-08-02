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

        // Smysl testu je „VersionService leze z kontejneru, ne z `new`". Jestli
        // kontejner vznikl přes buildContainer() (CLI cesta) nebo buildApp()
        // (web cesta) je pro tuhle záruku jedno — obě dávají tentýž kontejner.
        self::assertTrue(
            str_contains($source, 'Bootstrap::buildContainer()')
            || str_contains($source, 'Bootstrap::buildApp()->getContainer()'),
            'cron-version-check musí brát kontejner z Bootstrapu.',
        );
        self::assertStringContainsString(
            '$container->get(VersionService::class)',
            $source,
        );
        self::assertStringNotContainsString('new VersionService(', $source);
    }
}
