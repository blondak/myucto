<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Client\UpdateClientAction;
use MyInvoice\Action\Project\UpdateProjectAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Issue #113 — PUT klienta a zakázky: klíč, který v těle chybí, zachová uloženou
 * hodnotu; klíč poslaný jako null / "" ji smaže.
 */
#[Group('integration')]
final class ClientProjectPartialUpdateTest extends StockTestCase
{
    private const CLIENT_COLUMNS = [
        'company_name', 'first_name', 'last_name', 'ic', 'dic', 'tax_number', 'street', 'city', 'zip',
        'country_id', 'main_email', 'phone', 'language', 'currency_default_id', 'reverse_charge',
        'auto_send_reminders', 'payment_due_default', 'payment_due_unit', 'default_payment_method',
        'hourly_rate', 'note', 'invoice_number_format', 'proforma_number_format', 'credit_note_number_format',
        'invoice_number_period', 'related_party', 'related_party_type', 'related_party_note',
    ];

    private const PROJECT_COLUMNS = [
        'name', 'payment_due_days', 'payment_due_unit', 'project_number', 'contract_number',
        'budget_total', 'budget_yearly', 'budget_monthly', 'hourly_rate', 'currency_id', 'status',
        'requires_work_report_approval', 'note', 'billing_emails_mode',
    ];

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            foreach ($this->supplierIds as $sid) {
                $this->db->pdo()->prepare(
                    'DELETE p FROM projects p JOIN clients c ON c.id = p.client_id WHERE c.supplier_id = ?'
                )->execute([$sid]);
            }
        }
        parent::tearDown();
    }

    public function testClientPartialPutKeepsOmittedFields(): void
    {
        [$sid, $clientId] = $this->seededClient();
        $before = $this->clientRow($clientId);

        $res = $this->updateClient($sid, $clientId, ['phone' => '+420 777 000 111']);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $after = $this->clientRow($clientId);
        self::assertSame('+420 777 000 111', $after['phone']);
        unset($before['phone'], $after['phone']);
        self::assertSame($before, $after, 'Vynechané sloupce karty musí zůstat beze změny.');
    }

    public function testClientExplicitNullClears(): void
    {
        [$sid, $clientId] = $this->seededClient();
        $before = $this->clientRow($clientId);

        $res = $this->updateClient($sid, $clientId, [
            'ic' => null, 'note' => '', 'payment_due_default' => null, 'payment_due_unit' => null,
            'invoice_number_format' => null, 'related_party_note' => '',
        ]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $after = $this->clientRow($clientId);
        foreach (['ic', 'note', 'payment_due_default', 'payment_due_unit', 'invoice_number_format', 'related_party_note'] as $col) {
            self::assertNull($after[$col], $col);
            unset($before[$col], $after[$col]);
        }
        self::assertSame($before, $after);
    }

    public function testClientRelatedPartyTypeNullClearsWhileFlagStays(): void
    {
        [$sid, $clientId] = $this->seededClient();
        $before = $this->clientRow($clientId);

        $res = $this->updateClient($sid, $clientId, ['related_party_type' => null]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $after = $this->clientRow($clientId);
        self::assertNull($after['related_party_type']);
        self::assertSame(1, (int) $after['related_party']);
        unset($before['related_party_type'], $after['related_party_type']);
        self::assertSame($before, $after);
    }

    public function testClientCurrencyCodeOnlyOverridesStoredCurrency(): void
    {
        [$sid, $clientId] = $this->seededClient();
        $before = $this->clientRow($clientId);

        $res = $this->updateClient($sid, $clientId, ['currency_default' => 'CZK']);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $after = $this->clientRow($clientId);
        self::assertNotSame($before['currency_default_id'], $after['currency_default_id']);
        self::assertSame($this->currencyIdFor($sid), (int) $after['currency_default_id']);
        unset($before['currency_default_id'], $after['currency_default_id']);
        self::assertSame($before, $after);
    }

    public function testClientPartialPutStillValidatesPresentKeys(): void
    {
        [$sid, $clientId] = $this->seededClient();

        $res = $this->updateClient($sid, $clientId, ['company_name' => '']);

        self::assertSame(400, $res->getStatusCode());
        self::assertSame(['company_name'], array_keys($this->body($res)['error']['fields']));
    }

    public function testProjectPartialPutKeepsOmittedFieldsAndBillingEmails(): void
    {
        [$sid, $projectId] = $this->seededProject();
        $before = $this->projectRow($projectId);

        $res = $this->updateProject($sid, $projectId, ['name' => 'Přejmenovaná zakázka']);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $after = $this->projectRow($projectId);
        self::assertSame('Přejmenovaná zakázka', $after['name']);
        unset($before['name'], $after['name']);
        self::assertSame($before, $after, 'Vynechané sloupce zakázky i fakturační e-maily musí zůstat.');
    }

    public function testProjectExplicitNullAndEmptyListClear(): void
    {
        [$sid, $projectId] = $this->seededProject();
        $before = $this->projectRow($projectId);

        $res = $this->updateProject($sid, $projectId, [
            'budget_total' => null, 'contract_number' => '', 'note' => null, 'billing_emails' => [],
        ]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $after = $this->projectRow($projectId);
        foreach (['budget_total', 'contract_number', 'note'] as $col) {
            self::assertNull($after[$col], $col);
            unset($before[$col], $after[$col]);
        }
        self::assertSame([], $after['billing_emails']);
        unset($before['billing_emails'], $after['billing_emails']);
        self::assertSame($before, $after);
    }

    public function testProjectCurrencyCodeOnlyAndNullBillingEmails(): void
    {
        [$sid, $projectId] = $this->seededProject();
        $before = $this->projectRow($projectId);

        $res = $this->updateProject($sid, $projectId, ['currency' => 'CZK', 'billing_emails' => null]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $after = $this->projectRow($projectId);
        self::assertNotSame($before['currency_id'], $after['currency_id']);
        self::assertSame($this->currencyIdFor($sid), (int) $after['currency_id']);
        self::assertSame([], $after['billing_emails']);
        unset($before['currency_id'], $after['currency_id'], $before['billing_emails'], $after['billing_emails']);
        self::assertSame($before, $after);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** @return array{int,int} */
    private function seededClient(): array
    {
        $sid = $this->createSupplier();
        $clientId = $this->client($sid, 'Partial klient');
        $pdo = $this->db->pdo();
        $sk = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'SK' LIMIT 1")->fetchColumn() ?: $this->czId);
        $eur = $this->eur($sid);
        $tpl = 'PU' . substr(md5(uniqid('', true)), 0, 6);
        $pdo->prepare(
            "UPDATE clients SET first_name = 'Jan', last_name = 'Testovací', ic = '12345678', dic = 'SK1234567890',
                    tax_number = '1234567890', country_id = ?, phone = '+420 111', language = 'en',
                    currency_default_id = ?, reverse_charge = 1, auto_send_reminders = 0,
                    payment_due_default = 21, payment_due_unit = 'days', default_payment_method = 'cash',
                    hourly_rate = 950, note = 'Uložená poznámka',
                    invoice_number_format = ?, proforma_number_format = ?, credit_note_number_format = ?,
                    invoice_number_period = 'month', related_party = 1, related_party_type = 'capital',
                    related_party_note = 'podíl 60 %'
              WHERE id = ?"
        )->execute([$sk, $eur, $tpl . 'F{YYYY}{CCCC}', $tpl . 'Z{YYYY}{CCCC}', $tpl . 'D{YYYY}{CCCC}', $clientId]);

        return [$sid, $clientId];
    }

    /** @return array{int,int} */
    private function seededProject(): array
    {
        $sid = $this->createSupplier();
        $clientId = $this->client($sid, 'Klient zakázky');
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO projects (client_id, name, payment_due_days, payment_due_unit, project_number, contract_number,
                                   budget_total, budget_yearly, budget_monthly, hourly_rate, currency_id, status,
                                   requires_work_report_approval, note, billing_emails_mode)
             VALUES (?, 'Zakázka', 21, 'days', 'Z-001', 'SML-1', 100000, 50000, 5000, 800, ?, 'paused', 1, 'Poznámka', 'replace')"
        )->execute([$clientId, $this->eur($sid)]);
        $projectId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO project_billing_emails (project_id, position, email, label, usages)
             VALUES (?, 1, 'fakturace@example.test', 'Účtárna', NULL)"
        )->execute([$projectId]);

        return [$sid, $projectId];
    }

    private function eur(int $supplierId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "EUR", "Euro", "€", "Euro", "Euro", 2, 1, 0)'
        )->execute([$supplierId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function clientRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . implode(', ', self::CLIENT_COLUMNS) . ' FROM clients WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed> */
    private function projectRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . implode(', ', self::PROJECT_COLUMNS) . ' FROM projects WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $emails = $this->db->pdo()->prepare('SELECT position, email, label, usages FROM project_billing_emails WHERE project_id = ? ORDER BY position');
        $emails->execute([$id]);
        $row['billing_emails'] = $emails->fetchAll(\PDO::FETCH_ASSOC);
        return $row;
    }

    /** @param array<string,mixed> $body */
    private function updateClient(int $supplierId, int $id, array $body): ResponseInterface
    {
        $action = $this->container->get(UpdateClientAction::class);
        return $action($this->request($supplierId, $body), new Psr7Response(), ['id' => (string) $id]);
    }

    /** @param array<string,mixed> $body */
    private function updateProject(int $supplierId, int $id, array $body): ResponseInterface
    {
        $action = $this->container->get(UpdateProjectAction::class);
        return $action($this->request($supplierId, $body), new Psr7Response(), ['id' => (string) $id]);
    }

    /** @param array<string,mixed> $body */
    private function request(int $supplierId, array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
    }

    /** @return array<mixed> */
    private function body(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
