<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Admin\BankRuleTemplateAdminAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Accounting\Bank\BankRuleTemplateValidator;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[Group('integration')]
final class BankRuleTemplateAdminActionTest extends TestCase
{
    private Connection $db;
    private BankRuleTemplateAdminAction $action;
    /** @var list<int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        if (!is_file($root . '/cfg.php')) $this->markTestSkipped('cfg.php missing');
        try {
            $this->db = new Connection(Config::load($root));
            $this->db->pdo()->query('SELECT 1');
        } catch (\Exception $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }
        if ($this->db->pdo()->query("SHOW TABLES LIKE 'bank_rule_templates'")->fetchColumn() === false) {
            $this->markTestSkipped('bank_rule_templates missing');
        }
        $this->action = new BankRuleTemplateAdminAction(
            $this->db,
            new BankRuleTemplateValidator(),
            new ActivityLogger($this->db),
            new IpMatcher(),
        );
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        foreach ($this->ids as $id) {
            $this->db->pdo()->prepare("DELETE FROM activity_log WHERE entity_type = 'bank_rule_template' AND entity_id = ?")->execute([$id]);
            $this->db->pdo()->prepare('DELETE FROM bank_rule_templates WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testSuperadminCanCreateUpdateListAndDeleteTemplate(): void
    {
        [$ruleKey, $direction] = $this->validPostingRule();
        $key = 'test.bank.' . bin2hex(random_bytes(5));
        $payload = [
            'template_key' => $key,
            'name_cs' => '__TEST bankovní šablona',
            'name_en' => '__TEST bank template',
            'direction' => $direction,
            'operation_type' => 'bank.rule.custom',
            'counterparty_bank' => '0100',
            'counterparty_prefix' => null,
            'vs_placeholder' => null,
            'message_contains' => 'Testovací platba',
            'rule_key' => $ruleKey,
            'default_priority' => 100,
            'sort_order' => 65000,
            'is_active' => true,
        ];

        $created = $this->action->create($this->request('POST', $payload), $this->response());
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $createdBody = $this->json($created);
        $id = (int) ($createdBody['id'] ?? 0);
        self::assertGreaterThan(0, $id);
        $this->ids[] = $id;

        $payload['name_cs'] = '__TEST upravená šablona';
        $payload['is_active'] = false;
        $updated = $this->action->update($this->request('PUT', $payload), $this->response(), ['id' => (string) $id]);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
        self::assertSame('__TEST upravená šablona', $this->json($updated)['name_cs'] ?? null);
        self::assertFalse($this->json($updated)['is_active'] ?? true);

        $listed = $this->action->list($this->request('GET'), $this->response());
        self::assertSame(200, $listed->getStatusCode());
        self::assertContains($key, array_column($this->json($listed)['templates'] ?? [], 'template_key'));

        $deleted = $this->action->delete($this->request('DELETE'), $this->response(), ['id' => (string) $id]);
        self::assertSame(200, $deleted->getStatusCode(), (string) $deleted->getBody());
        $this->ids = [];
    }

    public function testNonSuperadminIsRejected(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/admin/bank-rule-templates')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['role' => 'accountant']);
        $response = $this->action->list($request, $this->response());
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden_permission', $this->json($response)['error']['code'] ?? null);
    }

    /** @return array{string,string} */
    private function validPostingRule(): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT rule_key, debit_account_code, credit_account_code
               FROM posting_rules
              WHERE supplier_id IS NULL AND is_active = 1
                AND debit_account_code IS NOT NULL AND credit_account_code IS NOT NULL'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $debit = (string) $row['debit_account_code'];
            $credit = (string) $row['credit_account_code'];
            if (str_starts_with($debit, '221') && !$this->isSaldo($credit)) return [(string) $row['rule_key'], 'incoming'];
            if (str_starts_with($credit, '221') && !$this->isSaldo($debit)) return [(string) $row['rule_key'], 'outgoing'];
        }
        $this->markTestSkipped('No bank-compatible global posting rule.');
    }

    private function isSaldo(string $account): bool
    {
        foreach (['311', '321', '314', '324', '325'] as $prefix) {
            if (str_starts_with($account, $prefix)) return true;
        }
        return false;
    }

    /** @param array<string,mixed>|null $body */
    private function request(string $method, ?array $body = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/admin/bank-rule-templates', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['role' => 'admin']);
        return $body === null ? $request : $request->withParsedBody($body);
    }

    private function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
