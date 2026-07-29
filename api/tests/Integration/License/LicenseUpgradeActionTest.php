<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\License;

use MyInvoice\Action\License\UpgradeLicenseAction;
use MyInvoice\Action\License\UpgradeQuoteLicenseAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseNetworkException;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Tests\Support\LicenseTokenTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * In-place navýšení počtu uživatelů (E4) — UpgradeQuoteLicenseAction /
 * UpgradeLicenseAction proti mockovanému licenčnímu serveru. Ověřuje kalkulaci
 * doplatku, provedení navýšení (vč. vynucené obnovy tokenu s vyšším limitem)
 * a chybové stavy (no_parent_payment, charge_failed, síťová chyba, chybějící klíč).
 */
#[Group('integration')]
final class LicenseUpgradeActionTest extends TestCase
{
    use LicenseTokenTrait;

    private Connection $db;
    private LicenseClient&MockObject $client;
    private LicenseService $service;
    private UpgradeQuoteLicenseAction $quote;
    private UpgradeLicenseAction $upgrade;
    private string $instanceId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(Bootstrap::rootDir() . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->db = Bootstrap::buildApp()->getContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->ping() || !$this->db->hasTable('license')) {
            $this->markTestSkipped('Migrace 1139 (license) neproběhla / DB nedostupná.');
        }

        $this->client  = $this->createMock(LicenseClient::class);
        $this->service = new LicenseService(
            $this->db,
            new Config(['license' => ['public_key' => $this->licensePublicKeyBase64()]]),
            new LicenseTokenVerifier(),
            $this->client,
        );
        $this->quote   = new UpgradeQuoteLicenseAction($this->service);
        $this->upgrade = new UpgradeLicenseAction($this->service);

        $pdo = $this->db->pdo();
        $this->instanceId = (string) $pdo->query('SELECT instance_id FROM license WHERE id = 1')->fetchColumn();
        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    // ── kalkulace doplatku ─────────────────────────────────────────────────────

    public function testQuoteHappyPathReturnsCalculation(): void
    {
        $this->seedActivated();
        $periodEnd = time() + 86400 * 20;
        $this->client->expects($this->once())
            ->method('upgradeQuote')
            ->with($this->anything(), 5)
            ->willReturn([
                'ok' => true, 'current_users' => 3, 'new_users' => 5,
                'amount' => 250, 'currency' => 'CZK', 'period_end' => $periodEnd,
            ]);

        $resp = $this->quote->__invoke($this->adminRequest(['users' => 5]), new Psr7Response());

        self::assertSame(200, $resp->getStatusCode());
        $body = $this->body($resp);
        self::assertSame(3, $body['current_users']);
        self::assertSame(5, $body['new_users']);
        self::assertSame(250, $body['amount']);
        self::assertSame('CZK', $body['currency']);
    }

    public function testQuoteNoParentPaymentReturns422(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())
            ->method('upgradeQuote')
            ->willReturn(['ok' => false, 'error' => 'no_parent_payment']);

        $resp = $this->quote->__invoke($this->adminRequest(['users' => 5]), new Psr7Response());

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('no_parent_payment', $this->body($resp)['error']['code']);
    }

    public function testQuoteWithoutLicenseKeyReturns422(): void
    {
        // Bez aktivního klíče se klient vůbec nevolá.
        $this->client->expects($this->never())->method('upgradeQuote');

        $resp = $this->quote->__invoke($this->adminRequest(['users' => 5]), new Psr7Response());

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('invalid_key', $this->body($resp)['error']['code']);
    }

    public function testQuoteInvalidUsersReturns400(): void
    {
        $this->client->expects($this->never())->method('upgradeQuote');

        $resp = $this->quote->__invoke($this->adminRequest(['users' => 0]), new Psr7Response());

        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('validation_failed', $this->body($resp)['error']['code']);
    }

    // ── provedení navýšení ─────────────────────────────────────────────────────

    public function testUpgradeHappyPathChargesAndRefreshesToken(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())
            ->method('upgrade')
            ->with($this->anything(), 5)
            ->willReturn(['ok' => true, 'new_users' => 5, 'amount_charged' => 250]);
        // Po úspěchu se vynutí obnova tokenu → přijde nový token s vyšším limitem.
        $this->client->expects($this->once())
            ->method('renew')
            ->willReturn(['ok' => true, 'token' => $this->token(['users' => 5])]);

        $resp = $this->upgrade->__invoke($this->adminRequest(['users' => 5]), new Psr7Response());

        self::assertSame(200, $resp->getStatusCode());
        $body = $this->body($resp);
        self::assertSame(5, $body['new_users']);
        self::assertSame(250, $body['amount_charged']);
        self::assertSame('active', $body['state']['state']);
        self::assertSame(5, $body['state']['users_licensed']);
    }

    public function testUpgradeChargeFailedReturns422(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())
            ->method('upgrade')
            ->willReturn(['ok' => false, 'error' => 'charge_failed']);
        $this->client->expects($this->never())->method('renew');

        $resp = $this->upgrade->__invoke($this->adminRequest(['users' => 5]), new Psr7Response());

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('charge_failed', $this->body($resp)['error']['code']);
    }

    public function testUpgradeNetworkErrorReturns503(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())
            ->method('upgrade')
            ->willThrowException(new LicenseNetworkException('down'));

        $resp = $this->upgrade->__invoke($this->adminRequest(['users' => 5]), new Psr7Response());

        self::assertSame(503, $resp->getStatusCode());
        self::assertSame('server_unreachable', $this->body($resp)['error']['code']);
    }

    public function testUpgradeWithoutLicenseKeyReturns422(): void
    {
        $this->client->expects($this->never())->method('upgrade');

        $resp = $this->upgrade->__invoke($this->adminRequest(['users' => 5]), new Psr7Response());

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('invalid_key', $this->body($resp)['error']['code']);
    }

    public function testUpgradeForbiddenForNonSuperadmin(): void
    {
        $this->client->expects($this->never())->method('upgrade');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/license/upgrade')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 2, 'role' => 'accountant'])
            ->withParsedBody(['users' => 5]);

        $resp = $this->upgrade->__invoke($request, new Psr7Response());

        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('forbidden', $this->body($resp)['error']['code']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seedActivated(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE license SET license_key = ?, token = ?, last_check_ok = 1 WHERE id = 1'
        )->execute(['MYU-TEST-0001-AAAA', $this->token()]);
    }

    /** @param array<string,mixed> $body */
    private function adminRequest(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/license/upgrade')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin'])
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function body(Psr7Response $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    /** @param array<string,mixed> $overrides */
    private function token(array $overrides = []): string
    {
        return $this->signLicenseToken(array_merge([
            'lic'           => 1,
            'iid'           => $this->instanceId,
            'tier'          => 'single',
            'users'         => 3,
            'max_companies' => 5,
            'valid_until'   => time() + 86400,
            'status'        => 'ok',
            'nonce'         => 'nonce-1',
        ], $overrides));
    }
}
