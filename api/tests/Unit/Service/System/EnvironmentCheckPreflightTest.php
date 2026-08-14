<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use MyInvoice\Infrastructure\Cache\RedisProbe;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\System\EnvironmentCheckService;
use MyInvoice\Service\Update\VersionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Preflight je veřejný na neinicializované instalaci — musí proto vracet jen
 * verdikt vybraných kontrol, nikdy naměřená fakta o stroji.
 */
#[AllowMockObjectsWithoutExpectations]
final class EnvironmentCheckPreflightTest extends TestCase
{
    private function service(VersionService&\PHPUnit\Framework\MockObject\MockObject $version): EnvironmentCheckService
    {
        // DB záměrně nedostupná: guard() musí degradovat, ne shodit diagnostiku.
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willThrowException(new \PDOException('connection refused'));
        $db->method('hasTable')->willThrowException(new \PDOException('connection refused'));

        $redis = $this->createMock(RedisProbe::class);
        $redis->method('isAvailable')->willReturn(false);

        return new EnvironmentCheckService($db, new Config([]), $redis, $version);
    }

    public function testPreflightReturnsOnlyPreflightChecksAndNoFacts(): void
    {
        $version = $this->createMock(VersionService::class);
        $version->method('detectEnvironment')->willReturn('native');

        $report = $this->service($version)->preflight();

        self::assertArrayNotHasKey('facts', $report);
        self::assertSame('native', $report['environment']);

        $ids = array_column($report['checks'], 'id');
        self::assertNotEmpty($ids);
        self::assertSame($ids, array_values(array_unique($ids)));
        foreach ($ids as $id) {
            self::assertContains($id, EnvironmentCheckService::PREFLIGHT_CHECKS, "kontrola {$id} nepatří do preflightu");
        }
        // Cron ani stav vydání na čerstvé instalaci nic neříkají.
        self::assertNotContains('cron_health', $ids);
        self::assertNotContains('app_version', $ids);
    }

    public function testPreflightNeverAsksForTheReleaseStatus(): void
    {
        $version = $this->createMock(VersionService::class);
        $version->method('detectEnvironment')->willReturn('native');
        $version->expects(self::never())->method('getStatus');

        $this->service($version)->preflight();
    }

    public function testPreflightPointsToTheDockerChapterInsideAContainer(): void
    {
        $version = $this->createMock(VersionService::class);
        $version->method('detectEnvironment')->willReturn('docker');

        $report = $this->service($version)->preflight();

        self::assertSame('docker', $report['environment']);
        $manuals = array_column($report['checks'], 'manual');
        self::assertNotContains('04_Instalace_Nativni', $manuals);
        self::assertContains('03_Instalace_Docker', $manuals);
    }

    public function testUnreachableDatabaseIsReportedAsAProblem(): void
    {
        $version = $this->createMock(VersionService::class);
        $version->method('detectEnvironment')->willReturn('native');

        $report = $this->service($version)->preflight();

        $byId = array_column($report['checks'], null, 'id');
        self::assertSame(EnvironmentCheckService::STATUS_FAIL, $byId['db_version']['status']);
        self::assertSame(EnvironmentCheckService::STATUS_FAIL, $report['summary']['status']);
    }
}
