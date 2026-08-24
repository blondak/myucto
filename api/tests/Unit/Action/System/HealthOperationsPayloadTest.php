<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\System;

use MyInvoice\Action\System\HealthAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Infrastructure\Cache\RedisProbe;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\SessionLockPolicy;
use MyInvoice\Service\System\AppUrlConfiguration;
use MyInvoice\Service\System\EnvironmentCheckService;
use MyInvoice\Service\System\InstanceHealthProbe;
use MyInvoice\Service\System\MaintenanceLock;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use MyInvoice\Service\Tenant\TenantDomainFeature;
use MyInvoice\Service\Update\VersionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * H-09 + H-29 bod 1 — co musí `/api/health` hlásit provozovateli.
 *
 * Bez fleet API je health jediný kanál, kterým se dozvíme, co na flotile běží,
 * a zároveň jediný způsob, jak provozovatel pozná, že je bezpečné nasazovat.
 * Testy proto drží TVAR odpovědi (klíč, který zmizí, je pro monitoring stejná
 * regrese jako špatná hodnota) a mez toho, co smí ven bez autentizace.
 */
#[AllowMockObjectsWithoutExpectations]
final class HealthOperationsPayloadTest extends TestCase
{
    private string $dir;
    private string $lockFile;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/myucto-health-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
        $this->lockFile = $this->dir . '/maintenance.lock';
    }

    protected function tearDown(): void
    {
        @unlink($this->lockFile);
        @rmdir($this->dir);
    }

    /** @param array<string,mixed> $overrides */
    private function config(array $overrides = []): Config
    {
        return new Config(array_replace_recursive([
            'app' => [
                'url' => 'https://app.example.test',
                'managed' => true,
                'managed_provider' => 'servermaster',
            ],
            'domains' => ['enabled' => true],
            'maintenance' => ['lock_file' => $this->lockFile],
        ], $overrides));
    }

    /** @param array<string,mixed> $overrides */
    private function action(array $overrides = []): HealthAction
    {
        $config = $this->config($overrides);
        // Nedostupná databáze je záměr: health musí odpovědět i tehdy — právě
        // tehdy je nejvíc potřeba — a neznámé hodnoty degradovat na null.
        $db = $this->createStub(Connection::class);
        $db->method('ping')->willReturn(false);
        $db->method('hasTable')->willReturn(false);
        $db->method('pdo')->willThrowException(new \RuntimeException('db unavailable'));
        $redis = $this->createStub(RedisProbe::class);
        $redis->method('isAvailable')->willReturn(false);
        $version = $this->createStub(VersionService::class);
        $version->method('getCurrentVersion')->willReturn('9.9.9');
        $appUrl = new AppUrlConfiguration($config, new HostnameNormalizer(), new NullLogger());

        return new HealthAction(
            $db,
            $redis,
            $this->createStub(SecretEncryption::class),
            $version,
            $this->createStub(PasskeyService::class),
            $this->createStub(MfaPolicyService::class),
            new SessionLockPolicy(new Config([])),
            $appUrl,
            new InstanceHealthProbe(
                $db,
                $config,
                new MaintenanceLock($config),
                $appUrl,
                new TenantDomainFeature($config),
                new EnvironmentCheckService($db, $config, $redis, $version, $appUrl),
            ),
        );
    }

    /** @param array<string,mixed> $overrides */
    private function call(string $host = 'app.example.test', array $overrides = [], bool $signedIn = false): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://' . $host . '/api/health');
        if ($signedIn) {
            $request = $request->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1]);
        }
        $response = ($this->action($overrides))($request, (new ResponseFactory())->createResponse());

        return (array) json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testPayloadCarriesEveryOperationalKey(): void
    {
        $body = $this->call();

        foreach (['status', 'version', 'db', 'redis', 'time', 'maintenance', 'jobs', 'cron', 'backup', 'migrations', 'configuration'] as $key) {
            self::assertArrayHasKey($key, $body, "Health musí vždy nést klíč '{$key}'.");
        }
        self::assertSame(['running', 'blocking'], array_keys($body['jobs']));
        self::assertSame(
            ['mode', 'dispatcher_age_sec', 'dispatcher_fresh', 'dispatcher_status', 'last_tick_age_sec'],
            array_keys($body['cron']),
        );
        self::assertSame(['age_sec', 'fresh'], array_keys($body['backup']));
        self::assertSame(['applied', 'pending', 'up_to_date'], array_keys($body['migrations']));
    }

    /**
     * Bez použitelné databáze musí health pořád odpovědět — právě tehdy je
     * nejvíc potřeba. Neznámá hodnota je `null`, nikdy nula.
     */
    public function testUnknownValuesDegradeToNullNotZero(): void
    {
        $body = $this->call();

        self::assertNull($body['jobs']['running']);
        self::assertNull($body['cron']['dispatcher_age_sec']);
        self::assertNull($body['backup']['age_sec']);
        self::assertNull($body['migrations']['pending']);
    }

    public function testMaintenanceFlagFollowsTheLockFile(): void
    {
        self::assertFalse($this->call()['maintenance']);

        touch($this->lockFile);
        self::assertTrue($this->call()['maintenance']);
        self::assertSame(
            'ok',
            $this->call()['status'],
            '`status` zůstává ok — instance odpovídá; údržbu nese samostatný příznak.',
        );

        unlink($this->lockFile);
        self::assertFalse($this->call()['maintenance']);
    }

    /**
     * ⚠️ KDO instalaci hostuje, se anonymnímu volajícímu neříká.
     *
     * `/api/health` je veřejný a záměrně odpovídá i na neznámé doméně, takže
     * by stačilo projet `*.myucto.online` a mít celý dodavatelský řetězec
     * i seznam instancí. Že instalace JE spravovaná, veřejné zůstává —
     * neprozrazuje nikoho a zákazník podle toho pozná, že si konfiguraci
     * nemá přenastavovat.
     */
    public function testManagedProviderIsHiddenFromAnonymousCallers(): void
    {
        $configuration = $this->call()['configuration'];

        self::assertTrue($configuration['managed'], 'Že je spravovaná, se říct smí.');
        self::assertArrayNotHasKey('managed_provider', $configuration, 'Kdo ji spravuje, ne.');
    }

    public function testManagedProviderIsReportedToSignedInUsers(): void
    {
        $configuration = $this->call('app.example.test', [], true)['configuration'];

        self::assertTrue($configuration['managed']);
        self::assertSame('servermaster', $configuration['managed_provider']);
    }

    public function testUnsetManagedProviderIsNullNotEmptyString(): void
    {
        $configuration = $this->call('app.example.test', [
            'app' => ['managed' => false, 'managed_provider' => ''],
        ], true)['configuration'];

        self::assertFalse($configuration['managed']);
        self::assertNull($configuration['managed_provider']);
    }

    /**
     * H-29 bod 1. Prázdné `app.url` host gate TIŠE VYPNE, chybné ho zamkne —
     * obojí je tiché selhání, takže „nastavené?" a „sedí s hostem?" musí být
     * samostatné, čitelné údaje.
     */
    public function testEmptyAppUrlIsReportedAsUnconfiguredAndUnenforcedGate(): void
    {
        $configuration = $this->call('whatever.example.test', ['app' => ['url' => '']])['configuration'];

        self::assertFalse($configuration['app_url_configured']);
        self::assertNull($configuration['app_url_matches_host']);
        self::assertFalse(
            $configuration['host_gate_enforced'],
            'Prázdné app.url gate vypne — health to musí říct nahlas.',
        );
    }

    public function testHostMatchIsReportedForCanonicalAndForeignHost(): void
    {
        $matching = $this->call('app.example.test')['configuration'];
        self::assertTrue($matching['app_url_configured']);
        self::assertTrue($matching['app_url_matches_host']);
        self::assertTrue($matching['host_gate_enforced']);

        $foreign = $this->call('monitoring.example.test')['configuration'];
        self::assertFalse($foreign['app_url_matches_host']);
    }

    public function testHostGateIsNotEnforcedWithoutTheDomainsFeature(): void
    {
        $configuration = $this->call('app.example.test', ['domains' => ['enabled' => false]])['configuration'];

        self::assertFalse($configuration['host_gate_enforced']);
    }

    /** Veřejný endpoint nesmí prozradit hostname ani cestu k zámku. */
    public function testAnonymousPayloadLeaksNeitherHostnameNorPaths(): void
    {
        touch($this->lockFile);
        $response = ($this->action())(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://app.example.test/api/health'),
            (new ResponseFactory())->createResponse(),
        );
        $raw = (string) $response->getBody();

        self::assertArrayNotHasKey('warnings', (array) json_decode($raw, true));
        foreach (['app.example.test', $this->lockFile, 'maintenance.lock'] as $secret) {
            self::assertStringNotContainsString($secret, $raw);
        }
    }

    /** Bez probe (a tedy bez DB) musí být tvar odpovědi pořád kompletní. */
    public function testShapeSurvivesAMissingProbe(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('ping')->willReturn(false);
        $redis = $this->createStub(RedisProbe::class);
        $redis->method('isAvailable')->willReturn(false);
        $version = $this->createStub(VersionService::class);
        $version->method('getCurrentVersion')->willReturn('9.9.9');
        $config = $this->config();

        $action = new HealthAction(
            $db,
            $redis,
            $this->createStub(SecretEncryption::class),
            $version,
            $this->createStub(PasskeyService::class),
            $this->createStub(MfaPolicyService::class),
            new SessionLockPolicy(new Config([])),
            new AppUrlConfiguration($config, new HostnameNormalizer(), new NullLogger()),
        );

        $body = (array) json_decode(
            (string) ($action(
                (new ServerRequestFactory())->createServerRequest('GET', 'https://app.example.test/api/health'),
                (new ResponseFactory())->createResponse(),
            ))->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(InstanceHealthProbe::unavailableSummary()['cron'], $body['cron']);
        self::assertFalse($body['maintenance']);
        self::assertFalse($body['configuration']['host_gate_enforced']);
    }
}
