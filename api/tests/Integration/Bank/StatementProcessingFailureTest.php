<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Bank\EmailNoticeReconciler;
use MyInvoice\Service\Bank\GpcParser;
use MyInvoice\Service\Bank\StatementImporter;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Bank\StatementProcessingState;
use MyInvoice\Service\Crm\CrmAggregationService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Zpracování pohybů po uložení výpisu (párování, zaúčtování) může spadnout. Pohyby už
 * jsou v evidenci a opakované nahrání souboru je nezpracuje, takže výpis musí o selhání
 * vědět a ukázat ho, dokud uživatel nepoužije „Přepárovat výpis".
 *
 * Izolace: rok 2099, výpisy se značkou v názvu souboru; vše se maže.
 */
#[Group('integration')]
final class StatementProcessingFailureTest extends TestCase
{
    private const FILE_MARKER = '__processing_failure__';

    private Connection $db;
    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private string $account = '';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->db = Bootstrap::buildContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $currency = $pdo->query(
            "SELECT id, supplier_id, account_number FROM currencies
              WHERE code = 'CZK' AND is_active = 1 AND account_number IS NOT NULL AND account_number <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$currency) {
            $this->markTestSkipped('Chybí aktivní CZK účet s číslem.');
        }
        $this->currencyId = (int) $currency['id'];
        $this->supplierId = (int) $currency['supplier_id'];
        $this->account = (string) $currency['account_number'];
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0) {
            $this->markTestSkipped('Chybí user.');
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    public function testFailedProcessingKeepsTransactionsAndFlagsStatementUntilRematch(): void
    {
        $result = $this->failingImporter()->import(
            'synthetic-gpc-failure', self::FILE_MARKER . 'upload.gpc', $this->userId, $this->currencyId, [], $this->parsed(),
        );

        self::assertTrue($result['processing_failed']);
        self::assertSame(2, $result['transactions'], 'Pohyby zůstanou uložené i po selhání zpracování.');
        $warning = array_values(array_filter($result['warnings'], static fn (array $w): bool => $w['code'] === 'processing_failed'));
        self::assertCount(1, $warning);
        self::assertStringContainsString('Přepárovat výpis', $warning[0]['message']);
        self::assertStringContainsString('Synthetic matcher failure', $warning[0]['error']);

        $statementId = (int) $result['statement_id'];
        $detail = $this->detail($statementId);
        self::assertTrue($detail['processing_failed']);
        self::assertSame(2, $detail['unprocessed_count']);
        self::assertStringContainsString('Synthetic matcher failure', (string) $detail['processing_error']);
        self::assertArrayNotHasKey('processing_error', $detail['transactions'][0], 'Stav zpracování nese výpis, ne pohyb.');

        $listed = $this->listed($statementId);
        self::assertTrue($listed['item']['processing_failed']);
        self::assertSame(2, $listed['item']['unprocessed_count']);
        self::assertGreaterThanOrEqual(1, $listed['processing_failed_statements']);

        $items = $this->crm()->bankProcessingActionItems($this->supplierId, true);
        self::assertContains('/bank/' . $statementId, array_column($items, 'link'));
        self::assertSame([], $this->crm()->bankProcessingActionItems($this->supplierId, false));

        $rematch = $this->action()->rematch($this->request('POST', '/api/bank-statements/' . $statementId . '/rematch'), new Response(), ['id' => (string) $statementId]);
        self::assertSame(200, $rematch->getStatusCode());

        $after = $this->detail($statementId);
        self::assertFalse($after['processing_failed'], '„Přepárovat výpis" upozornění odstraní.');
        self::assertSame(0, $after['unprocessed_count']);
        self::assertNull($after['processing_error']);
        self::assertNotContains('/bank/' . $statementId, array_column($this->crm()->bankProcessingActionItems($this->supplierId, true), 'link'));
    }

    /** Výpis uložený bez zámku účtu (PDF bez jednoznačného účtu) zpracovává pohyby hned. */
    public function testFailedProcessingOnDirectPersistIsFlaggedToo(): void
    {
        $parsed = $this->parsed();
        $result = $this->failingImporter()->importParsedPdf($parsed, '%PDF-synthetic-failure', self::FILE_MARKER . 'direct.pdf', $this->userId);

        self::assertTrue($result['processing_failed']);
        self::assertSame(2, $result['transactions']);
        self::assertSame(2, StatementProcessingState::forStatement($this->db->pdo(), (int) $result['statement_id'])['unprocessed_count']);
    }

    public function testSuccessfulImportLeavesNoProcessingFlag(): void
    {
        $importer = Bootstrap::buildContainer()->get(StatementImporter::class);
        $result = $importer->import(
            'synthetic-gpc-success', self::FILE_MARKER . 'success.gpc', $this->userId, $this->currencyId, [], $this->parsed(),
        );

        self::assertFalse($result['processing_failed']);
        $pending = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.file_name = ? AND bt.processing_pending_at IS NOT NULL'
        );
        $pending->execute([self::FILE_MARKER . 'success.gpc']);
        self::assertSame(0, (int) $pending->fetchColumn());
        self::assertFalse($this->detail((int) $result['statement_id'])['processing_failed']);
    }

    /** Pohyb bez chyby, jehož zpracování nedoběhlo (pád procesu), se po lhůtě hlásí také. */
    public function testInterruptedProcessingIsReportedAfterGracePeriod(): void
    {
        $result = $this->failingImporter()->import(
            'synthetic-gpc-interrupted', self::FILE_MARKER . 'interrupted.gpc', $this->userId, $this->currencyId, [], $this->parsed(),
        );
        $statementId = (int) $result['statement_id'];
        $pdo = $this->db->pdo();
        $pending = StatementProcessingState::pendingSince();
        $pdo->exec("UPDATE bank_transactions SET processing_error = NULL, processing_pending_at = '$pending' WHERE statement_id = $statementId");

        self::assertSame(0, StatementProcessingState::forStatement($pdo, $statementId)['unprocessed_count'], 'Běžící import se nehlásí.');
        $later = new \DateTimeImmutable('+' . (StatementProcessingState::GRACE_MINUTES + 1) . ' minutes');
        $count = (int) $pdo->query('SELECT COUNT(*) FROM bank_transactions bt WHERE bt.statement_id = ' . $statementId . ' AND ' . StatementProcessingState::failedSql('bt', $later))->fetchColumn();
        self::assertSame(2, $count);
    }

    private function failingImporter(): StatementImporter
    {
        $c = Bootstrap::buildContainer();
        $matcher = $this->createStub(StatementMatcher::class);
        $matcher->method('matchBatch')->willThrowException(new \RuntimeException('Synthetic matcher failure'));
        $matcher->method('match')->willThrowException(new \RuntimeException('Synthetic matcher failure'));
        return new StatementImporter(
            $this->db,
            new GpcParser(),
            $matcher,
            $c->get(EmailNoticeReconciler::class),
            $c->get(BankPostingService::class),
        );
    }

    /** @return array<string,mixed> */
    private function parsed(): array
    {
        return [
            'header' => [
                'account_number' => $this->account, 'statement_number' => '7', 'statement_date' => '2099-04-30',
                'prev_balance' => 0.0, 'curr_balance' => 1500.0, 'credit_total' => 2000.0, 'debit_total' => 500.0,
            ],
            'transactions' => [
                $this->tx('SYNTH-PF-IN', 2000.00, '2099770001'),
                $this->tx('SYNTH-PF-OUT', -500.00, '2099770002'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function tx(string $ref, float $amount, string $vs): array
    {
        return [
            'posted_at' => '2099-04-10', 'amount' => $amount, 'currency' => 'CZK',
            'variable_symbol' => $vs, 'constant_symbol' => '', 'specific_symbol' => '',
            'counterparty_account' => '1000000005', 'counterparty_bank' => '0100', 'counterparty_name' => 'Synthetic protistrana',
            'description' => 'Synthetic processing failure', 'bank_ref' => $ref,
        ];
    }

    private function action(): BankStatementAction
    {
        return Bootstrap::buildContainer()->get(BankStatementAction::class);
    }

    private function crm(): CrmAggregationService
    {
        return Bootstrap::buildContainer()->get(CrmAggregationService::class);
    }

    private function request(string $method, string $uri, array $query = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $uri)
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }

    /** @return array<string,mixed> */
    private function detail(int $statementId): array
    {
        $response = $this->action()->detail($this->request('GET', '/api/bank-statements/' . $statementId), new Response(), ['id' => (string) $statementId]);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        return $body['data'] ?? $body;
    }

    /** @return array{item:array<string,mixed>,processing_failed_statements:int} */
    private function listed(int $statementId): array
    {
        $response = $this->action()->list($this->request('GET', '/api/bank-statements', ['filter' => ['year' => '2099', 'month' => '4']]), new Response());
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $body = $body['data'] ?? $body;
        $item = array_values(array_filter($body['items'], static fn (array $row): bool => (int) $row['id'] === $statementId))[0] ?? null;
        self::assertNotNull($item, 'Výpis musí být v seznamu.');
        return ['item' => $item, 'processing_failed_statements' => (int) $body['processing_failed_statements']];
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $statementIds = array_map('intval', $pdo->query(
            "SELECT id FROM bank_statements WHERE file_name LIKE '" . self::FILE_MARKER . "%'
              UNION SELECT m.monthly_statement_id FROM bank_api_evidence_months m JOIN bank_statements bs ON bs.id = m.evidence_statement_id
              WHERE bs.file_name LIKE '" . self::FILE_MARKER . "%'"
        )->fetchAll(PDO::FETCH_COLUMN));
        if ($statementIds === []) {
            return;
        }
        $in = implode(',', $statementIds);
        $txIds = array_map('intval', $pdo->query("SELECT id FROM bank_transactions WHERE statement_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
        if ($txIds !== []) {
            $tx = implode(',', $txIds);
            $maps = $pdo->query("SELECT DISTINCT map_id FROM bank_counterparty_observations WHERE bank_transaction_id IN ($tx)")->fetchAll(PDO::FETCH_COLUMN);
            $pdo->exec("DELETE FROM bank_counterparty_observations WHERE bank_transaction_id IN ($tx)");
            foreach ($maps as $mapId) {
                $pdo->prepare('DELETE FROM bank_counterparty_map WHERE id = ? AND NOT EXISTS (SELECT 1 FROM bank_counterparty_observations o WHERE o.map_id = ?)')
                    ->execute([$mapId, $mapId]);
            }
            $pdo->exec("DELETE cba FROM client_bank_accounts cba WHERE cba.last_bank_transaction_id IN ($tx) AND cba.source_manual = 0 AND cba.source_vat_registry = 0
                AND NOT EXISTS (SELECT 1 FROM bank_counterparty_map m WHERE m.client_bank_account_id = cba.id)");
            $pdo->exec("DELETE FROM bank_match_suggestions WHERE bank_transaction_id IN ($tx)");
            $pdo->exec("DELETE FROM invoice_payments WHERE bank_transaction_id IN ($tx)");
            $pdo->exec("DELETE l FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE e.source_type = 'bank' AND e.source_id IN ($tx)");
            $pdo->exec("DELETE FROM journal_entries WHERE source_type = 'bank' AND source_id IN ($tx)");
        }
        $pdo->exec("DELETE FROM bank_transaction_imports WHERE statement_id IN ($in) OR original_statement_id IN ($in)");
        $pdo->exec("DELETE FROM bank_api_evidence_months WHERE evidence_statement_id IN ($in) OR monthly_statement_id IN ($in)");
        $pdo->exec("DELETE FROM bank_api_months WHERE statement_id IN ($in)");
        $pdo->exec("DELETE FROM bank_transactions WHERE statement_id IN ($in)");
        $pdo->exec("DELETE FROM bank_statements WHERE id IN ($in)");
    }
}
