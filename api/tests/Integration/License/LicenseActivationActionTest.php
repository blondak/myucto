<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\License;

use MyInvoice\Action\License\ActivateLicenseAction;
use MyInvoice\Action\License\DeactivateLicenseAction;
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
 * Aktivace / deaktivace licence (E4) — ActivateLicenseAction / DeactivateLicenseAction
 * proti mockovanému licenčnímu serveru. Ověřuje happy path (uložení klíče+tokenu),
 * chybové odpovědi serveru, síťovou chybu, neplatný podpis a smazání klíče při
 * deaktivaci (i při nedostupném serveru).
 */
#[Group('integration')]
final class LicenseActivationActionTest extends TestCase
{
    use LicenseTokenTrait;

    private Connection $db;
    private LicenseClient&MockObject $client;
    private LicenseService $service;
    private ActivateLicenseAction $activate;
    private DeactivateLicenseAction $deactivate;
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
        $this->activate   = new ActivateLicenseAction($this->service);
        $this->deactivate = new DeactivateLicenseAction($this->service);

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

    // ── aktivace ─────────────────────────────────────────────────────────────

    public function testActivateHappyPathStoresKeyAndToken(): void
    {
        $token = $this->token();
        $this->client->expects($this->once())->method('activate')->willReturn(['ok' => true, 'token' => $token]);

        $resp = $this->activate->__invoke($this->adminRequest(['license_key' => 'MYU-TEST-0001-AAAA']), new Psr7Response());

        self::assertSame(200, $resp->getStatusCode());
        $body = $this->body($resp);
        self::assertSame('active', $body['state']);

        $row = $this->row();
        self::assertSame('MYU-TEST-0001-AAAA', $row['license_key']);
        self::assertSame($token, $row['token']);
        self::assertSame(1, (int) $row['last_check_ok']);
    }

    public function testActivateServerRejectionReturns422(): void
    {
        $this->client->expects($this->once())->method('activate')->willReturn(['ok' => false, 'error' => 'already_bound']);

        $resp = $this->activate->__invoke($this->adminRequest(['license_key' => 'MYU-TEST-0001-AAAA']), new Psr7Response());

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('already_bound', $this->body($resp)['error']['code']);
        self::assertNull($this->row()['license_key'], 'Při odmítnutí se klíč neuloží.');
    }

    public function testActivateNetworkErrorReturns503(): void
    {
        $this->client->expects($this->once())->method('activate')->willThrowException(new LicenseNetworkException('down'));

        $resp = $this->activate->__invoke($this->adminRequest(['license_key' => 'MYU-TEST-0001-AAAA']), new Psr7Response());

        self::assertSame(503, $resp->getStatusCode());
        self::assertSame('server_unreachable', $this->body($resp)['error']['code']);
    }

    public function testActivateBadSignatureReturns422(): void
    {
        // Server vrátí token podepsaný cizím klíčem → verifikace selže.
        $bad = $this->signLicenseToken(
            ['iid' => $this->instanceId, 'valid_until' => time() + 86400, 'status' => 'ok'],
            $this->foreignSecretKey(),
        );
        $this->client->expects($this->once())->method('activate')->willReturn(['ok' => true, 'token' => $bad]);

        $resp = $this->activate->__invoke($this->adminRequest(['license_key' => 'MYU-TEST-0001-AAAA']), new Psr7Response());

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('invalid_token', $this->body($resp)['error']['code']);
        self::assertNull($this->row()['license_key']);
    }

    public function testActivateTakeoverPassesFlagToClient(): void
    {
        $token = $this->token();
        $this->client->expects($this->once())
            ->method('activate')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), true)
            ->willReturn(['ok' => true, 'token' => $token]);

        $resp = $this->activate->__invoke(
            $this->adminRequest(['license_key' => 'MYU-TEST-0001-AAAA', 'takeover' => true]),
            new Psr7Response(),
        );

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('active', $this->body($resp)['state']);
    }

    public function testActivateDefaultsTakeoverToFalse(): void
    {
        $this->client->expects($this->once())
            ->method('activate')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), false)
            ->willReturn(['ok' => true, 'token' => $this->token()]);

        $resp = $this->activate->__invoke($this->adminRequest(['license_key' => 'MYU-TEST-0001-AAAA']), new Psr7Response());

        self::assertSame(200, $resp->getStatusCode());
    }

    public function testActivateAlreadyBoundPropagatesTransfersRemaining(): void
    {
        $this->client->expects($this->once())
            ->method('activate')
            ->willReturn(['ok' => false, 'error' => 'already_bound', 'transfers_remaining' => 1]);

        $resp = $this->activate->__invoke($this->adminRequest(['license_key' => 'MYU-TEST-0001-AAAA']), new Psr7Response());

        self::assertSame(422, $resp->getStatusCode());
        $error = $this->body($resp)['error'];
        self::assertSame('already_bound', $error['code']);
        self::assertSame(1, $error['transfers_remaining']);
    }

    public function testActivateTransferLimitReturns422(): void
    {
        $this->client->expects($this->once())
            ->method('activate')
            ->willReturn(['ok' => false, 'error' => 'transfer_limit']);

        $resp = $this->activate->__invoke(
            $this->adminRequest(['license_key' => 'MYU-TEST-0001-AAAA', 'takeover' => true]),
            new Psr7Response(),
        );

        self::assertSame(422, $resp->getStatusCode());
        self::assertSame('transfer_limit', $this->body($resp)['error']['code']);
        self::assertNull($this->row()['license_key']);
    }

    public function testActivateEmptyKeyReturns400(): void
    {
        $this->client->expects($this->never())->method('activate');

        $resp = $this->activate->__invoke($this->adminRequest(['license_key' => '   ']), new Psr7Response());

        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('validation_failed', $this->body($resp)['error']['code']);
    }

    public function testActivateForbiddenForNonSuperadmin(): void
    {
        $this->client->expects($this->never())->method('activate');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/license/activate')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 2, 'role' => 'accountant'])
            ->withParsedBody(['license_key' => 'MYU-TEST-0001-AAAA']);

        $resp = $this->activate->__invoke($request, new Psr7Response());

        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('forbidden', $this->body($resp)['error']['code']);
    }

    // ── deaktivace ───────────────────────────────────────────────────────────

    public function testDeactivateClearsKeyAndReportsTransfers(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())->method('deactivate')->willReturn(['ok' => true, 'transfers_remaining' => 2]);

        $resp = $this->deactivate->__invoke($this->adminRequest([]), new Psr7Response());

        self::assertSame(200, $resp->getStatusCode());
        $body = $this->body($resp);
        self::assertSame(2, $body['transfers_remaining']);

        $row = $this->row();
        self::assertNull($row['license_key']);
        self::assertNull($row['token']);
    }

    public function testDeactivateClearsLocalEvenWhenServerUnreachable(): void
    {
        $this->seedActivated();
        $this->client->expects($this->once())->method('deactivate')->willThrowException(new LicenseNetworkException('down'));

        $resp = $this->deactivate->__invoke($this->adminRequest([]), new Psr7Response());

        self::assertSame(200, $resp->getStatusCode());
        self::assertNull($this->body($resp)['transfers_remaining']);

        $row = $this->row();
        self::assertNull($row['license_key'], 'Lokální klíč se smaže i při nedostupném serveru.');
        self::assertNull($row['token']);
    }

    public function testDeactivateForbiddenForNonSuperadmin(): void
    {
        $this->client->expects($this->never())->method('deactivate');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/license/deactivate')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 2, 'role' => 'accountant']);

        $resp = $this->deactivate->__invoke($request, new Psr7Response());

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
            ->createServerRequest('POST', '/api/license/activate')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin'])
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function body(Psr7Response $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        return (array) $this->db->pdo()->query('SELECT * FROM license WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
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
