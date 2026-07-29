<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Sample;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Sample\SampleDataGenerator;
use MyInvoice\Service\Sample\SampleDataService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Evidence + přesné odebrání ukázkových dat (issue #162) end-to-end:
 *
 *   1. generate() vytvoří sample a každou kořenovou entitu zaeviduje (sample_data_entries).
 *   2. guard: druhý generate nad neprázdnou DB selže (žádné duplicity / pád na UNIQUE).
 *   3. purge() smaže celou sample dávku FK-bezpečně (děti kaskádou, projects bez supplier_id).
 *
 * Izolace: throwaway dodavatel s vlastními měnami, úklid v tearDown. Soft-skip bez cfg.php/DB.
 */
#[Group('integration')]
final class SampleDataPurgeTest extends TestCase
{
    private Connection $db;
    private BankPostingService $bankPosting;
    private SampleDataGenerator $generator;
    private SampleDataService $service;

    private int $supplierId = 0;
    private int $adminId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db        = $c->get(Connection::class);
            $this->bankPosting = $c->get(BankPostingService::class);
            $this->generator = $c->get(SampleDataGenerator::class);
            $this->service   = $c->get(SampleDataService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->adminId = (int) $pdo->query(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
              WHERE r.system_key = 'superadmin' AND r.is_active = 1 AND u.is_active = 1
              ORDER BY u.id LIMIT 1"
        )->fetchColumn();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ'")->fetchColumn();
        $vatId = (int) $pdo->query("SELECT id FROM vat_rates WHERE code='CZ-21' LIMIT 1")->fetchColumn();
        $anyCur = (int) $pdo->query("SELECT id FROM currencies LIMIT 1")->fetchColumn();
        if (!$this->adminId || !$countryId || !$vatId || !$anyCur) {
            $this->markTestSkipped('Chybí předpoklady (admin/country/vat/currency).');
        }

        // Throwaway dodavatel + CZK/EUR měny (generátor je řeší per supplier_id+code).
        $pdo->prepare(
            "INSERT INTO supplier
                (company_name, street, city, zip, country_id, email, default_currency_id,
                 default_vat_rate_id, taxpayer_type, is_vat_payer)
             VALUES ('__SAMPLE_PURGE_TEST__ s.r.o.','Test 1','Praha','11000', ?,
                     'samplepurge@example.invalid', ?, ?, 'po', 1)"
        )->execute([$countryId, $anyCur, $vatId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $insCur = $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?,?,?,?,?,?,2,1,?)"
        );
        $insCur->execute([$this->supplierId, 'CZK', 'Kč', 'Kč', 'koruna', 'crown', 1]);
        $czk = (int) $pdo->lastInsertId();
        $insCur->execute([$this->supplierId, 'EUR', 'EUR', '€', 'euro', 'euro', 0]);
        $pdo->prepare("UPDATE supplier SET default_currency_id=? WHERE id=?")->execute([$czk, $this->supplierId]);
    }

    protected function tearDown(): void
    {
        if ($this->supplierId <= 0 || !isset($this->db)) return;
        $pdo = $this->db->pdo();
        try { $this->service->purge($this->supplierId); } catch (\Throwable) {}

        // POZOR: `SET FOREIGN_KEY_CHECKS=0` nevypne jen KONTROLU, ale i ON DELETE CASCADE.
        // Dřívější verze mazala supplier s vypnutými kontrolami, takže kaskáda nikdy
        // neproběhla a po každém běhu tu zůstalo ~220 osiřelých řádků účtové osnovy,
        // ~132 ai_jobs, číselné řady, období… Za 28 běhů se jich v ostré DB nasbíralo
        // přes 12 000 a shazovaly migrace, které do chart_of_accounts insertují.
        //
        // Správný postup (týž jako SettingsAction::deleteSupplier): FK kontrolu vypnout
        // JEN na tabulky s RESTRICT FK, které by DELETE zablokovaly, a vlastní
        // `DELETE FROM supplier` provést se ZAPNUTOU kontrolou, aby kaskáda doběhla.
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ([
                'sample_data_entries',
                'supplier_bank_accounts',
                'invoice_counters',
                'purchase_invoice_counters',
                'clients',
                'activity_log',
                'currencies',
            ] as $table) {
                $pdo->prepare("DELETE FROM `$table` WHERE supplier_id=?")->execute([$this->supplierId]);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        $pdo->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->supplierId]);
    }

    public function testGenerateTracksAndPurgeRemovesEverything(): void
    {
        $pdo = $this->db->pdo();

        $r = $this->generator->generate($this->supplierId, $this->adminId);
        self::assertSame(24, $r['clients']);
        self::assertSame(12, $r['vendors']);
        self::assertSame(120, $r['invoices']);
        self::assertSame(120, $r['purchase_invoices']);
        self::assertSame(120, $r['stock_documents']);
        self::assertSame(2, $r['assets']);
        self::assertSame(6, $r['bank_statements']);
        self::assertSame(120, $r['bank_transactions']);
        self::assertSame(104, $r['automation_auto']);
        self::assertSame(6, $r['automation_pending']);
        self::assertSame(1, $r['automation_needs_input']);
        self::assertSame(1, $r['automation_approved']);
        self::assertSame(1, $r['cash_registers']);
        self::assertSame(7, $r['cash_documents']);
        self::assertSame(3, $r['manufacturers']);
        self::assertSame(3, $r['eshop_categories']);
        self::assertGreaterThanOrEqual(375, $r['journal_entries']);
        self::assertTrue($r['accounting_enabled']);
        self::assertSame([], $r['warnings']);

        $tracked = (int) $pdo->query("SELECT COUNT(*) FROM sample_data_entries WHERE supplier_id={$this->supplierId}")->fetchColumn();

        $sum = $this->service->summary($this->supplierId);
        self::assertTrue($sum['has']);
        self::assertSame($tracked, $sum['total']);
        self::assertSame(120, $sum['counts']['invoice']);
        self::assertSame($r['journal_entries'], $sum['counts']['journal_entry']);

        self::assertSame(36, (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE supplier_id={$this->supplierId}")->fetchColumn());
        $supplierFlags = $pdo->query(
            "SELECT accounting_mode, stock_enabled FROM supplier WHERE id={$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('double_entry', $supplierFlags['accounting_mode']);
        self::assertSame(1, (int) $supplierFlags['stock_enabled']);
        $accountingFrom = (string) $pdo->query(
            "SELECT MIN(effective_from) FROM supplier_accounting_modes WHERE supplier_id={$this->supplierId}"
        )->fetchColumn();
        $oldestDocument = (string) $pdo->query(
            "SELECT MIN(document_date) FROM (
                SELECT issue_date AS document_date FROM invoices WHERE supplier_id={$this->supplierId}
                UNION ALL
                SELECT issue_date FROM purchase_invoices WHERE supplier_id={$this->supplierId}
            ) sample_documents"
        )->fetchColumn();
        self::assertLessThanOrEqual($oldestDocument, $accountingFrom);
        self::assertSame(
            ['1', '2'],
            $pdo->query("SELECT CAST(tax_group AS CHAR) FROM assets WHERE supplier_id={$this->supplierId} ORDER BY tax_group")
                ->fetchAll(PDO::FETCH_COLUMN),
        );
        self::assertSame(60, (int) $pdo->query(
            "SELECT COUNT(*) FROM invoice_payments WHERE supplier_id={$this->supplierId} AND bank_transaction_id IS NOT NULL"
        )->fetchColumn());
        self::assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM supplier_bank_accounts WHERE supplier_id={$this->supplierId}"
        )->fetchColumn());
        self::assertSame(2, (int) $pdo->query(
            "SELECT COUNT(*) FROM bank_posting_suggestions
              WHERE supplier_id={$this->supplierId} AND status='pending'
                AND operation_type='bank.payment.matched' AND debit_account_code='221' AND credit_account_code='311'"
        )->fetchColumn());
        self::assertSame(2, (int) $pdo->query(
            "SELECT COUNT(*) FROM bank_posting_suggestions
              WHERE supplier_id={$this->supplierId} AND status='pending'
                AND operation_type='bank.payment.matched' AND debit_account_code='321' AND credit_account_code='221'"
        )->fetchColumn());
        self::assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM bank_posting_suggestions
              WHERE supplier_id={$this->supplierId} AND status='pending'
                AND operation_type='bank.fee' AND debit_account_code='568' AND credit_account_code='221'"
        )->fetchColumn());
        self::assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM bank_posting_suggestions
              WHERE supplier_id={$this->supplierId} AND status='pending'
                AND operation_type='bank.interest' AND debit_account_code='221' AND credit_account_code='662'"
        )->fetchColumn());
        self::assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM bank_posting_suggestions bps
               JOIN journal_entries je
                 ON je.supplier_id=bps.supplier_id
                AND je.id=CAST(SUBSTRING_INDEX(bps.note, '#', -1) AS UNSIGNED)
              WHERE bps.supplier_id={$this->supplierId} AND bps.status='needs_input'
                AND bps.note LIKE 'duplicate_suspect:#%' AND je.source_type='manual'"
        )->fetchColumn());
        $pendingSuggestionIds = array_map('intval', $pdo->query(
            "SELECT id FROM bank_posting_suggestions
              WHERE supplier_id={$this->supplierId} AND status='pending' ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN));
        self::assertCount(6, $pendingSuggestionIds);
        foreach ($pendingSuggestionIds as $suggestionId) {
            $preview = $this->bankPosting->previewSuggestion($this->supplierId, $suggestionId);
            self::assertCount(2, $preview['lines']);
        }
        self::assertGreaterThan(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM activity_log WHERE supplier_id={$this->supplierId}"
        )->fetchColumn());
        self::assertNull($pdo->query(
            "SELECT account_number FROM currencies WHERE supplier_id={$this->supplierId} AND code='CZK'"
        )->fetchColumn());
        self::assertSame(7, (int) $pdo->query(
            "SELECT COUNT(*) FROM cash_documents WHERE supplier_id={$this->supplierId} AND status='posted' AND journal_entry_id IS NOT NULL"
        )->fetchColumn());
        self::assertGreaterThan(0.0, (float) $pdo->query(
            "SELECT SUM(CASE WHEN doc_type='in' THEN total_amount ELSE -total_amount END)
               FROM cash_documents WHERE supplier_id={$this->supplierId}"
        )->fetchColumn());
        self::assertSame(12, (int) $pdo->query(
            "SELECT COUNT(*) FROM stock_items
              WHERE supplier_id={$this->supplierId} AND manufacturer_id IS NOT NULL AND export_eshop=1"
        )->fetchColumn());
        self::assertSame(12, (int) $pdo->query(
            "SELECT COUNT(*) FROM stock_item_categories WHERE supplier_id={$this->supplierId} AND is_primary=1"
        )->fetchColumn());

        $unbalanced = (int) $pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT je.id,
                       SUM(CASE WHEN jel.side='debit' THEN jel.amount ELSE 0 END) md,
                       SUM(CASE WHEN jel.side='credit' THEN jel.amount ELSE 0 END) d
                  FROM journal_entries je
                  JOIN journal_entry_lines jel ON jel.entry_id=je.id AND jel.supplier_id=je.supplier_id
                 WHERE je.supplier_id={$this->supplierId}
              GROUP BY je.id
                HAVING ROUND(md,2) <> ROUND(d,2)
            ) x"
        )->fetchColumn();
        self::assertSame(0, $unbalanced);

        $del = $this->service->purge($this->supplierId);
        self::assertSame(36, $del['clients']);
        self::assertSame(120, $del['purchase_invoices']);
        self::assertSame($r['journal_entries'], $del['journal_entries']);
        self::assertSame(120, $del['stock_documents']);
        self::assertSame(1, $del['bank_accounts']);
        self::assertSame(7, $del['cash_documents']);
        self::assertSame(1, $del['cash_registers']);
        self::assertSame(3, $del['manufacturers']);
        self::assertSame(3, $del['eshop_categories']);
        self::assertGreaterThan(0, $del['activity_logs']);

        // Po purge je vše pryč.
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM journal_entries WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM bank_statements WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM supplier_bank_accounts WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM activity_log WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM assets WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM stock_items WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM stock_levels WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM cash_documents WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM cash_registers WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM manufacturers WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM stock_categories WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM cars WHERE supplier_id={$this->supplierId}")->fetchColumn());

        // Polymorfní přívěsky bez FK (entity_type/entity_id) — smazáním dokladu nezmizí,
        // takže je purge musí uklidit sám. AI joby i korekce visí i na bank_transaction,
        // které se needviduje jednotlivě (patří pod výpis) → cesta přes bank_statements.
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM ai_jobs WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM accounting_corrections WHERE supplier_id={$this->supplierId}")->fetchColumn());

        // Čítače číselných řad: po odebrání ukázek nesmí zůstat vyhnané nahoru, jinak
        // první REÁLNÁ faktura naváže číslováním až za smazanými ukázkovými doklady.
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM invoice_counters WHERE supplier_id={$this->supplierId}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM purchase_invoice_counters WHERE supplier_id={$this->supplierId}")->fetchColumn());

        self::assertFalse($this->service->hasSampleData($this->supplierId));
    }

    public function testSecondGenerateIsBlockedByGuard(): void
    {
        $pdo = $this->db->pdo();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2='CZ'")->fetchColumn();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE supplier_id={$this->supplierId} AND code='CZK'")->fetchColumn();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, "Existující data", "Test 1", "Praha", "11000", ?, "existing@example.invalid", ?)'
        )->execute([$this->supplierId, $countryId, $currencyId]);

        $this->expectException(\RuntimeException::class);
        $this->generator->generate($this->supplierId, $this->adminId);
    }

    public function testNonVatSoleTraderKeepsTaxEvidenceAndStockDisabled(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "UPDATE supplier
                SET taxpayer_type='fo', is_vat_payer=0, accounting_mode='tax_evidence', stock_enabled=0
              WHERE id=?"
        )->execute([$this->supplierId]);

        $result = $this->generator->generate($this->supplierId, $this->adminId);
        self::assertFalse($result['accounting_enabled']);
        self::assertSame(120, $result['invoices']);
        self::assertSame(120, $result['purchase_invoices']);
        self::assertSame(0, $result['stock_documents']);
        self::assertSame(0, $result['assets']);
        self::assertSame(0, $result['bank_statements']);
        self::assertSame(1, $result['cash_registers']);
        self::assertSame(7, $result['cash_documents']);
        self::assertSame(0, $result['manufacturers']);
        self::assertSame(0, $result['eshop_categories']);
        self::assertSame(0, $result['journal_entries']);

        $flags = $pdo->query(
            "SELECT accounting_mode, stock_enabled FROM supplier WHERE id={$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('tax_evidence', $flags['accounting_mode']);
        self::assertSame(0, (int) $flags['stock_enabled']);

        $deleted = $this->service->purge($this->supplierId);
        self::assertSame(7, $deleted['cash_documents']);
        self::assertSame(1, $deleted['cash_registers']);
        self::assertFalse($this->service->hasSampleData($this->supplierId));
    }
}
