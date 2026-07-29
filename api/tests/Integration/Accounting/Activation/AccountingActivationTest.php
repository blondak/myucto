<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Activation;

use MyInvoice\Action\Settings\AccountingActivationAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingBackfillJobRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Activation\BackfillService;
use MyInvoice\Service\Accounting\Activation\OpeningBalanceService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

#[Group('integration')]
final class AccountingActivationTest extends TestCase
{
    private const STARTS_ON = '2099-01-01';

    private Connection $db;
    private AccountingActivationAction $action;
    private SettingsAction $settings;
    private AccountingBackfillJobRepository $jobs;
    private OpeningBalanceService $opening;
    private BackfillService $backfill;
    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(AccountingActivationAction::class);
            $this->settings = $container->get(SettingsAction::class);
            $this->jobs = $container->get(AccountingBackfillJobRepository::class);
            $this->opening = $container->get(OpeningBalanceService::class);
            $this->backfill = $container->get(BackfillService::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
            $periods = $container->get(AccountingPeriodRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/user v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare('DELETE FROM accounting_backfill_jobs WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM accounting_opening_balances WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare("DELETE FROM journal_entries WHERE supplier_id = ? AND source_type = 'opening'")->execute([$this->supplierId]);
        $pdo->prepare(
            "UPDATE supplier SET accounting_mode = 'tax_evidence', accounting_starts_on = ?,
                    accounting_activation_status = 'draft', taxpayer_type = 'fo' WHERE id = ?"
        )->execute([self::STARTS_ON, $this->supplierId]);
        $seeder->seedForSupplier($this->supplierId);
        $periods->ensureOpenPeriodFor($this->supplierId, self::STARTS_ON);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) $pdo->rollBack();
            $this->db->close();
        }
    }

    public function testDryRunWritesNothingAndRepeatedExecuteIsIdempotent(): void
    {
        $draft = $this->opening->saveDraft($this->supplierId, [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 100.00, 'note' => 'Syntetický test'],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 100.00, 'note' => 'Syntetický test'],
        ]);
        self::assertTrue($draft['totals']['balanced']);

        $dryId = $this->jobs->create($this->supplierId, 'dry_run', [
            'starts_on' => self::STARTS_ON,
            'opening_hash' => $draft['hash'],
            'with_rules' => false,
        ], $this->userId);
        $this->backfill->run($dryId);
        $dry = $this->jobs->find($dryId, $this->supplierId);
        self::assertSame('completed', $dry['status']);
        self::assertSame(0, $dry['report_json']['failed_total']);
        self::assertSame(0, $this->openingEntryCount(), 'Dry-run nezapsal otevírací zápis.');
        self::assertSame('tax_evidence', $this->supplierMode(), 'Dry-run nepřepnul účetní režim.');

        $firstId = $this->jobs->create($this->supplierId, 'execute', [
            'starts_on' => self::STARTS_ON,
            'opening_hash' => $draft['hash'],
            'with_rules' => false,
        ], $this->userId);
        $this->backfill->run($firstId);
        $firstEntryId = $this->openingEntryId();
        self::assertGreaterThan(0, $firstEntryId);
        self::assertSame('completed', $this->jobs->find($firstId, $this->supplierId)['status']);
        self::assertArrayHasKey(
            'advance_settlements',
            $this->jobs->find($firstId, $this->supplierId)['report_json']['phases'],
            'Aktivace po zaúčtování banky znovu přepočítá finální doklady navázané na zálohy.',
        );
        self::assertSame('double_entry', $this->supplierMode());
        self::assertSame('completed', $this->supplierActivationStatus());
        self::assertSame(0, $this->account701DifferenceCents($firstEntryId));

        $secondId = $this->jobs->create($this->supplierId, 'execute', [
            'starts_on' => self::STARTS_ON,
            'opening_hash' => $draft['hash'],
            'with_rules' => false,
        ], $this->userId);
        $this->backfill->run($secondId);
        self::assertSame('completed', $this->jobs->find($secondId, $this->supplierId)['status']);
        self::assertSame(1, $this->openingEntryCount(), 'Opakovaný execute nevytvořil duplicitní opening.');
        self::assertSame($firstEntryId, $this->openingEntryId(), 'Opening se přepsal in-place.');
    }

    public function testUnbalancedOpeningBlocksExecuteBeforeJobCreation(): void
    {
        $this->opening->saveDraft($this->supplierId, [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 100.00],
        ]);
        $response = $this->action->execute($this->request('POST'), new Psr7Response());
        $payload = $this->json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('opening_unbalanced', $payload['error']['code']);
        self::assertSame(0, $this->jobCount());
    }

    public function testDirectModeSwitchWithPendingHistoryReturnsBackfillRequired(): void
    {
        $pdo = $this->db->pdo();
        $name = 'E2 test ' . bin2hex(random_bytes(4));
        $pdo->prepare(
            "INSERT INTO cash_registers (supplier_id, name, account_code, is_default, is_active)
             VALUES (?, ?, '211E2', 0, 1)"
        )->execute([$this->supplierId, $name]);
        $registerId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, description,
                 vat_mode, total_amount, currency_code, status, created_by)
             VALUES (?, ?, 'in', 'other', ?, ?, 'Syntetický test aktivace',
                     'none', 100.00, 'CZK', 'posted', ?)"
        )->execute([$this->supplierId, $registerId, 'PPD-E2-' . bin2hex(random_bytes(3)), date('Y-m-d'), $this->userId]);

        $request = $this->request('PUT')->withParsedBody([
            'accounting_mode' => 'double_entry',
            'accounting_mode_effective_from' => date('Y-01-01'),
        ]);
        $response = $this->settings->updateSupplier($request, new Psr7Response());
        $payload = $this->json($response);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('backfill_required', $payload['error']['code']);
        self::assertGreaterThanOrEqual(1, $payload['error']['cash_documents']);
        self::assertSame('tax_evidence', $this->supplierMode());
    }

    public function testJobIsTenantScopedAndMutationRequiresAdmin(): void
    {
        $jobId = $this->jobs->create($this->supplierId, 'dry_run', [
            'starts_on' => self::STARTS_ON,
            'opening_hash' => hash('sha256', 'none'),
        ], $this->userId);

        $foreign = $this->request('GET', $this->supplierId + 999);
        $notFound = $this->action->job($foreign, new Psr7Response(), ['id' => (string) $jobId]);
        self::assertSame(404, $notFound->getStatusCode());

        $readonly = $this->request('POST')->withAttribute(AuthMiddleware::ATTR_USER, [
            'id' => $this->userId,
            'role' => 'readonly',
        ]);
        $forbidden = $this->action->start($readonly->withParsedBody(['starts_on' => date('Y-m-d')]), new Psr7Response());
        self::assertSame(403, $forbidden->getStatusCode());
    }

    public function testJobHistoryIsPaginatedWithoutSilentLimit(): void
    {
        for ($index = 0; $index < 3; $index++) {
            $id = $this->jobs->create($this->supplierId, 'dry_run', [
                'starts_on' => self::STARTS_ON,
                'opening_hash' => 'page-' . $index,
                'with_rules' => false,
            ], $this->userId);
            $this->jobs->markCompleted($id);
        }

        $request = $this->request('GET')->withQueryParams(['page' => '2', 'per_page' => '2']);
        $response = $this->action->jobs($request, new Psr7Response());
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $body['total']);
        self::assertSame(2, $body['page']);
        self::assertSame(2, $body['per_page']);
        self::assertCount(1, $body['items']);
    }

    public function testOpeningBankBalanceIgnoresForeignCurrencyTransactions(): void
    {
        $method = new \ReflectionMethod($this->opening, 'bankBalance');
        $asOf = '2098-12-31';
        $before = (float) $method->invoke($this->opening, $this->supplierId, $asOf);
        $pdo = $this->db->pdo();

        $insertCurrency = $pdo->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                 account_number, bank_code)
             VALUES (?, ?, ?, ?, ?, ?, 2, 0, 0, ?, ?)'
        );
        $insertStatement = $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insertTx = $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, posted_at, amount, currency) VALUES (?, ?, ?, ?)'
        );

        foreach ([['CZK', '9990981001', 100.00], ['EUR', '9990981002', 250.00]] as [$currency, $account, $amount]) {
            $insertCurrency->execute([
                $this->supplierId, $currency, "TEST opening {$currency}", $currency, $currency, $currency,
                $account, '0100',
            ]);
            $insertStatement->execute([
                $this->supplierId,
                "__test_opening_{$currency}.gpc",
                hash('sha256', "opening-{$this->supplierId}-{$currency}"),
                $account,
                '0100',
                $currency,
                $asOf,
            ]);
            $insertTx->execute([(int) $pdo->lastInsertId(), $asOf, $amount, $currency]);
        }

        $after = (float) $method->invoke($this->opening, $this->supplierId, $asOf);
        self::assertEqualsWithDelta(100.00, $after - $before, 0.001);
    }

    private function request(string $method, ?int $supplierId = null): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/settings/accounting-activation')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }

    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function supplierMode(): string
    {
        return (string) $this->db->pdo()->query('SELECT accounting_mode FROM supplier WHERE id = ' . $this->supplierId)->fetchColumn();
    }

    private function supplierActivationStatus(): string
    {
        return (string) $this->db->pdo()->query('SELECT accounting_activation_status FROM supplier WHERE id = ' . $this->supplierId)->fetchColumn();
    }

    private function openingEntryCount(): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId} AND source_type = 'opening'"
        )->fetchColumn();
    }

    private function openingEntryId(): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT id FROM journal_entries WHERE supplier_id = {$this->supplierId} AND source_type = 'opening' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
    }

    private function account701DifferenceCents(int $entryId): int
    {
        return (int) $this->db->pdo()->query(
            "SELECT CAST(ROUND(COALESCE(SUM(CASE WHEN jel.side = 'debit' THEN jel.amount ELSE -jel.amount END), 0) * 100) AS SIGNED)
               FROM journal_entry_lines jel
               JOIN chart_of_accounts coa ON coa.id = jel.account_id AND coa.supplier_id = jel.supplier_id
              WHERE jel.entry_id = {$entryId} AND coa.account_code = '701'"
        )->fetchColumn();
    }

    private function jobCount(): int
    {
        return (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM accounting_backfill_jobs WHERE supplier_id = ' . $this->supplierId
        )->fetchColumn();
    }
}
