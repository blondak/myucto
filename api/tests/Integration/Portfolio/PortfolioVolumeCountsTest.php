<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Portfolio;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Portfolio\PortfolioAggregationService;
use MyInvoice\Service\Portfolio\PortfolioVolumeCounter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Objem dat v přehledu firem: počty vydaných/přijatých faktur, bankovních výpisů
 * a pohybů, pokladních dokladů a zápisů deníku per firma.
 *
 * Tři syntetické firmy v transakci (rollback v tearDown): A a B jsou v membershipu
 * uživatele, C je cizí firma plná dokladů — do přehledu ani do počtů A/B se nesmí
 * promítnout. U A jsou i doklady, které se počítat nemají (koncepty, stornované,
 * proforma, zálohová PF, koncept pokladního dokladu), a legacy výpis bez supplier_id,
 * který A patří jen přes číslo účtu.
 */
#[Group('integration')]
final class PortfolioVolumeCountsTest extends TestCase
{
    private const LEGACY_ACCOUNT = '3000000004';
    private const BANK_CODE = '0100';

    private Connection $db;
    private PortfolioAggregationService $portfolio;
    private bool $inTx = false;

    private int $czId = 0;
    private int $vatRateId = 0;
    private int $anyCurrencyId = 0;
    private int $creatorId = 0;

    /** @var array<int, array{currency:int, client:int}> */
    private array $refs = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->portfolio = $c->get(PortfolioAggregationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'roles'")->fetchColumn() === false) {
            $this->markTestSkipped('Dynamické role chybí — spusť api/bin/migrate.php.');
        }
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->anyCurrencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->creatorId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->czId === 0 || $this->vatRateId === 0 || $this->anyCurrencyId === 0 || $this->creatorId === 0) {
            $this->markTestSkipped('Chybí základní data (country/vat_rate/currency/user) v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        $this->inTx = false;
    }

    public function testOverviewCountsVolumePerVisibleCompanyOnly(): void
    {
        $a = $this->supplier('__TEST PORTFOLIO objem A', 'double_entry', self::LEGACY_ACCOUNT);
        $b = $this->supplier('__TEST PORTFOLIO objem B', 'tax_evidence');
        $foreign = $this->supplier('__TEST PORTFOLIO objem cizí', 'double_entry');

        // A: vydané — 3 počítané + koncept, storno a proforma, které se nepočítají.
        $this->invoice($a, 'invoice', 'issued');
        $this->invoice($a, 'credit_note', 'paid');
        $this->invoice($a, 'tax_document', 'sent');
        $this->invoice($a, 'invoice', 'draft');
        $this->invoice($a, 'invoice', 'cancelled');
        $this->invoice($a, 'proforma', 'issued');
        // A: přijaté — 2 počítané + koncept, storno a zálohová.
        $this->purchase($a, 'invoice', 'received');
        $this->purchase($a, 'receipt', 'booked');
        $this->purchase($a, 'invoice', 'draft');
        $this->purchase($a, 'invoice', 'cancelled');
        $this->purchase($a, 'advance', 'paid');
        // A: výpis se supplier_id (2 pohyby) + legacy výpis bez supplier_id (1 pohyb).
        $this->statement($a, 2);
        $this->statement(null, 1, self::LEGACY_ACCOUNT);
        // A: pokladna — zaúčtovaný a stornovaný se počítají, koncept ne.
        $register = $this->register($a);
        $this->cashDocument($a, $register, 'posted');
        $this->cashDocument($a, $register, 'reversed');
        $this->cashDocument($a, $register, 'draft');
        // A: deník.
        $period = $this->period($a);
        $this->journalEntry($a, $period);
        $this->journalEntry($a, $period);

        // B: jediná vydaná faktura, jinak nic.
        $this->invoice($b, 'invoice', 'paid');

        // Cizí firma: od všeho něco — nesmí se nikde objevit.
        $this->invoice($foreign, 'invoice', 'issued');
        $this->purchase($foreign, 'invoice', 'received');
        $this->statement($foreign, 3);
        $this->cashDocument($foreign, $this->register($foreign), 'posted');
        $this->journalEntry($foreign, $this->period($foreign));

        $userId = $this->user();
        $this->assign($userId, [$a, $b]);

        $res = $this->portfolio->overview($userId, false, new \DateTimeImmutable());
        $byId = [];
        foreach ($res['companies'] as $row) {
            $byId[$row['supplier_id']] = $row['volume'];
        }

        self::assertSame([$a, $b], array_values(array_intersect([$a, $b], array_keys($byId))));
        self::assertArrayNotHasKey($foreign, $byId, 'Cizí firma nesmí být v přehledu.');
        self::assertCount(2, $byId);

        self::assertSame([
            'issued_invoices'   => 3,
            'purchase_invoices' => 2,
            'bank_statements'   => 2,
            'bank_transactions' => 3,
            'cash_documents'    => 2,
            'journal_entries'   => 2,
        ], $byId[$a]);

        self::assertSame([
            'issued_invoices'   => 1,
            'purchase_invoices' => 0,
            'bank_statements'   => 0,
            'bank_transactions' => 0,
            'cash_documents'    => 0,
            'journal_entries'   => 0,
        ], $byId[$b]);
    }

    public function testCounterReturnsOnlyRequestedCompanies(): void
    {
        $a = $this->supplier('__TEST PORTFOLIO counter A', 'tax_evidence');
        $foreign = $this->supplier('__TEST PORTFOLIO counter cizí', 'tax_evidence');
        $this->invoice($a, 'invoice', 'issued');
        $this->invoice($foreign, 'invoice', 'issued');
        $this->statement($foreign, 1);

        $counts = (new PortfolioVolumeCounter($this->db))->countsFor([$a]);

        self::assertSame([$a], array_keys($counts));
        self::assertSame(1, $counts[$a]['issued_invoices']);
        self::assertSame(0, $counts[$a]['bank_statements']);
        self::assertSame([], (new PortfolioVolumeCounter($this->db))->countsFor([]));
    }

    private function supplier(string $name, string $mode, ?string $account = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO supplier (company_name, display_name, street, city, zip, country_id, email,
                                   default_currency_id, default_vat_rate_id, accounting_mode, is_vat_payer)
             VALUES (?, ?, 'Testovací 1', 'Brno', '60200', ?, 'portfolio@example.invalid', ?, ?, ?, 0)"
        )->execute([$name, $name, $this->czId, $this->anyCurrencyId, $this->vatRateId, $mode]);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                                     account_number, bank_code)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1, ?, ?)"
        )->execute([$id, $account, $account !== null ? self::BANK_CODE : null]);
        $currency = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currency, $id]);

        $pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id)
             VALUES (?, 'Syntetický partner s.r.o.', 'Partnerská 2', 'Praha', '11000', ?, ?)"
        )->execute([$id, $this->czId, $currency]);

        $this->refs[$id] = ['currency' => $currency, 'client' => (int) $pdo->lastInsertId()];
        return $id;
    }

    private function invoice(int $supplierId, string $type, string $status): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO invoices (supplier_id, invoice_type, status, client_id, issue_date, due_date, currency_id)
             VALUES (?, ?, ?, ?, '2026-03-01', '2026-03-15', ?)"
        )->execute([$supplierId, $type, $status, $this->refs[$supplierId]['client'], $this->refs[$supplierId]['currency']]);
    }

    private function purchase(int $supplierId, string $kind, string $status): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, document_kind, status,
                                            issue_date, due_date, received_at, currency_id, vendor_snapshot, created_by)
             VALUES (?, ?, ?, ?, ?, '2026-03-01', '2026-03-15', '2026-03-02', ?, '{}', ?)"
        )->execute([
            $supplierId, $this->refs[$supplierId]['client'], 'PF-' . bin2hex(random_bytes(4)), $kind, $status,
            $this->refs[$supplierId]['currency'], $this->creatorId,
        ]);
    }

    private function statement(?int $supplierId, int $transactions, string $account = '9999999999'): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, statement_date)
             VALUES (?, 'syntetic.gpc', ?, ?, ?, '2026-03-31')"
        )->execute([$supplierId, bin2hex(random_bytes(32)), $account, self::BANK_CODE]);
        $statementId = (int) $pdo->lastInsertId();

        $tx = $pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount) VALUES (?, '2026-03-10', ?)");
        for ($i = 1; $i <= $transactions; $i++) {
            $tx->execute([$statementId, 100 * $i]);
        }
    }

    private function register(int $supplierId): int
    {
        $this->db->pdo()->prepare("INSERT INTO cash_registers (supplier_id, name) VALUES (?, 'Syntetická pokladna')")
            ->execute([$supplierId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function cashDocument(int $supplierId, int $registerId, string $status): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO cash_documents (supplier_id, register_id, doc_number, doc_type, purpose, issue_date,
                                         description, total_amount, status)
             VALUES (?, ?, ?, 'in', 'other', '2026-03-05', 'Syntetický doklad', 100.00, ?)"
        )->execute([$supplierId, $registerId, $status === 'draft' ? null : 'PPD-' . bin2hex(random_bytes(4)), $status]);
    }

    private function period(int $supplierId): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on) VALUES (?, 2026, '2026-01-01', '2026-12-31')"
        )->execute([$supplierId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function journalEntry(int $supplierId, int $periodId): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, description) VALUES (?, ?, '2026-03-05', 'Syntetický zápis')"
        )->execute([$supplierId, $periodId]);
    }

    private function user(): int
    {
        $pdo = $this->db->pdo();
        $role = $pdo->prepare('SELECT id FROM roles WHERE system_key = ?');
        $role->execute(['accountant']);
        $pdo->prepare(
            "INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
             VALUES (?, '\$2y\$10\$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', '__TEST Portfolio objem', ?, 'cs', 1)"
        )->execute(['__test_portfolio_volume_' . bin2hex(random_bytes(6)) . '@example.com', (int) $role->fetchColumn()]);
        return (int) $pdo->lastInsertId();
    }

    /** @param list<int> $supplierIds */
    private function assign(int $userId, array $supplierIds): void
    {
        $ins = $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, NULL)');
        foreach ($supplierIds as $sid) {
            $ins->execute([$userId, $sid]);
        }
    }
}
