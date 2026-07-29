<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Infrastructure\Cache\RedisFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Epic F6 — admin správa uživatelů s rolí client (§8.4 specu):
 *   - create/update s role='client' projde (UserAdminAction rozšířený výčet rolí)
 *   - UserSupplierAdminAction: non-NULL role override pro client uživatele → 400,
 *     membership klienta se zakládá s role = NULL
 *   - create bez role → vznikne 'readonly' (API default i DB DEFAULT — změna M3)
 */
#[Group('integration')]
final class UserAdminTest extends TestCase
{
    private Connection $db;
    private Config $config;
    private SessionManager $sessions;
    private ?App $app = null;

    private int $supplierA = 0;

    /** @var list<int> */
    private array $userIds = [];
    /** @var list<string> */
    private array $sessionTokens = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $this->config = Config::load($rootDir);
            $this->db = new Connection($this->config);
            $redis = new RedisFactory($this->config);
            $this->sessions = new SessionManager($this->db, $this->config, new DatabaseSecurityClock());
            $this->db->pdo()->query('SELECT 1');
        } catch (\Exception $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }

        if ($this->db->pdo()->query("SHOW TABLES LIKE 'roles'")->fetchColumn() === false) {
            $this->markTestSkipped('Dynamické role chybí — spusť api/bin/migrate.php.');
        }

        $this->supplierA = (int) $this->db->pdo()->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($this->supplierA <= 0) {
            $this->markTestSkipped('Žádný supplier v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();

        foreach ($this->sessionTokens as $token) {
            try {
                $this->sessions->destroy($token);
            } catch (\Throwable) {
                // best-effort úklid
            }
        }

        if ($this->userIds !== []) {
            $place = implode(',', array_fill(0, count($this->userIds), '?'));
            $pdo->prepare("DELETE FROM activity_log WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM activity_log WHERE entity_type = 'user' AND entity_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM user_suppliers WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM users WHERE id IN ($place)")->execute($this->userIds);
        }

        $this->userIds = $this->sessionTokens = [];
        $this->db->close();
        $this->app = null;
    }

    // ---------------------------------------------------------------- tests

    public function testCreateAndUpdateClientUser(): void
    {
        $admin = $this->mkUser('admin');
        $session = $this->mkSession($admin);

        $email = $this->mkEmail();
        $res = $this->sessionRequest('POST', '/api/admin/users', $session, [
            'email'    => $email,
            'name'     => '__TEST F6 client',
            'role_id'  => $this->roleId('client'),
            'locale'   => 'cs',
            'password' => 'SuperTajneHeslo123',
        ]);
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $body = $this->json($res);
        self::assertSame('client', $body['role']['type'] ?? null);
        $id = (int) ($body['id'] ?? 0);
        self::assertGreaterThan(0, $id);
        $this->userIds[] = $id;

        // Update client → accountant → client (obě strany výčtu)
        $res = $this->sessionRequest('PUT', '/api/admin/users/' . $id, $session, ['role_id' => $this->roleId('accountant')]);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('staff', $this->json($res)['role']['type'] ?? null);

        $res = $this->sessionRequest('PUT', '/api/admin/users/' . $id, $session, ['role_id' => $this->roleId('client')]);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('client', $this->json($res)['role']['type'] ?? null);

        // Neplatná role dál padá
        $res = $this->sessionRequest('PUT', '/api/admin/users/' . $id, $session, ['role_id' => 999999999]);
        self::assertSame(400, $res->getStatusCode());
        self::assertSame('validation_failed', $this->json($res)['error']['code'] ?? null);
    }

    public function testSupplierRoleOverrideForClientUserRejected(): void
    {
        $admin = $this->mkUser('admin');
        $session = $this->mkSession($admin);
        $client = $this->mkUser('client');

        // non-NULL role v assignments pro client uživatele → 400 validation_failed
        $res = $this->sessionRequest('PUT', '/api/admin/users/' . $client . '/suppliers', $session, [
            'assignments' => [['supplier_id' => $this->supplierA, 'role_id' => $this->roleId('accountant')]],
        ]);
        self::assertSame(400, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('role_type_mismatch', $this->json($res)['error']['code'] ?? null);

        // role NULL projde — membership klienta se zakládá se zděděnou (NULL) rolí
        $res = $this->sessionRequest('PUT', '/api/admin/users/' . $client . '/suppliers', $session, [
            'assignments' => [['supplier_id' => $this->supplierA, 'role_id' => null]],
        ]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $rows = $this->json($res);
        self::assertCount(1, $rows);
        self::assertSame($this->supplierA, (int) ($rows[0]['supplier_id'] ?? 0));
        self::assertArrayHasKey('role_id', $rows[0]);
        self::assertNull($rows[0]['role_id']);

        // Pro legacy roli override dál funguje (ROLES beze změny)
        $readonly = $this->mkUser('readonly');
        $res = $this->sessionRequest('PUT', '/api/admin/users/' . $readonly . '/suppliers', $session, [
            'assignments' => [['supplier_id' => $this->supplierA, 'role_id' => $this->roleId('accountant')]],
        ]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $rows = $this->json($res);
        self::assertSame($this->roleId('accountant'), $rows[0]['role_id'] ?? null);
    }

    public function testCreateWithoutRoleIsRejected(): void
    {
        $admin = $this->mkUser('admin');
        $session = $this->mkSession($admin);

        // API create bez role → 'readonly' (UserAdminAction posílá roli vždy explicitně, M3)
        $res = $this->sessionRequest('POST', '/api/admin/users', $session, [
            'email'    => $this->mkEmail(),
            'name'     => '__TEST F6 norole',
            'password' => 'SuperTajneHeslo123',
        ]);
        self::assertSame(400, $res->getStatusCode(), (string) $res->getBody());
    }

    // ------------------------------------------------------------- fixtures

    private function mkEmail(): string
    {
        return '__test_f6_admin_' . bin2hex(random_bytes(6)) . '@example.com';
    }

    private function mkUser(string $role): int
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
             VALUES (?, '\$2y\$10\$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', '__TEST F6', ?, 'cs', 1)"
        );
        $stmt->execute([$this->mkEmail(), $this->roleId($role)]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->userIds[] = $id;
        return $id;
    }

    private function roleId(string $legacy): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM roles WHERE system_key = ?');
        $stmt->execute([$legacy === 'admin' ? 'superadmin' : $legacy]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{token:string, csrf_token:string} */
    private function mkSession(int $userId): array
    {
        $out = $this->sessions->create($userId, '127.0.0.1', '__test_f6');
        $this->sessionTokens[] = (string) $out['token'];
        return ['token' => $out['token'], 'csrf_token' => $out['csrf_token']];
    }

    // -------------------------------------------------------------- helpers

    private function app(): App
    {
        return $this->app ??= Bootstrap::buildApp();
    }

    /** @param array{token:string, csrf_token:string} $session */
    private function sessionRequest(
        string $method,
        string $path,
        array $session,
        ?array $body = null,
    ): ResponseInterface {
        $cookieName = (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session');
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([$cookieName => $session['token']])
            ->withHeader('Accept', 'application/json')
            ->withHeader('Origin', $appUrl)
            ->withHeader('X-CSRF-Token', $session['csrf_token']);
        if ($body !== null) {
            $stream = (new StreamFactory())->createStream(
                json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            );
            $req = $req->withHeader('Content-Type', 'application/json')->withBody($stream);
        }
        return $this->app()->handle($req);
    }

    private function json(ResponseInterface $res): array
    {
        $decoded = json_decode((string) $res->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
