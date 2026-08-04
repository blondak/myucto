<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\Closing\JournalTransferAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Integrační test dvou noh převodů přes 261 (Epic F4, §6.2 I17, R14):
 * POST /journal/transfer → dva manual zápisy se sdíleným číslem řady PP
 * (sufix /1, /2), zůstatek 261 po obou nohách 0, precheck warning
 * transit_261_open při viselci na 261.
 *
 * POZOR: JournalTransferAction otevírá VLASTNÍ transakci (beginTransaction bez
 * kontroly vnější) — test proto NEBĚŽÍ v transakci; izolovaný supplier se
 * v tearDown maže (FK ON DELETE CASCADE uklidí osnovu, období, řady i deník).
 * Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class JournalTransferTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private JournalTransferAction $action;
    private AccountingPeriodRepository $periods;
    private ClosingRepository $closingRepo;
    private ClosingService $closing;
    private PostingService $posting;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    /** @var list<int> */
    private array $statementIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db          = $container->get(Connection::class);
            $this->action      = $container->get(JournalTransferAction::class);
            $this->periods     = $container->get(AccountingPeriodRepository::class);
            $this->closingRepo = $container->get(ClosingRepository::class);
            $this->closing     = $container->get(ClosingService::class);
            $this->posting     = $container->get(PostingService::class);
            $seeder            = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId   = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId    = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId         = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        // BEZ vnější transakce (akce si drží vlastní) — úklid dělá tearDown.
        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "f4-transfer@example.com", ?, ?, "double_entry")'
        );
        $stmt->execute(['F4 převody test s.r.o.', $czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->supplierId === 0) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($this->statementIds as $statementId) {
            $pdo->prepare('DELETE FROM bank_transactions WHERE statement_id = ?')->execute([$statementId]);
            $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$statementId]);
        }
        // Úklid dev DB: deník napřed (fk_jel_account RESTRICT na osnovu),
        // audit log nemá FK, zbytek uklidí ON DELETE CASCADE.
        $pdo->prepare('DELETE FROM journal_entry_lines WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        $this->db->close();
    }

    // ── I17 ──────────────────────────────────────────────────────────────────

    public function testI17TransferCreatesTwoLegsWithSharedDocumentNo(): void
    {
        $res = $this->transfer([
            'date_out'     => self::YEAR . '-12-28',
            'date_in'      => self::YEAR . '-12-30',
            'amount'       => 5000.00,
            'account_from' => '221',
            'account_to'   => '211',
        ]);

        self::assertSame(201, $res['status']);
        self::assertSame('PP-' . self::YEAR . '-0001', $res['body']['document_no'], 'Sdílené číslo z řady transfer (R13/R14).');
        self::assertCount(2, $res['body']['entries']);

        [$out, $in] = $res['body']['entries'];
        self::assertSame('PP-' . self::YEAR . '-0001/1', $out['document_no']);
        self::assertSame('PP-' . self::YEAR . '-0001/2', $in['document_no']);
        self::assertSame(self::YEAR . '-12-28', (string) $out['entry_date']);
        self::assertSame(self::YEAR . '-12-30', (string) $in['entry_date']);
        self::assertSame('manual', $out['source_type']);
        self::assertNull($out['source_id'], 'Manual zápis bez source_id (R14).');
        self::assertNotNull($out['posted_at']);

        // Noha 1: MD 261 / D 221; noha 2: MD 211 / D 261
        $outLines = $this->linesByAccountCode((int) $out['id']);
        self::assertSame(self::cents(5000.00), self::cents($outLines['261']['debit']));
        self::assertSame(self::cents(5000.00), self::cents($outLines['221']['credit']));
        $inLines = $this->linesByAccountCode((int) $in['id']);
        self::assertSame(self::cents(5000.00), self::cents($inLines['211']['debit']));
        self::assertSame(self::cents(5000.00), self::cents($inLines['261']['credit']));

        // Zůstatek 261 po obou nohách = 0
        $balance = $this->closingRepo->accountBalance($this->supplierId, '261', self::YEAR . '-12-31');
        self::assertSame(0, self::cents($balance), '261 po obou nohách na nule.');

        // Precheck: transit_261_open je OK
        $precheck = $this->closing->runPrecheck($this->supplierId, $this->periodId, $this->rv(), ['user_id' => $this->userId]);
        $check = $this->check($precheck['checks'], 'transit_261_open');
        self::assertTrue((bool) $check['ok'], 'Vyrovnané 261 → warning nehlásí.');
    }

    public function testI17TransferValidation(): void
    {
        // Shodné účty
        $res = $this->transfer([
            'date_out' => self::YEAR . '-12-28', 'date_in' => self::YEAR . '-12-30',
            'amount' => 100.00, 'account_from' => '221', 'account_to' => '221',
        ]);
        self::assertSame(422, $res['status']);

        // Neexistující účet
        $res = $this->transfer([
            'date_out' => self::YEAR . '-12-28', 'date_in' => self::YEAR . '-12-30',
            'amount' => 100.00, 'account_from' => '999', 'account_to' => '211',
        ]);
        self::assertSame(422, $res['status']);

        // date_out po date_in
        $res = $this->transfer([
            'date_out' => self::YEAR . '-12-30', 'date_in' => self::YEAR . '-12-28',
            'amount' => 100.00, 'account_from' => '221', 'account_to' => '211',
        ]);
        self::assertSame(422, $res['status']);

        // Nezáporná částka
        $res = $this->transfer([
            'date_out' => self::YEAR . '-12-28', 'date_in' => self::YEAR . '-12-30',
            'amount' => -5, 'account_from' => '221', 'account_to' => '211',
        ]);
        self::assertSame(422, $res['status']);
    }

    public function testI17DanglingTransitBalanceTriggersPrecheckWarning(): void
    {
        // Jen jedna noha (viselec na 261) — precheck warning transit_261_open
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '261', 'side' => 'debit', 'amount' => 1000.00],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 1000.00],
        ], ['entry_date' => self::YEAR . '-12-28', 'posted_by' => $this->userId]);

        $precheck = $this->closing->runPrecheck($this->supplierId, $this->periodId, $this->rv(), ['user_id' => $this->userId]);
        $check = $this->check($precheck['checks'], 'transit_261_open');
        self::assertFalse((bool) $check['ok'], 'Nenulové 261 k ends_on → warning transit_261_open (R14).');
        self::assertSame('warning', $check['severity']);
        self::assertSame(self::cents(1000.00), self::cents((float) $check['value']['balance']));
    }

    public function testManualTransferWarnsAboutExistingBankTransactionAndForceOverrides(): void
    {
        $pdo = $this->db->pdo();
        // Vlastnictví výpisu drží bank_statements.supplier_id (SEC-01 / R4), ne shoda
        // čísla účtu — kandidáti se hledají výhradně přes BankStatementOwnershipResolver.
        $txId = $this->bankCandidate($this->supplierId);

        // Cizí firma se STEJNÝM číslem účtu i částkou: nesmí se objevit v těle 409
        // (R4, 4.9 — dotaz dřív neměl tenant predikát a sypal přes celou instalaci).
        $foreignSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier WHERE id <> ' . $this->supplierId . ' ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        $foreignTxId = $foreignSupplierId !== 0 ? $this->bankCandidate($foreignSupplierId) : 0;

        $payload = [
            'date_out' => self::YEAR . '-12-28',
            'date_in' => self::YEAR . '-12-30',
            'amount' => 2500.00,
            'account_from' => '221',
            'account_to' => '211',
        ];
        $warning = $this->transfer($payload);
        self::assertSame(409, $warning['status']);
        self::assertSame('bank_transfer_candidates', $warning['body']['error']['code']);
        $candidates = $warning['body']['error']['data']['candidates'];
        self::assertSame($txId, $candidates[0]['tx_id']);

        if ($foreignTxId !== 0) {
            $ids = array_map(static fn (array $c): int => (int) $c['tx_id'], $candidates);
            self::assertNotContains($foreignTxId, $ids, 'Pohyb cizí firmy nesmí prosáknout do těla 409.');
            self::assertCount(1, $ids, 'Kandidáti jsou jen z vlastních výpisů.');
        }

        $created = $this->transfer($payload + ['force' => true]);
        self::assertSame(201, $created['status']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Výpis dané firmy s jedním pohybem -2500 Kč; vrací id pohybu. */
    private function bankCandidate(int $supplierId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date, imported_by)
             VALUES (?, "transfer-candidate.gpc", ?, "1000000005", "0100", "CZK", ?, ?)'
        )->execute([
            $supplierId, hash('sha256', uniqid('transfer-candidate', true)),
            self::YEAR . '-12-28', $this->userId,
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $this->statementIds[] = $statementId;
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, source, posted_at, amount, currency, counterparty_account,
                 counterparty_bank, description, match_status)
             VALUES (?, "statement", ?, -2500.00, "CZK", "2000000010", "0100", "Převod", "unmatched")'
        )->execute([$statementId, self::YEAR . '-12-28']);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function transfer(array $body): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/accounting/journal/transfer')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withParsedBody($body);
        $resp = $this->action->transfer($req, new Psr7Response());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function rv(): int
    {
        return (int) $this->periods->findById($this->supplierId, $this->periodId)['row_version'];
    }

    /**
     * @param list<array<string,mixed>> $checks
     * @return array<string,mixed>
     */
    private function check(array $checks, string $key): array
    {
        foreach ($checks as $check) {
            if ($check['key'] === $key) {
                return $check;
            }
        }
        self::fail('Precheck kontrola ' . $key . ' chybí.');
    }

    /**
     * @return array<string,array{debit:float,credit:float}>
     */
    private function linesByAccountCode(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ?'
        );
        $stmt->execute([$entryId, $this->supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $code = (string) $r['account_code'];
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][(string) $r['side']] += (float) $r['amount'];
        }
        return $out;
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100.0);
    }
}
