<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Action\TaxEvidence\CashJournalAction;
use MyInvoice\Action\TaxEvidence\ReceivablesPayablesAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Integrační testy REST API daňové evidence (Epic DE, A3) — vzor ReportsApiTest.
 * GET vrací tvar odpovědi (deník+warnings / aging+kpis), exporty streamují bajty se
 * správným MIME + attachment hlavičkou, špatné datum/format → 422, čtecí role
 * (readonly/účetní/admin) projdou (RBAC klienta řeší RoleMiddleware — viz Unit test).
 *
 * Reuse throwaway tax_evidence supplier + rollbackovaná tx z CashJournalTestCase.
 */
#[Group('integration')]
final class TaxEvidenceApiTest extends CashJournalTestCase
{
    private CashJournalAction $cashJournalAction;
    private ReceivablesPayablesAction $receivablesAction;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setVatPayer($this->supplierId, true);
        $this->cashJournalAction = $this->container->get(CashJournalAction::class);
        $this->receivablesAction = $this->container->get(ReceivablesPayablesAction::class);
    }

    // ── GET tvary ────────────────────────────────────────────────────────────

    public function testCashJournalGetReturnsJournalAndWarnings(): void
    {
        $res = $this->call($this->cashJournalAction, 'get', 'readonly', ['year' => (string) self::YEAR]);
        self::assertSame(200, $res['status']);
        foreach (['opening_balance', 'closing_balance', 'rows', 'totals', 'checks', 'warnings'] as $k) {
            self::assertArrayHasKey($k, $res['body'], "chybí klíč {$k}");
        }
        self::assertIsArray($res['body']['rows']);
        self::assertIsArray($res['body']['warnings']);
    }

    public function testReceivablesPayablesGetReturnsAgingAndKpis(): void
    {
        $res = $this->call($this->receivablesAction, 'get', 'readonly');
        self::assertSame(200, $res['status']);
        foreach (['receivables', 'payables', 'kpis'] as $k) {
            self::assertArrayHasKey($k, $res['body']);
        }
        self::assertArrayHasKey('dso', $res['body']['kpis']);
    }

    // ── validace ─────────────────────────────────────────────────────────────

    public function testBadDateReturns422(): void
    {
        $res = $this->call($this->cashJournalAction, 'get', 'readonly', ['from' => 'nope', 'to' => self::YEAR . '-12-31']);
        self::assertSame(422, $res['status']);
        self::assertSame('validation_failed', $res['body']['error']['code']);
    }

    public function testBadFormatReturns422OnBothExports(): void
    {
        foreach ([
            [$this->cashJournalAction, ['year' => (string) self::YEAR, 'format' => 'csv']],
            [$this->receivablesAction, ['format' => 'csv']],
        ] as [$action, $query]) {
            $res = $this->call($action, 'export', 'readonly', $query);
            self::assertSame(422, $res['status'], $action::class . ' csv → 422');
            self::assertSame('validation_failed', $res['body']['error']['code']);
        }
    }

    // ── exporty (MIME + attachment, non-empty bytes) ──────────────────────────

    public function testCashJournalExportPdfXlsx(): void
    {
        $pdf = $this->raw($this->cashJournalAction, 'export', 'readonly', ['year' => (string) self::YEAR, 'format' => 'pdf']);
        self::assertSame(200, $pdf->getStatusCode());
        self::assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $pdf->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('penezni-denik', $pdf->getHeaderLine('Content-Disposition'));
        self::assertSame('private, no-store', $pdf->getHeaderLine('Cache-Control'));
        $pdf->getBody()->rewind();
        self::assertStringStartsWith('%PDF', (string) $pdf->getBody());

        $xlsx = $this->raw($this->cashJournalAction, 'export', 'readonly', ['year' => (string) self::YEAR, 'format' => 'xlsx']);
        self::assertSame(200, $xlsx->getStatusCode());
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsx->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $xlsx->getHeaderLine('Content-Disposition'));
        self::assertGreaterThan(0, (int) $xlsx->getHeaderLine('Content-Length'));
    }

    public function testReceivablesExportPdfXlsx(): void
    {
        $pdf = $this->raw($this->receivablesAction, 'export', 'readonly', ['format' => 'pdf']);
        self::assertSame(200, $pdf->getStatusCode());
        self::assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        $pdf->getBody()->rewind();
        self::assertStringStartsWith('%PDF', (string) $pdf->getBody());

        $xlsx = $this->raw($this->receivablesAction, 'export', 'readonly', ['format' => 'xlsx']);
        self::assertSame(200, $xlsx->getStatusCode());
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsx->getHeaderLine('Content-Type'));
        self::assertStringContainsString('pohledavky-zavazky', $xlsx->getHeaderLine('Content-Disposition'));
    }

    // ── čtecí role projdou na Action vrstvě (client blokuje RoleMiddleware) ────

    public function testReadRolesPassThroughActionLayer(): void
    {
        foreach (['readonly', 'accountant', 'admin'] as $role) {
            $get = $this->call($this->cashJournalAction, 'get', $role, ['year' => (string) self::YEAR]);
            self::assertSame(200, $get['status'], "{$role} GET cash-journal");
            $exp = $this->raw($this->receivablesAction, 'export', $role, ['format' => 'xlsx']);
            self::assertSame(200, $exp->getStatusCode(), "{$role} export receivables");
        }
    }

    public function testHistoricalTaxEvidenceYearRemainsAccessibleAfterModeSwitch(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_accounting_modes (supplier_id, effective_from, accounting_mode)
             VALUES (?, ?, ?), (?, ?, ?)
             ON DUPLICATE KEY UPDATE accounting_mode = VALUES(accounting_mode)'
        )->execute([
            $this->supplierId, self::YEAR . '-01-01', 'tax_evidence',
            $this->supplierId, (self::YEAR + 1) . '-01-01', 'double_entry',
        ]);
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);

        $result = $this->call($this->cashJournalAction, 'get', 'readonly', ['year' => (string) self::YEAR]);
        self::assertSame(200, $result['status']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string,string> $query */
    private function raw(object $action, string $method, string $role, array $query = []): ResponseInterface
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/tax-evidence')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
        return $action->{$method}($req, new Psr7Response());
    }

    /**
     * @param array<string,string> $query
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(object $action, string $method, string $role, array $query = []): array
    {
        $resp = $this->raw($action, $method, $role, $query);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
