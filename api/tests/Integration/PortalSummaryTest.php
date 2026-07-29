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

/**
 * Epic F6 — klientský portál GET /api/portal/summary (§5 specu):
 *   - client s membershipem → 200 + kompletní tvar response (company/kpi/monthly/
 *     cashflow/vat)
 *   - bezpečnostní invariant: response neobsahuje žádná jména klientů/odběratelů
 *     ani čísla dokladů — jen agregáty, počty, částky
 *   - client bez membershipu → 403 (fail-closed resolver)
 *   - dostupné i staff rolím (accountant/readonly — náhled očima klienta)
 */
#[Group('integration')]
final class PortalSummaryTest extends TestCase
{
    /** Klíče, které se v portálové response nesmí NIKDY objevit (leak jmen/čísel dokladů). */
    private const FORBIDDEN_KEYS = [
        'company_name', 'client_name', 'vendor_name', 'client_id', 'vendor_id',
        'invoice_number', 'document_number', 'number', 'variable_symbol',
        'email', 'ic', 'dic', 'street', 'city',
    ];

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
            $pdo->prepare("DELETE FROM user_suppliers WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM users WHERE id IN ($place)")->execute($this->userIds);
        }

        $this->userIds = $this->sessionTokens = [];
        $this->db->close();
        $this->app = null;
    }

    // ---------------------------------------------------------------- tests

    public function testClientWithMembershipGetsAggregateOnlySummary(): void
    {
        $userId = $this->mkUser('client');
        $this->assign($userId, $this->supplierA);
        $session = $this->mkSession($userId);

        $res = $this->sessionRequest('GET', '/api/portal/summary', $session, $this->supplierA);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $body = $this->json($res);

        // Tvar response dle §5
        foreach (['company', 'kpi', 'monthly', 'cashflow', 'vat'] as $key) {
            self::assertArrayHasKey($key, $body);
        }
        self::assertIsString($body['company']['name'] ?? null);
        self::assertNotSame('', $body['company']['name']);
        self::assertArrayHasKey('period', $body['company']);

        foreach (['current_month', 'last_month', 'ytd', 'prev_year_ytd', 'last_12m', 'currencies'] as $key) {
            self::assertArrayHasKey($key, $body['kpi'], "kpi.$key chybí");
        }
        foreach ($body['kpi']['current_month'] as $row) {
            foreach (['currency', 'invoiced', 'costs', 'profit'] as $k) {
                self::assertArrayHasKey($k, $row);
            }
        }

        self::assertIsArray($body['monthly']);
        foreach (['receivables', 'payables', 'forecast'] as $key) {
            self::assertArrayHasKey($key, $body['cashflow']);
        }
        self::assertArrayHasKey('weeks', $body['cashflow']['forecast']);
        foreach (['is_vat_payer', 'status', 'deadlines'] as $key) {
            self::assertArrayHasKey($key, $body['vat']);
        }

        // Bezpečnostní invariant: žádná jména klientů/odběratelů ani čísla dokladů
        $offending = [];
        $walk = function (array $arr, string $prefix) use (&$walk, &$offending): void {
            foreach ($arr as $k => $v) {
                $key = $prefix . $k;
                if (is_string($k) && in_array($k, self::FORBIDDEN_KEYS, true)) {
                    $offending[] = $key;
                }
                if (is_array($v)) $walk($v, $key . '.');
            }
        };
        $walk($body, '');
        self::assertSame([], $offending,
            'Portal response nesmí obsahovat jména klientů ani čísla dokladů (jen agregáty)');
    }

    public function testClientWithoutMembershipDenied(): void
    {
        $userId = $this->mkUser('client');
        $session = $this->mkSession($userId);

        $res = $this->sessionRequest('GET', '/api/portal/summary', $session, $this->supplierA);
        self::assertSame(403, $res->getStatusCode(), 'client bez membershipu nesmí na portál (fail-closed)');

        $res = $this->sessionRequest('GET', '/api/portal/summary', $session, null);
        self::assertSame(403, $res->getStatusCode(), 'ani bez explicitního headeru (žádný fallback)');
    }

    public function testStaffRolesHavePortalAccess(): void
    {
        foreach (['accountant', 'readonly'] as $role) {
            $userId = $this->mkUser($role);
            $this->assign($userId, $this->supplierA);
            $session = $this->mkSession($userId);
            $res = $this->sessionRequest('GET', '/api/portal/summary', $session, $this->supplierA);
            self::assertSame(200, $res->getStatusCode(), "role $role musí mít náhled portálu: " . (string) $res->getBody());
            self::assertArrayHasKey('kpi', $this->json($res));
        }
    }

    // ------------------------------------------------------------- fixtures

    private function mkUser(string $role): int
    {
        $email = '__test_f6_portal_' . bin2hex(random_bytes(6)) . '@example.com';
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
             VALUES (?, '\$2y\$10\$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', '__TEST F6', ?, 'cs', 1)"
        );
        $stmt->execute([$email, $this->roleId($role)]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->userIds[] = $id;
        return $id;
    }

    private function assign(int $userId, int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, NULL)'
        )->execute([$userId, $supplierId]);
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
        ?int $supplierId,
    ): ResponseInterface {
        $cookieName = (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session');
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([$cookieName => $session['token']])
            ->withHeader('Accept', 'application/json')
            ->withHeader('Origin', $appUrl)
            ->withHeader('X-CSRF-Token', $session['csrf_token']);
        if ($supplierId !== null) {
            $req = $req->withHeader('X-Supplier-Id', (string) $supplierId);
        }
        return $this->app()->handle($req);
    }

    private function json(ResponseInterface $res): array
    {
        $decoded = json_decode((string) $res->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
