<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CashJournalRepository;
use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\TaxEvidence\CashJournalService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Sdílený základ integračních testů peněžního deníku daňové evidence (Epic DE, A2).
 * Vše v jedné rollbackované transakci (rok 2099, demo data se nemění); throwaway
 * supplier přes cloneSupplier — NIKDY se nesahá na supplier 1. Soft-skip bez cfg.php / DB.
 */
abstract class CashJournalTestCase extends TestCase
{
    protected const YEAR = 2099;

    protected \Psr\Container\ContainerInterface $container;
    protected Connection $db;
    protected CashJournalRepository $repo;
    protected CashJournalService $service;

    protected int $supplierId = 0;   // throwaway supplier A
    protected int $currencyId = 0;   // CZK currency A (s account_number)
    protected int $clientId = 0;     // odběratel A
    protected int $vendorId = 0;     // dodavatel A
    protected int $registerId = 0;   // pokladna A
    protected int $userId = 0;
    protected int $czId = 0;
    protected string $accountA = '990000111';
    protected bool $inTx = false;

    /**
     * Monotonní pořadí čísla dokladu. Dřív se losovalo `random_int(1000, 9999)`, jenže
     * `uq_cashdoc_supplier_number` je unikátní na (supplier_id, doc_number) a jeden test
     * založí desítky dokladů nad TÝMŽ throwaway supplierem — z 9000 hodnot to narozeninovým
     * paradoxem občas kolidovalo a sada spadla na duplicate entry. Čítač je per-proces;
     * napříč procesy se liší supplier, takže na unikátnost stačí.
     */
    private static int $docSeq = 0;

    /** Unikátní číslo pokladního dokladu pro testovací data. */
    protected static function nextDocNumber(string $prefix): string
    {
        return sprintf('%s-%d-%05d', $prefix, self::YEAR, ++self::$docSeq);
    }

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->container = $c;
            $this->db      = $c->get(Connection::class);
            $this->repo    = $c->get(CashJournalRepository::class);
            $this->service = $c->get(CashJournalService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $hasTable = $pdo->query("SHOW TABLES LIKE 'de_movement_classification'")->fetch();
        if ($hasTable === false) {
            $this->markTestSkipped('Migrace 1027 (de_movement_classification) neproběhla.');
        }
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId   = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí user/country CZ.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $this->supplierId = $this->cloneSupplier('tax_evidence', true);
        $this->currencyId = $this->currencyRow($this->supplierId, 'CZK', $this->accountA, '2010');
        $this->registerId = $this->cashRegister($this->supplierId);
        $this->clientId   = $this->client($this->supplierId, 'Odběratel A s.r.o.');
        $this->vendorId   = $this->client($this->supplierId, 'Dodavatel A s.r.o.');
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

    protected function fullYear(int $supplierId, bool $vatPayer = true): array
    {
        $this->setVatPayer($supplierId, $vatPayer);
        return $this->service->build($supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31', ['year' => self::YEAR]);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    protected function cloneSupplier(string $mode, bool $vatPayer): int
    {
        $pdo = $this->db->pdo();
        $template = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($template === 0) {
            $this->markTestSkipped('Žádný supplier k naklonování.');
        }
        $cols = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier'
                AND COLUMN_NAME <> 'id' AND EXTRA NOT LIKE '%auto_increment%'
                AND (GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION = '')
              ORDER BY ORDINAL_POSITION"
        )->fetchAll(PDO::FETCH_COLUMN);
        $colList = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $cols));
        $pdo->prepare("INSERT INTO supplier ({$colList}) SELECT {$colList} FROM supplier WHERE id = ?")
            ->execute([$template]);
        $newId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET accounting_mode = ?, is_vat_payer = ? WHERE id = ?')
            ->execute([$mode, $vatPayer ? 1 : 0, $newId]);
        return $newId;
    }

    protected function setVatPayer(int $supplierId, bool $vatPayer): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = ? WHERE id = ?')
            ->execute([$vatPayer ? 1 : 0, $supplierId]);
    }

    protected function currencyRow(int $supplierId, string $code, ?string $account, ?string $bankCode): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, 1, ?, ?)'
        )->execute([$supplierId, $code, $code, $code, $code, $code, $account, $bankCode]);
        return (int) $pdo->lastInsertId();
    }

    protected function cashRegister(int $supplierId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO cash_registers (supplier_id, name, currency_code, account_code, is_default)
             VALUES (?, 'Hlavní pokladna', 'CZK', '211', 1)"
        )->execute([$supplierId]);
        return (int) $pdo->lastInsertId();
    }

    protected function client(int $supplierId, string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "t@example.com", "cs", ?, 1, 1)'
        );
        $stmt->execute([$supplierId, $name, $this->czId, $this->currencyId ?: null]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Pokladní doklad (posted). $link: ['invoice_id'=>..] nebo ['purchase_invoice_id'=>..].
     * $vat: ['base'=>, 'vat'=>, 'deduction'?, 'percent'?, 'tax_treatment'?]
     * přidá řádek cash_document_vat_lines (vat_mode='vat').
     *
     * @param array<string,int> $link
     * @param array<string,mixed>|null $vat
     */
    protected function cashDoc(string $docType, string $purpose, float $total, array $link = [], ?array $vat = null, ?string $date = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, partner_name,
                 description, vat_mode, total_amount, currency_code, fx_rate, invoice_id, purchase_invoice_id, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 'Protistrana', 'Pokladní pohyb', ?, ?, 'CZK', 1, ?, ?, 'posted', ?)"
        )->execute([
            $this->supplierId, $this->registerId, $docType, $purpose,
            self::nextDocNumber($docType === 'in' ? 'PPD' : 'VPD'),
            $date ?? (self::YEAR . '-06-15'),
            $vat !== null ? 'vat' : 'none',
            $total,
            $link['invoice_id'] ?? null,
            $link['purchase_invoice_id'] ?? null,
            $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        if ($vat !== null) {
            $pdo->prepare(
                'INSERT INTO cash_document_vat_lines
                    (cash_document_id, vat_rate, base_amount, vat_amount, vat_deduction, vat_deduction_percent, tax_treatment)
                 VALUES (?, 21.00, ?, ?, ?, ?, ?)'
            )->execute([$id, $vat['base'], $vat['vat'], $vat['deduction'] ?? 'full',
                $vat['percent'] ?? 100, $vat['tax_treatment'] ?? 'deductible']);
        }
        return $id;
    }

    protected function statement(int $supplierId, string $account, string $bankCode = '2010'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_statements (file_name, file_hash, account_number, bank_code, currency, statement_date, imported_by)
             VALUES (?, ?, ?, ?, "CZK", ?, ?)'
        )->execute([
            'test.gpc', hash('sha256', uniqid('cjst', true)), $account, $bankCode,
            self::YEAR . '-06-15', $this->userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @param array<string,mixed> $over */
    protected function bankTx(int $statementId, float $amount, array $over = []): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, source, posted_at, amount, currency, variable_symbol,
                 counterparty_name, description, match_status, matched_invoice_id)
             VALUES (?, "statement", ?, ?, "CZK", ?, ?, ?, ?, ?)'
        )->execute([
            $statementId,
            $over['posted_at'] ?? (self::YEAR . '-06-15'),
            $amount,
            $over['variable_symbol'] ?? null,
            $over['counterparty_name'] ?? 'Protistrana',
            $over['description'] ?? 'Platba',
            $over['match_status'] ?? 'unmatched',
            $over['matched_invoice_id'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    protected function invoicePayment(int $supplierId, int $invoiceId, float $amount, string $source, ?int $bankTxId = null, ?string $date = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id)
             VALUES (?, ?, ?, ?, "CZK", ?, ?)'
        )->execute([$supplierId, $invoiceId, $date ?? (self::YEAR . '-06-15'), $amount, $source, $bankTxId]);
        return (int) $pdo->lastInsertId();
    }

    protected function paymentMatch(int $supplierId, int $bankTxId, int $purchaseInvoiceId, float $amount): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type)
             VALUES (?, ?, ?, ?, "manual")'
        )->execute([$supplierId, $bankTxId, $purchaseInvoiceId, $amount]);
    }

    /**
     * Vydaná faktura. $opts: status, paid_at, income_tax_exempt, without, with, type.
     * @param array<string,mixed> $opts
     */
    protected function saleInvoice(int $supplierId, array $opts = []): int
    {
        $without = (float) ($opts['without'] ?? 10000.0);
        $with    = (float) ($opts['with'] ?? $without);
        $status  = (string) ($opts['status'] ?? 'issued');
        $paidAt  = $opts['paid_at'] ?? null;
        $type    = (string) ($opts['type'] ?? 'invoice');
        $exempt  = (int) ($opts['income_tax_exempt'] ?? 0);
        $issue   = (string) ($opts['issue_date'] ?? (self::YEAR . '-06-10'));
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, paid_at, income_tax_exempt, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, '1', ?)"
        )->execute([
            $supplierId, (string) random_int(100000, 999999), $type, $this->clientId,
            $issue, $issue, $issue, $this->currencyId,
            $without, round($with - $without, 2), $with,
            $status, $paidAt, $exempt, $this->userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Přijatá faktura. $opts: without, with, tax_deductible, document_kind, paid_at, status.
     * @param array<string,mixed> $opts
     */
    protected function purchaseInvoice(int $supplierId, array $opts = []): int
    {
        $without = (float) ($opts['without'] ?? 5000.0);
        $with    = (float) ($opts['with'] ?? $without);
        $kind    = (string) ($opts['document_kind'] ?? 'invoice');
        $deduct  = (int) ($opts['tax_deductible'] ?? 1);
        $paidAt  = $opts['paid_at'] ?? null;
        $status  = (string) ($opts['status'] ?? 'received');
        $isFixedAsset = !empty($opts['is_fixed_asset']) ? 1 : 0;
        $vatDeduction = (string) ($opts['vat_deduction'] ?? 'full');
        $vatDeductionPercent = (float) ($opts['vat_deduction_percent'] ?? 100);
        $issue   = (string) ($opts['issue_date'] ?? (self::YEAR . '-06-10'));
        $snapshot = json_encode(['company_name' => 'Dodavatel A s.r.o.'], JSON_UNESCAPED_UNICODE);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                  issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, is_fixed_asset,
                  total_without_vat, total_vat, total_with_vat, tax_deductible, status, paid_at, created_by)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $supplierId, $this->vendorId, 'PF-' . random_int(100000, 999999), $snapshot, $kind, $vatDeduction,
            $issue, $issue, $issue, $issue, $this->currencyId, $isFixedAsset,
            $without, round($with - $without, 2), $with, $deduct, $status, $paidAt, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE purchase_invoices SET vat_deduction_percent = ? WHERE id = ?')
            ->execute([$vatDeductionPercent, $id]);
        return $id;
    }

    protected function classifyOverride(int $supplierId, string $sourceType, int $sourceId, string $bucket): void
    {
        $col = $sourceType === 'bank' ? 'bank_transaction_id' : 'cash_document_id';
        $this->db->pdo()->prepare(
            "INSERT INTO de_movement_classification (supplier_id, source_type, {$col}, tax_bucket, classified_by)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$supplierId, $sourceType, $sourceId, $bucket, $this->userId]);
    }

    // ── asserts / lookups ────────────────────────────────────────────────────

    /** Počet řádků deníku pro daný source_type v build() výstupu. */
    protected function countRows(array $result, ?string $sourceType = null, ?int $sourceId = null): int
    {
        $n = 0;
        foreach ($result['rows'] as $row) {
            if ($sourceType !== null && $row['source_type'] !== $sourceType) {
                continue;
            }
            if ($sourceId !== null && (int) $row['source_id'] !== $sourceId) {
                continue;
            }
            $n++;
        }
        return $n;
    }
}
