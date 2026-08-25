<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\License;

use MyInvoice\Action\License\PurchaseCompleteAction;
use MyInvoice\Action\License\PurchaseStartAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Tests\Support\LicenseTokenTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class LicensePurchaseHandoffActionTest extends TestCase
{
    use LicenseTokenTrait;

    private Connection $db;
    private LicenseClient&MockObject $client;
    private LicenseService $service;
    private PurchaseStartAction $start;
    private PurchaseCompleteAction $complete;
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
        if (!$this->db->ping()
            || !$this->db->hasColumn('license', 'purchase_handoff_state_hash')
        ) {
            $this->markTestSkipped('Migrace 1529 neproběhla / DB není dostupná.');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->inTx = true;
        $this->instanceId = (string) $pdo->query('SELECT instance_id FROM license WHERE id = 1')->fetchColumn();
        $pdo->exec(
            'UPDATE license
                SET license_key = NULL, token = NULL, token_payload = NULL,
                    subscription_info = NULL, instance_info = NULL,
                    purchase_handoff_state_hash = NULL,
                    purchase_handoff_verifier = NULL,
                    purchase_handoff_expires_at = NULL,
                    trial_started_at = NOW(), last_check_ok = 1
              WHERE id = 1'
        );

        $this->client = $this->createMock(LicenseClient::class);
        $config = new Config([
            'app' => ['url' => 'https://app.example.test'],
            'license' => [
                'server_url' => 'https://shop.example.test',
                'public_key' => $this->licensePublicKeyBase64(),
            ],
        ]);
        $this->service = new LicenseService($this->db, $config, new LicenseTokenVerifier(), $this->client);
        $this->start = new PurchaseStartAction($this->service);
        $this->complete = new PurchaseCompleteAction($this->service);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testStartStoresServerOnlyPkceContextAndReturnsCheckoutUrl(): void
    {
        $seenState = '';
        $seenChallenge = '';
        $this->client->expects($this->once())
            ->method('purchaseSession')
            ->willReturnCallback(function (string $iid, string $state, string $challenge, string $returnUrl) use (&$seenState, &$seenChallenge): array {
                self::assertSame($this->instanceId, $iid);
                self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $state);
                self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $challenge);
                self::assertSame('https://app.example.test/activation/purchase', $returnUrl);
                $seenState = $state;
                $seenChallenge = $challenge;
                return [
                    'ok' => true,
                    'token' => str_repeat('a', 48),
                    'buy_url' => 'https://shop.example.test/objednavka?src=app&h=' . str_repeat('a', 48),
                    'expires_in' => 7200,
                ];
            });

        $response = $this->start->__invoke($this->adminRequest('/api/license/purchase/start'), new Response());

        self::assertSame(200, $response->getStatusCode());
        $body = $this->body($response);
        self::assertSame(7200, $body['expires_in']);
        self::assertArrayNotHasKey('token', $body);
        $row = $this->row();
        self::assertSame(hash('sha256', $seenState), $row['purchase_handoff_state_hash']);
        self::assertSame(
            $seenChallenge,
            $this->base64Url(hash('sha256', (string) $row['purchase_handoff_verifier'], true)),
        );
    }

    public function testStartRejectsExistingLiveLicenseWithoutCallingServer(): void
    {
        $this->seedLicense('MYU-OLD-0001-AAAA', $this->token());
        $this->client->expects($this->never())->method('purchaseSession');

        $response = $this->start->__invoke($this->adminRequest('/api/license/purchase/start'), new Response());

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('already_licensed', $this->body($response)['error']['code']);
    }

    public function testStartAllowsNewPaidSubscriptionFromLiveFreeTier(): void
    {
        $this->seedLicense('MYU-FREE-0001-AAAA', $this->token(['commercial' => false]));
        $this->client->expects($this->once())->method('purchaseSession')->willReturn([
            'ok' => true,
            'buy_url' => 'https://shop.example.test/objednavka?src=app&h=' . str_repeat('f', 48),
            'expires_in' => 7200,
        ]);

        $response = $this->start->__invoke($this->adminRequest('/api/license/purchase/start'), new Response());

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($this->row()['purchase_handoff_verifier']);
    }

    public function testStartRejectsCheckoutUrlOutsideConfiguredLicenseServer(): void
    {
        $this->client->expects($this->once())->method('purchaseSession')->willReturn([
            'ok' => true,
            'buy_url' => 'https://attacker.example/checkout',
            'expires_in' => 7200,
        ]);

        $response = $this->start->__invoke($this->adminRequest('/api/license/purchase/start'), new Response());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('purchase_failed', $this->body($response)['error']['code']);
        self::assertNull($this->row()['purchase_handoff_verifier']);
    }

    public function testCompleteClaimsAndActivatesWithoutReturningLicenseKey(): void
    {
        [$state, $verifier] = $this->seedHandoff();
        $orderToken = str_repeat('b', 32);
        $signedToken = $this->token();
        $this->client->expects($this->once())
            ->method('purchaseClaim')
            ->with(
                $orderToken,
                $verifier,
                $this->instanceId,
                $this->anything(),
                $this->anything(),
                $this->service->countActiveUsers(),
                $this->anything(),
            )
            ->willReturn([
                'ok' => true,
                'license_key' => 'MYU-NEW-0001-BBBB',
                'token' => $signedToken,
                'subscription' => ['state' => 'active', 'auto_renew' => true],
            ]);

        $response = $this->complete->__invoke(
            $this->adminRequest('/api/license/purchase/complete', ['purchase' => $orderToken, 'state' => $state]),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('MYU-NEW-0001-BBBB', (string) $response->getBody());
        $row = $this->row();
        self::assertSame('MYU-NEW-0001-BBBB', $row['license_key']);
        self::assertSame($signedToken, $row['token']);
        self::assertNull($row['purchase_handoff_state_hash']);
        self::assertNull($row['purchase_handoff_verifier']);
        self::assertSame('active', json_decode((string) $row['subscription_info'], true)['state']);
    }

    public function testCompleteRejectsForeignInstanceBeforeOverwritingExistingLicense(): void
    {
        $oldToken = $this->token();
        $this->seedLicense('MYU-OLD-0001-AAAA', $oldToken);
        [$state] = $this->seedHandoff();
        $this->client->expects($this->once())->method('purchaseClaim')->willReturn([
            'ok' => true,
            'license_key' => 'MYU-FOREIGN-0001-BBBB',
            'token' => $this->token(['iid' => '11111111-2222-3333-4444-555555555555']),
        ]);

        $response = $this->complete->__invoke(
            $this->adminRequest('/api/license/purchase/complete', [
                'purchase' => str_repeat('c', 32),
                'state' => $state,
            ]),
            new Response(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_token', $this->body($response)['error']['code']);
        $row = $this->row();
        self::assertSame('MYU-OLD-0001-AAAA', $row['license_key']);
        self::assertSame($oldToken, $row['token']);
        self::assertNotNull($row['purchase_handoff_state_hash'], 'Kontext zůstává pro bezpečné opakování.');
    }

    public function testExpiredHandoffNeverCallsClaim(): void
    {
        [$state] = $this->seedHandoff('-1 minute');
        $this->client->expects($this->never())->method('purchaseClaim');

        $response = $this->complete->__invoke(
            $this->adminRequest('/api/license/purchase/complete', [
                'purchase' => str_repeat('d', 32),
                'state' => $state,
            ]),
            new Response(),
        );

        self::assertSame(410, $response->getStatusCode());
        self::assertSame('handoff_expired', $this->body($response)['error']['code']);
        self::assertNull($this->row()['purchase_handoff_verifier']);
    }

    public function testStateMismatchAndPendingPaymentPreserveServerOnlyContext(): void
    {
        [$state, $verifier] = $this->seedHandoff();
        $this->client->expects($this->once())->method('purchaseClaim')->willReturn([
            'ok' => false,
            'error' => 'payment_pending',
        ]);

        $mismatch = $this->complete->__invoke(
            $this->adminRequest('/api/license/purchase/complete', [
                'purchase' => str_repeat('e', 32),
                'state' => $this->base64Url(random_bytes(32)),
            ]),
            new Response(),
        );
        self::assertSame(422, $mismatch->getStatusCode());
        self::assertSame('invalid_handoff', $this->body($mismatch)['error']['code']);

        $pending = $this->complete->__invoke(
            $this->adminRequest('/api/license/purchase/complete', [
                'purchase' => str_repeat('e', 32),
                'state' => $state,
            ]),
            new Response(),
        );
        self::assertSame(409, $pending->getStatusCode());
        self::assertSame('payment_pending', $this->body($pending)['error']['code']);
        self::assertSame($verifier, $this->row()['purchase_handoff_verifier']);
    }

    public function testPurchaseActionsRequireBrowserSessionEvenForBearerSuperadmin(): void
    {
        $this->client->expects($this->never())->method('purchaseSession');
        $request = $this->adminRequest('/api/license/purchase/start')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer');

        $response = $this->start->__invoke($request, new Response());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('session_required', $this->body($response)['error']['code']);
    }

    public function testPurchaseActionsRequireSuperadmin(): void
    {
        $this->client->expects($this->never())->method('purchaseSession');
        $request = $this->adminRequest('/api/license/purchase/start')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 2, 'role' => 'accountant']);

        $response = $this->start->__invoke($request, new Response());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->body($response)['error']['code']);
    }

    /** @return array{string,string} */
    private function seedHandoff(string $expiry = '+10 minutes'): array
    {
        $state = $this->base64Url(random_bytes(32));
        $verifier = $this->base64Url(random_bytes(32));
        $stmt = $this->db->pdo()->prepare(
            'UPDATE license
                SET purchase_handoff_state_hash = ?, purchase_handoff_verifier = ?,
                    purchase_handoff_expires_at = ?
              WHERE id = 1'
        );
        $stmt->execute([
            hash('sha256', $state),
            $verifier,
            date('Y-m-d H:i:s', (int) strtotime($expiry)),
        ]);
        return [$state, $verifier];
    }

    private function seedLicense(string $key, string $token): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE license SET license_key = ?, token = ?, last_check_ok = 1 WHERE id = 1'
        );
        $stmt->execute([$key, $token]);
    }

    /** @param array<string,mixed> $body */
    private function adminRequest(string $path, array $body = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin'])
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        return (array) $this->db->pdo()->query('SELECT * FROM license WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    private function body(Response $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    /** @param array<string,mixed> $overrides */
    private function token(array $overrides = []): string
    {
        return $this->signLicenseToken(array_merge([
            'lic' => 1,
            'iid' => $this->instanceId,
            'tier' => 'single',
            'users' => 3,
            'max_companies' => 5,
            'valid_until' => time() + 86400,
            'status' => 'ok',
            'nonce' => 'purchase-nonce',
        ], $overrides));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
