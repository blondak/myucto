<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Admin\Import\AiProviderCredentialsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Epic F7 §3.8/§13 — AI provider credentials (AiProviderCredentialsAction). Ověřuje
 * rozšířený FE kontrakt status() (models, eu_capable, default_model, residency_label)
 * a dedikovaný TestConnection endpoint (admin-only; non-admin 403; bez změny creds).
 *
 * Gemini klíč se pro tenanta v setUp vynuluje (a v tearDown obnoví), aby testConnection
 * nešel na síť (creds null → ok:false, error, žádný HTTP call). Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class AiProviderCredentialsActionTest extends TestCase
{
    private Connection $db;
    private AiProviderCredentialsAction $action;
    private int $supplierId = 0;
    private int $userId = 0;
    private ?string $savedGeminiKey = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db     = $c->get(Connection::class);
            $this->action = $c->get(AiProviderCredentialsAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT MIN(id) FROM users')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/user.');
        }
        // Vynuluj gemini klíč (testConnection pak nejde na síť); ulož pro obnovu.
        $stmt = $pdo->prepare('SELECT gemini_api_key_enc FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplierId]);
        $val = $stmt->fetchColumn();
        $this->savedGeminiKey = $val === false || $val === null ? null : (string) $val;
        $pdo->prepare('UPDATE supplier SET gemini_api_key_enc = NULL WHERE id = ?')->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->supplierId > 0) {
            $this->db->pdo()->prepare('UPDATE supplier SET gemini_api_key_enc = ? WHERE id = ?')
                ->execute([$this->savedGeminiKey, $this->supplierId]);
            $this->db->close();
        }
    }

    public function testStatusHasFeContractFields(): void
    {
        $res = $this->call('status', 'GET', 'admin');
        self::assertSame(200, $res['status']);
        $providers = $res['body']['providers'] ?? [];
        foreach (['anthropic', 'azure_openai', 'openai', 'gemini'] as $p) {
            self::assertArrayHasKey($p, $providers, "provider $p chybí");
            $info = $providers[$p];
            self::assertArrayHasKey('models', $info, "$p.models");
            self::assertIsArray($info['models']);
            self::assertArrayHasKey('default_model', $info);
            self::assertArrayHasKey('data_region', $info);
            self::assertArrayHasKey('eu_capable', $info);
            self::assertIsBool($info['eu_capable']);
            self::assertArrayHasKey('residency_label', $info);
            self::assertArrayHasKey('configured', $info);
            self::assertArrayHasKey('extractions_count', $info);
        }
        // eu_capable matrix: anthropic/gemini = false, openai/azure = true.
        self::assertFalse($providers['anthropic']['eu_capable']);
        self::assertFalse($providers['gemini']['eu_capable']);
        self::assertTrue($providers['openai']['eu_capable']);
        self::assertTrue($providers['azure_openai']['eu_capable']);
    }

    public function testTestRouteAdminOnly(): void
    {
        // Non-admin → 403.
        foreach (['accountant', 'readonly'] as $role) {
            $denied = $this->call('test', 'POST', $role, ['provider' => 'gemini']);
            self::assertSame(403, $denied['status'], "$role musí dostat 403");
        }

        // Admin → 200 + kontrakt {test_ok, test_error, model}; gemini bez creds → ok:false (bez sítě).
        $ok = $this->call('test', 'POST', 'admin', ['provider' => 'gemini']);
        self::assertSame(200, $ok['status']);
        self::assertArrayHasKey('test_ok', $ok['body']);
        self::assertArrayHasKey('test_error', $ok['body']);
        self::assertArrayHasKey('model', $ok['body']);
        self::assertFalse($ok['body']['test_ok'], 'gemini bez klíče → test_ok false');
    }

    public function testTestRouteRejectsBadProvider(): void
    {
        $res = $this->call('test', 'POST', 'admin', ['provider' => 'bogus']);
        self::assertSame(400, $res['status']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, string $http, string $role, array $body = []): array
    {
        $req = $this->req($http, $role);
        if ($body !== []) $req = $req->withParsedBody($body);
        $resp = $this->action->{$method}($req, new Psr7Response());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function req(string $http, string $role): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($http, '/api/admin/imports/ai/credentials')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
    }
}
