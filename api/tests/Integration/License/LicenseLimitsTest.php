<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\License;

use MyInvoice\Action\Admin\UserAdminAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Tests\Support\LicenseTokenTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Licenční limity (E4) na úrovni Action tříd:
 *  - seat limit: UserAdminAction::create blokuje nového provozního uživatele nad
 *    počet míst; readonly/client role se nepočítají; trial je bez limitu.
 *  - max_companies: SettingsAction::createSupplier blokuje nad limit; null (unlimited)
 *    i trial projdou.
 *  - LicenseService::countActiveUsers() — počítací dotaz vč. JOIN na roles.
 */
#[Group('integration')]
final class LicenseLimitsTest extends TestCase
{
    use LicenseTokenTrait;

    private Connection $db;
    private LicenseService $service;
    private UserAdminAction $userAdmin;
    private SettingsAction $settings;
    private string $instanceId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(Bootstrap::rootDir() . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db  = $container->get(Connection::class);
            if (!$this->db->ping() || !$this->db->hasTable('license')) {
                $this->markTestSkipped('Migrace 1139 (license) neproběhla / DB nedostupná.');
            }
            // Vstříkni LicenseService s testovacím veřejným klíčem, ať jdou podepsat tokeny.
            $this->service = new LicenseService(
                $this->db,
                new Config(['license' => ['public_key' => $this->licensePublicKeyBase64()]]),
                new LicenseTokenVerifier(),
                $this->createStub(LicenseClient::class),
            );
            $container->set(LicenseService::class, $this->service);
            $this->userAdmin = $container->get(UserAdminAction::class);
            $this->settings  = $container->get(SettingsAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

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

    // ── countActiveUsers: JOIN na roles ──────────────────────────────────────

    public function testCountActiveUsersIgnoresReadonlyAndClientRoles(): void
    {
        $roles = $this->roleIds();
        $baseline = $this->service->countActiveUsers();

        // readonly (staff, system_key=readonly) i client se do licenčních míst nepočítají.
        $this->insertUser('readonly', $roles['readonly']);
        $this->insertUser('client', $roles['client']);
        self::assertSame($baseline, $this->service->countActiveUsers(), 'readonly/client role nezabírají místo.');

        // accountant (provozní staff role) místo zabírá.
        $this->insertUser('accountant', $roles['accountant']);
        self::assertSame($baseline + 1, $this->service->countActiveUsers(), 'accountant zabírá licenční místo.');

        // Deaktivovaný uživatel se nepočítá.
        $this->insertUser('accountant', $roles['accountant'], active: false);
        self::assertSame($baseline + 1, $this->service->countActiveUsers(), 'neaktivní uživatel se nepočítá.');
    }

    // ── seat limit přes UserAdminAction::create ─────────────────────────────

    public function testCreateUserBlockedOverSeatLimit(): void
    {
        $roles = $this->roleIds();
        // Token licencuje přesně tolik míst, kolik jich je obsazeno → žádné volné.
        $this->licenseWithToken($this->token(['users' => $this->service->countActiveUsers()]));

        $resp = $this->createUser($roles['accountant']);

        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('license_user_limit', $this->error($resp));
    }

    public function testReadonlyRoleNotSubjectToSeatLimit(): void
    {
        $roles = $this->roleIds();
        $this->licenseWithToken($this->token(['users' => $this->service->countActiveUsers()]));

        // readonly role licenční kontrolu přeskočí → propadne až na validaci hesla (400).
        $resp = $this->createUser($roles['readonly'], password: 'x');

        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('validation_failed', $this->error($resp));
    }

    public function testTrialHasNoSeatLimit(): void
    {
        $this->trialLicense();
        $roles = $this->roleIds();

        // Trial → bez limitu; provozní role projde licenční branou (padne až na hesle).
        $resp = $this->createUser($roles['accountant'], password: 'x');

        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('validation_failed', $this->error($resp));
    }

    // ── max_companies přes SettingsAction::createSupplier ────────────────────

    public function testCreateSupplierBlockedOverCompanyLimit(): void
    {
        $companies = $this->companyCount();
        $this->licenseWithToken($this->token(['max_companies' => $companies])); // obsazeno na doraz

        $resp = $this->createSupplier();

        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('license_company_limit', $this->error($resp));
    }

    public function testUnlimitedCompaniesPassLicenseGate(): void
    {
        $this->licenseWithToken($this->token(['max_companies' => null])); // null = neomezeno

        // Licenční brána projde → padne až na validaci povinných polí (prázdné tělo).
        $resp = $this->createSupplier();

        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('validation_failed', $this->error($resp));
    }

    public function testTrialHasNoCompanyLimit(): void
    {
        $this->trialLicense();

        $resp = $this->createSupplier();

        self::assertSame(400, $resp->getStatusCode());
        self::assertSame('validation_failed', $this->error($resp));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function createUser(int $roleId, string $password = 'Sup3rSecret!123'): Psr7Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/users')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'is_superadmin' => true])
            ->withParsedBody([
                'email'    => 'seat_' . uniqid('', true) . '@example.test',
                'name'     => 'Seat Test',
                'role_id'  => $roleId,
                'locale'   => 'cs',
                'password' => $password,
            ]);

        return $this->userAdmin->create($request, new Psr7Response());
    }

    private function createSupplier(): Psr7Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/suppliers')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin'])
            ->withParsedBody([]); // prázdné tělo — po licenční bráně spadne na validaci

        return $this->settings->createSupplier($request, new Psr7Response());
    }

    private function insertUser(string $legacyRole, int $roleId, bool $active = true): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO users (email, password_hash, name, role, role_id, locale, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'lic_' . uniqid('', true) . '@example.test',
            str_repeat('a', 60),
            'License Fixture',
            $legacyRole,
            $roleId,
            'cs',
            $active ? 1 : 0,
        ]);
    }

    /** @return array{accountant:int,readonly:int,client:int} */
    private function roleIds(): array
    {
        $pdo = $this->db->pdo();
        $ids = [];
        foreach (['accountant', 'readonly', 'client'] as $key) {
            $stmt = $pdo->prepare('SELECT id FROM roles WHERE system_key = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$key]);
            $id = (int) $stmt->fetchColumn();
            if ($id === 0) {
                $this->markTestSkipped("Systémová role '{$key}' v test DB chybí.");
            }
            $ids[$key] = $id;
        }
        /** @var array{accountant:int,readonly:int,client:int} $ids */
        return $ids;
    }

    private function companyCount(): int
    {
        return (int) $this->db->pdo()->query('SELECT COUNT(*) FROM supplier')->fetchColumn();
    }

    private function licenseWithToken(string $token): void
    {
        $this->db->pdo()->prepare(
            'UPDATE license SET license_key = ?, token = ?, token_payload = NULL WHERE id = 1'
        )->execute(['MYU-TEST-0001-AAAA', $token]);
    }

    private function trialLicense(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE license SET license_key = NULL, token = NULL, token_payload = NULL,
                    trial_started_at = NOW() WHERE id = 1'
        )->execute();
    }

    private function error(Psr7Response $response): ?string
    {
        $body = json_decode((string) $response->getBody(), true);
        return $body['error']['code'] ?? null;
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
