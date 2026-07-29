<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Accounting\ChartOfAccountsAction;
use MyInvoice\Action\TaxEvidence\MovementClassificationAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Backend mode-gate (audit 2026-07, nález G6 — de-mode): zápisové Action třídy
 * v Accounting/* (accounting_mode='double_entry') a TaxEvidence/* (='tax_evidence')
 * musí odmítnout volání pro firmu ve špatném režimu 403 wrong_accounting_mode
 * (GuardsAccountingMode, stejný vzor jako GuardsStockEnabled). Bez gate by šlo
 * po round-tripu DE→PÚ→DE volat /api/accounting/* i pro firmu vedenou v daňové
 * evidenci (naseedovaná osnova z předchozího přepnutí by na to stačila).
 */
#[Group('integration')]
final class AccountingModeGateTest extends TestCase
{
    private Connection $db;
    private ChartOfAccountsAction $accountsAction;
    private MovementClassificationAction $classificationAction;

    private int $doubleEntrySupplierId = 0;
    private int $taxEvidenceSupplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db                   = $container->get(Connection::class);
            $this->accountsAction       = $container->get(ChartOfAccountsAction::class);
            $this->classificationAction = $container->get(MovementClassificationAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $hasTable = $pdo->query("SHOW TABLES LIKE 'de_movement_classification'")->fetch();
        if ($hasTable === false) {
            $this->markTestSkipped('Migrace 1027 (de_movement_classification) neproběhla.');
        }

        $templateId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($templateId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->doubleEntrySupplierId = $this->cloneSupplier($templateId, 'double_entry');
        $this->taxEvidenceSupplierId = $this->cloneSupplier($templateId, 'tax_evidence');
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

    public function testDoubleEntrySupplierCannotWriteTaxEvidenceClassification(): void
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/tax-evidence/classification')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->doubleEntrySupplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);

        $resp = $this->classificationAction->create($req, new Psr7Response());

        self::assertSame(403, $resp->getStatusCode(), 'double_entry firma nesmí zapisovat do /api/tax-evidence/*.');
        $body = json_decode((string) $resp->getBody(), true);
        self::assertSame('wrong_accounting_mode', $body['error']['code'] ?? null);
    }

    public function testTaxEvidenceSupplierCannotWriteAccountingChartOfAccounts(): void
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/accounting/chart-of-accounts')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->taxEvidenceSupplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);

        $resp = $this->accountsAction->create($req, new Psr7Response());

        self::assertSame(403, $resp->getStatusCode(), 'tax_evidence firma nesmí zapisovat do /api/accounting/*.');
        $body = json_decode((string) $resp->getBody(), true);
        self::assertSame('wrong_accounting_mode', $body['error']['code'] ?? null);
    }

    public function testTaxEvidenceSupplierCannotReadAccountingChartOfAccounts(): void
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/chart-of-accounts')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->taxEvidenceSupplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);

        $resp = $this->accountsAction->list($req, new Psr7Response());

        self::assertSame(403, $resp->getStatusCode(), 'tax_evidence firma nesmí ani číst osnovu (double_entry-only modul).');
        $body = json_decode((string) $resp->getBody(), true);
        self::assertSame('wrong_accounting_mode', $body['error']['code'] ?? null);
    }

    private function cloneSupplier(int $templateId, string $accountingMode): int
    {
        $pdo = $this->db->pdo();
        $cols = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier'
                AND COLUMN_NAME <> 'id' AND EXTRA NOT LIKE '%auto_increment%'
                AND (GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION = '')
              ORDER BY ORDINAL_POSITION"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $colList = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $cols));
        $pdo->prepare("INSERT INTO supplier ({$colList}) SELECT {$colList} FROM supplier WHERE id = ?")
            ->execute([$templateId]);
        $newId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET accounting_mode = ? WHERE id = ?')->execute([$accountingMode, $newId]);
        return $newId;
    }
}
