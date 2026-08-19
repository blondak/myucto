<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use MyInvoice\Infrastructure\Cache\RedisProbe;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\System\AppUrlConfiguration;
use MyInvoice\Service\System\EnvironmentCheckService;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use MyInvoice\Service\Update\VersionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Preflight je veřejný na neinicializované instalaci — musí proto vracet jen
 * verdikt vybraných kontrol, nikdy naměřená fakta o stroji.
 */
#[AllowMockObjectsWithoutExpectations]
final class EnvironmentCheckPreflightTest extends TestCase
{
    private function service(
        VersionService&\PHPUnit\Framework\MockObject\MockObject $version,
        ?Config $config = null,
        ?AppUrlConfiguration $appUrl = null,
    ): EnvironmentCheckService {
        // DB záměrně nedostupná: guard() musí degradovat, ne shodit diagnostiku.
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willThrowException(new \PDOException('connection refused'));
        $db->method('hasTable')->willThrowException(new \PDOException('connection refused'));

        $redis = $this->createMock(RedisProbe::class);
        $redis->method('isAvailable')->willReturn(false);

        $config ??= new Config([]);

        return new EnvironmentCheckService(
            $db,
            $config,
            $redis,
            $version,
            $appUrl ?? new AppUrlConfiguration($config, new HostnameNormalizer(), new NullLogger()),
        );
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

    public function testMissingAndBlankAppUrlAreNonBlockingOnlyDuringFirstSetup(): void
    {
        $version = $this->createMock(VersionService::class);
        $version->method('detectEnvironment')->willReturn('native');

        foreach ([new Config([]), new Config(['app' => ['url' => " \t\r\n "]])] as $config) {
            $service = $this->service($version, $config);
            $preflight = array_column($service->preflight()['checks'], null, 'id')['app_url'];
            $running = array_column($service->report(['app_url'])['checks'], null, 'id')['app_url'];

            self::assertSame(EnvironmentCheckService::STATUS_OK, $preflight['status']);
            self::assertSame(AppUrlConfiguration::REASON_MISSING, $preflight['actual']);
            self::assertSame('app_url_detected_during_setup', $preflight['info']);
            self::assertSame(EnvironmentCheckService::STATUS_FAIL, $running['status']);
            self::assertSame(AppUrlConfiguration::REASON_MISSING, $running['actual']);
        }
    }

    public function testNonEmptyMalformedAppUrlFailsFirstSetupPreflight(): void
    {
        $version = $this->createMock(VersionService::class);
        $version->method('detectEnvironment')->willReturn('native');
        $config = new Config(['app' => [
            'url' => 'https://preflight-user:preflight-password@example.test/app?token=secret',
        ]]);

        $report = $this->service($version, $config)->preflight();
        $check = array_column($report['checks'], null, 'id')['app_url'];

        self::assertSame(EnvironmentCheckService::STATUS_FAIL, $check['status']);
        self::assertSame(AppUrlConfiguration::REASON_INVALID_ORIGIN, $check['actual']);
        self::assertSame(AppUrlConfiguration::STATE_INVALID, $check['meta']['state']);
        $encoded = json_encode($check, JSON_THROW_ON_ERROR);
        foreach (['preflight-user', 'preflight-password', 'token', 'secret', 'example.test'] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
    }

    public function testLanIpRemainsRoutingCompatibleButWarnsAboutWebAuthn(): void
    {
        $version = $this->createMock(VersionService::class);
        $version->method('detectEnvironment')->willReturn('native');
        $config = new Config(['app' => ['url' => 'http://192.168.10.20:8080']]);

        $check = array_column(
            $this->service($version, $config)->preflight()['checks'],
            null,
            'id',
        )['app_url'];

        self::assertSame(EnvironmentCheckService::STATUS_WARN, $check['status']);
        self::assertSame(AppUrlConfiguration::REASON_WEBAUTHN_INCOMPATIBLE, $check['actual']);
        self::assertTrue($check['meta']['routing_compatible']);
        self::assertFalse($check['meta']['webauthn_compatible']);
    }

    public function testCanonicalHostnameConflictFailsEnvironmentCheck(): void
    {
        $version = $this->createMock(VersionService::class);
        $version->method('detectEnvironment')->willReturn('native');
        $config = new Config(['app' => ['url' => 'https://portal.example.test']]);
        $appUrl = new AppUrlConfiguration($config, new HostnameNormalizer(), new NullLogger());
        $appUrl->hostnameConflictStatus();

        $check = array_column(
            $this->service($version, $config, $appUrl)->preflight()['checks'],
            null,
            'id',
        )['app_url'];

        self::assertSame(EnvironmentCheckService::STATUS_FAIL, $check['status']);
        self::assertSame(AppUrlConfiguration::REASON_HOSTNAME_CONFLICT, $check['actual']);
        self::assertSame(AppUrlConfiguration::STATE_HOSTNAME_CONFLICT, $check['meta']['state']);
    }
}
