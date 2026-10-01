<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\StatementImporter;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Nahrání výpisu k pohybům, které už jsou v evidenci (stažené z napojení banky), je
 * jen PROPOJÍ s výpisem. Nepáruje je znovu na faktury, nepřeúčtovává je a nepočítá
 * znovu pozorování protistran — viz {@see \MyInvoice\Service\Bank\StatementImportTransactions}.
 *
 * Dřív se každý takový pohyb po nahrání výpisu zpracoval znovu: převod mezi vlastními
 * účty se shodným VS se spároval na fakturu (falešná úhrada) a nespárovaný pohyb
 * dostal úhradu faktury, která vznikla až po jeho stažení.
 *
 * Izolace: rok 2099, VS mimo běžné řady, výpisy se značkou v názvu souboru; vše se maže.
 */
#[Group('integration')]
final class StatementUploadKeepsEvidencedTransactionsTest extends TestCase
{
    private const FILE_MARKER = '__reupload__';
    private const VS_TRANSFER = '2099880001';
    private const VS_PAYMENT = '2099880002';

    private Connection $db;
    private StatementImporter $importer;
    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $clientId = 0;
    private int $userId = 0;
    private string $account = '';

    /** @var list<int> */
    private array $transactionIds = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->importer = $c->get(StatementImporter::class);
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
        $this->clientId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->clientId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí client/user pro supplier.');
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    public function testUploadDoesNotRematchOwnTransferWithInvoiceOfSameSymbol(): void
    {
        $api = $this->apiImport('transfer', [
            $this->tx('SYNTH-RU-IN', 1000.00, self::VS_TRANSFER, 'Prevod z vlastniho uctu'),
            $this->tx('SYNTH-RU-OUT', -1000.00, self::VS_TRANSFER, 'Prevod na vlastni ucet'),
        ]);
        [$in, $out] = $this->transactionIds;
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO bank_transfer_matches (supplier_id, out_transaction_id, in_transaction_id, amount, currency) VALUES (?, ?, ?, 1000.00, "CZK")')
            ->execute([$this->supplierId, $out, $in]);
        // Stav po spárování převodu: pohyb s fakturou spárovaný není a důvod nepárování nenese.
        $pdo->prepare('UPDATE bank_transactions SET match_reason = NULL WHERE id IN (?, ?)')->execute([$in, $out]);
        $invoiceId = $this->insertInvoice(self::VS_TRANSFER, 1000.00);
        $before = $this->state();

        $result = $this->upload('transfer', $api['parsed']);

        self::assertSame(0, $result['transactions']);
        self::assertSame(2, $result['skipped_duplicates']);
        self::assertSame($before, $this->state(), 'Nahrání výpisu nesmí převod mezi vlastními účty přepárovat ani přeúčtovat.');
        self::assertSame(0, $this->paymentCount($invoiceId), 'Převod mezi vlastními účty nesmí uhradit fakturu se shodným VS.');
        $linked = $pdo->prepare('SELECT COUNT(DISTINCT bti.bank_transaction_id) FROM bank_transaction_imports bti JOIN bank_statements bs ON bs.id = bti.statement_id WHERE bs.file_name = ?');
        $linked->execute([self::FILE_MARKER . 'transfer.gpc']);
        self::assertSame(2, (int) $linked->fetchColumn(), 'Výpis se k evidovaným pohybům jen propojí.');
    }

    public function testUploadDoesNotMatchEvidencedUnmatchedMovementAgain(): void
    {
        $api = $this->apiImport('payment', [
            $this->tx('SYNTH-RU-PAY', 2500.00, self::VS_PAYMENT, 'Platba od odberatele', '1000000005', '0800'),
        ]);
        [$txId] = $this->transactionIds;
        self::assertSame('unmatched', $this->db->pdo()->query("SELECT match_status FROM bank_transactions WHERE id = $txId")->fetchColumn());
        // Faktura vznikla až po stažení pohybu — dorovnání je na uživateli („Znovu spárovat").
        $invoiceId = $this->insertInvoice(self::VS_PAYMENT, 2500.00);
        $before = $this->state();

        $result = $this->upload('payment', $api['parsed']);

        self::assertSame(0, $result['transactions']);
        self::assertSame(1, $result['skipped_duplicates']);
        self::assertSame($before, $this->state(), 'Nahrání výpisu nesmí už evidovaný pohyb znovu párovat ani počítat pozorování.');
        self::assertSame(0, $this->paymentCount($invoiceId));
    }

    /** @return array{parsed:array<string,mixed>,result:array<string,mixed>} */
    private function apiImport(string $tag, array $transactions): array
    {
        $parsed = [
            'header' => [
                'account_number' => $this->account, 'statement_number' => null, 'statement_date' => '2099-03-31',
                'prev_balance' => null, 'curr_balance' => null, 'credit_total' => null, 'debit_total' => null,
            ],
            'transactions' => $transactions,
        ];
        $result = $this->importer->importConnectedParsed(
            $parsed, 'synthetic-api-' . $tag, self::FILE_MARKER . $tag . '.json', $this->userId, $this->currencyId, $this->supplierId,
        );
        self::assertSame(count($transactions), $result['transactions']);
        $stmt = $this->db->pdo()->prepare('SELECT bt.id FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id WHERE bs.file_name = ? ORDER BY bt.id');
        $stmt->execute([self::FILE_MARKER . $tag . '.json']);
        $this->transactionIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        self::assertCount(count($transactions), $this->transactionIds);
        return ['parsed' => $parsed, 'result' => $result];
    }

    /** Ruční nahrání výpisu za totéž období (GPC zvoleného účtu). */
    private function upload(string $tag, array $parsed): array
    {
        $parsed['header']['statement_number'] = '3';
        $parsed['header']['prev_balance'] = 0.0;
        $parsed['header']['curr_balance'] = 0.0;
        return $this->importer->import(
            'synthetic-gpc-' . $tag, self::FILE_MARKER . $tag . '.gpc', $this->userId, $this->currencyId, [], $parsed,
        );
    }

    private function tx(string $ref, float $amount, string $vs, string $description, string $counterparty = '1000000005', string $bank = '0300'): array
    {
        return [
            'posted_at' => '2099-03-10', 'amount' => $amount, 'currency' => 'CZK',
            'variable_symbol' => $vs, 'constant_symbol' => '', 'specific_symbol' => '',
            'counterparty_account' => $counterparty, 'counterparty_bank' => $bank, 'counterparty_name' => 'Synthetic protistrana',
            'description' => $description, 'bank_ref' => $ref,
        ];
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $pdo = $this->db->pdo();
        $ids = implode(',', $this->transactionIds);
        $rows = static fn (string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return [
            'transactions' => $rows("SELECT id, statement_id, match_status, match_reason, matched_invoice_id, matched_at, matched_by, ignore_note FROM bank_transactions WHERE id IN ($ids) ORDER BY id"),
            'invoice_payments' => $rows("SELECT * FROM invoice_payments WHERE bank_transaction_id IN ($ids) ORDER BY id"),
            'payment_matches' => $rows("SELECT * FROM payment_matches WHERE bank_transaction_id IN ($ids) ORDER BY id"),
            'transfers' => $rows("SELECT * FROM bank_transfer_matches WHERE in_transaction_id IN ($ids) OR out_transaction_id IN ($ids) ORDER BY id"),
            'journal' => $rows("SELECT * FROM journal_entries WHERE source_type = 'bank' AND source_id IN ($ids) ORDER BY id"),
            'journal_lines' => $rows("SELECT l.* FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE e.source_type = 'bank' AND e.source_id IN ($ids) ORDER BY l.id"),
            'observations' => $rows("SELECT * FROM bank_counterparty_observations WHERE bank_transaction_id IN ($ids) ORDER BY id"),
            'counterparty_map' => $rows("SELECT id, match_count, manual_count, contradiction_count FROM bank_counterparty_map WHERE supplier_id = {$this->supplierId} ORDER BY id"),
            'suggestions' => $rows("SELECT * FROM bank_match_suggestions WHERE bank_transaction_id IN ($ids) ORDER BY id"),
            'journal_total' => (int) $pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn(),
        ];
    }

    private function insertInvoice(string $vs, float $amount): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, paid_total, created_by)
             VALUES ('invoice', ?, ?, ?, '2099-03-01', '2099-03-01', '2099-03-15', ?, 'issued', ?, ?, 0, ?)"
        )->execute([$vs, $this->clientId, $this->supplierId, $this->currencyId, $amount, $amount, $this->userId]);
        return (int) $pdo->lastInsertId();
    }

    private function paymentCount(int $invoiceId): int
    {
        return (int) $this->db->pdo()->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = $invoiceId")->fetchColumn();
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $vs = [self::VS_TRANSFER, self::VS_PAYMENT];
        $txIds = array_map('intval', $pdo->query(
            "SELECT bt.id FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.file_name LIKE '" . self::FILE_MARKER . "%'"
        )->fetchAll(PDO::FETCH_COLUMN));
        $pdo->prepare('DELETE p FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id WHERE i.supplier_id = ? AND i.varsymbol IN (?, ?)')
            ->execute([$this->supplierId, ...$vs]);
        if ($txIds !== []) {
            $in = implode(',', $txIds);
            $maps = $pdo->query("SELECT DISTINCT map_id FROM bank_counterparty_observations WHERE bank_transaction_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
            $pdo->exec("DELETE FROM bank_counterparty_observations WHERE bank_transaction_id IN ($in)");
            foreach ($maps as $mapId) {
                $pdo->prepare('DELETE FROM bank_counterparty_map WHERE id = ? AND NOT EXISTS (SELECT 1 FROM bank_counterparty_observations o WHERE o.map_id = ?)')
                    ->execute([$mapId, $mapId]);
            }
            $pdo->exec("DELETE cba FROM client_bank_accounts cba WHERE cba.last_bank_transaction_id IN ($in) AND cba.source_manual = 0 AND cba.source_vat_registry = 0
                AND NOT EXISTS (SELECT 1 FROM bank_counterparty_map m WHERE m.client_bank_account_id = cba.id)");
            $pdo->exec("DELETE FROM bank_match_suggestions WHERE bank_transaction_id IN ($in)");
            $pdo->exec("DELETE FROM bank_transfer_matches WHERE in_transaction_id IN ($in) OR out_transaction_id IN ($in)");
            $pdo->exec("DELETE FROM invoice_payments WHERE bank_transaction_id IN ($in)");
        }
        $statementIds = array_map('intval', $pdo->query(
            "SELECT id FROM bank_statements WHERE file_name LIKE '" . self::FILE_MARKER . "%'
              UNION SELECT m.monthly_statement_id FROM bank_api_evidence_months m JOIN bank_statements bs ON bs.id = m.evidence_statement_id
              WHERE bs.file_name LIKE '" . self::FILE_MARKER . "%'"
        )->fetchAll(PDO::FETCH_COLUMN));
        if ($statementIds !== []) {
            $in = implode(',', $statementIds);
            $pdo->exec("DELETE FROM bank_transaction_imports WHERE statement_id IN ($in) OR original_statement_id IN ($in)");
            $pdo->exec("DELETE FROM bank_api_evidence_months WHERE evidence_statement_id IN ($in) OR monthly_statement_id IN ($in)");
            $pdo->exec("DELETE FROM bank_api_months WHERE statement_id IN ($in)");
            $pdo->exec("DELETE FROM bank_transactions WHERE statement_id IN ($in)");
            $pdo->exec("DELETE FROM bank_statements WHERE id IN ($in)");
        }
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ? AND varsymbol IN (?, ?)')->execute([$this->supplierId, ...$vs]);
    }
}
