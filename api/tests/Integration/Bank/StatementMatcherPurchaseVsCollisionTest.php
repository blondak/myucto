<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * BUG 3: kolize interního varsymbolu s čísly dokladů dodavatelů.
 *
 * `matchPurchase()` hledá kandidáty i přes `CAST(REGEXP_REPLACE(varsymbol,'[^0-9]',''))`,
 * takže interní varsymbol s písmenným prefixem („PF-2099-9042") se srovná na holé číslo,
 * které může být `vendor_invoice_number` úplně jiné faktury. Matcher pak viděl dva
 * kandidáty, vrátil `ambiguous_vs_purchase` a propadl na slabší shodu podle částky a data.
 * Riziko roste s objemem — `ensureVarsymbol()` generuje další interní varsymbol při
 * každém překlopení draft → received.
 *
 * Oprava upřednostní kandidáta s DOSLOVNOU shodou čísla dokladu před tím, kterého trefila
 * až normalizace na číslice. Dvě doslovné shody zůstávají nejednoznačné (druhý test).
 *
 * Izolace: rok 2099, vlastní doklady + výpis, úklid v tearDown.
 */
#[Group('integration')]
final class StatementMatcherPurchaseVsCollisionTest extends TestCase
{
    private Connection $db;
    private StatementMatcher $matcher;
    private int $supplierId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private string $account = '';
    private ?string $bankCode = null;

    private int $statementId = 0;
    private int $transactionId = 0;

    private const FILE_MARKER = '__vscollision2099__';
    /** VS, který banka pošle v odchozí platbě — číslice bez prefixu. */
    private const BANK_VS = '20999042';
    /** Interní varsymbol s prefixem; po normalizaci na číslice koliduje s BANK_VS. */
    private const INTERNAL_VS = 'PF-2099-9042';
    private const OTHER_INTERNAL_VS = 'PF-2099-9099';
    private const AMOUNT = 5432.10;
    private const DECOY_AMOUNT = 999.00;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->matcher = new StatementMatcher($this->db, $c->get(FinalFromProformaCreator::class), null);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $cur = $pdo->query(
            "SELECT id, supplier_id, account_number, bank_code FROM currencies
              WHERE code = 'CZK' AND account_number IS NOT NULL AND account_number <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            $this->markTestSkipped('Chybí CZK currency s account_number.');
        }
        $this->currencyId = (int) $cur['id'];
        $this->supplierId = (int) $cur['supplier_id'];
        $this->account = (string) $cur['account_number'];
        $this->bankCode = $cur['bank_code'] !== null ? (string) $cur['bank_code'] : null;

        $this->vendorId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vendorId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí client/user pro supplier.');
        }

        $this->cleanup();
        $this->seedStatement();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $vsList = [self::INTERNAL_VS, self::OTHER_INTERNAL_VS, self::BANK_VS];
        $ph = implode(',', array_fill(0, count($vsList), '?'));
        $pdo->prepare(
            "DELETE pm FROM payment_matches pm
               JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id
              WHERE pi.supplier_id = ? AND pi.varsymbol IN ($ph)"
        )->execute([$this->supplierId, ...$vsList]);
        $pdo->prepare("DELETE FROM bank_statements WHERE file_name LIKE ?")->execute(['%' . self::FILE_MARKER . '%']);
        $pdo->prepare("DELETE FROM purchase_invoices WHERE supplier_id = ? AND varsymbol IN ($ph)")
            ->execute([$this->supplierId, ...$vsList]);
        $this->statementId = $this->transactionId = 0;
    }

    /**
     * Jádro nálezu: platba nese číslo dokladu DODAVATELE. Interní varsymbol jiné faktury
     * se na totéž číslo srovná až po odstranění prefixu — to je slabší shoda a nesmí
     * z párování udělat nejednoznačnost.
     */
    public function testExactVendorNumberWinsOverNormalizedInternalVarsymbol(): void
    {
        // Kolizní doklad: interní varsymbol „PF-2099-9042" → číslice 20999042 = VS z banky.
        // Číslo dokladu dodavatele má vlastní, nekolidující řadu.
        $decoyId = $this->seedPurchase(self::INTERNAL_VS, 'FV-2099-777', self::DECOY_AMOUNT);
        // Skutečný cíl platby: číslo dokladu dodavatele se shoduje doslova.
        $targetId = $this->seedPurchase(self::OTHER_INTERNAL_VS, self::BANK_VS, self::AMOUNT);

        $res = $this->matcher->match($this->transactionId);

        self::assertSame('auto_exact', $res['status'] ?? null,
            'Doslovná shoda vendor_invoice_number musí přebít kolizi přes normalizovaný interní varsymbol.');
        self::assertSame($targetId, $res['purchase_invoice_id'] ?? null);

        $pdo = $this->db->pdo();
        self::assertSame('paid', $pdo->query("SELECT status FROM purchase_invoices WHERE id = {$targetId}")->fetchColumn());
        self::assertSame('received', $pdo->query("SELECT status FROM purchase_invoices WHERE id = {$decoyId}")->fetchColumn(),
            'Kolizní doklad se nesmí dotknout.');
        self::assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM payment_matches
              WHERE bank_transaction_id = {$this->transactionId} AND purchase_invoice_id = {$targetId}"
        )->fetchColumn());
    }

    /**
     * Protipól: dvě DOSLOVNÉ shody (náš interní varsymbol × číslo dokladu dodavatele)
     * jsou skutečná nejednoznačnost — přednost nemá čemu vzniknout a matcher nesmí hádat.
     */
    public function testTwoLiteralMatchesStayAmbiguous(): void
    {
        $ourVsId = $this->seedPurchase(self::BANK_VS, 'FV-2099-778', self::AMOUNT);
        $vendorVsId = $this->seedPurchase(self::OTHER_INTERNAL_VS, self::BANK_VS, self::AMOUNT);

        $res = $this->matcher->match($this->transactionId);

        // Shoda VS zůstane nejednoznačná (a doklad propadne dál na slabší shody, které
        // taky nerozhodnou) — podstatné je, že se ŽÁDNÝ z dokladů automaticky neuhradí.
        self::assertSame('unmatched', $res['status'] ?? null);
        $pdo = $this->db->pdo();
        self::assertSame(
            ['received', 'received'],
            $pdo->query("SELECT status FROM purchase_invoices WHERE id IN ({$ourVsId}, {$vendorVsId}) ORDER BY id")
                ->fetchAll(PDO::FETCH_COLUMN),
            'Dvě doslovné shody nesmí matcher rozseknout hádáním.',
        );
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM payment_matches WHERE bank_transaction_id = {$this->transactionId}"
        )->fetchColumn());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seedPurchase(string $varsymbol, string $vendorNumber, float $amount): int
    {
        $d = '2099-06-15';
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, vendor_snapshot,
                 total_without_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, 'invoice', ?, ?, ?, ?, ?, '{}', ?, ?, 'received', ?)"
        )->execute([
            $this->supplierId, $this->vendorId, $varsymbol, $vendorNumber,
            $d, $d, $d, $d, $this->currencyId, $amount, $amount, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function seedStatement(): void
    {
        $pdo = $this->db->pdo();
        $d = '2099-06-15';
        $pdo->prepare(
            "INSERT INTO bank_statements
                (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, 'CZK', ?)"
        )->execute([
            self::FILE_MARKER . '.gpc',
            hash('sha256', self::FILE_MARKER . self::BANK_VS),
            $this->account, $this->bankCode, $d,
        ]);
        $this->statementId = (int) $pdo->lastInsertId();

        // ODCHOZÍ platba (záporná) pod VS dodavatele → matchPurchase().
        $pdo->prepare(
            "INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, variable_symbol)
             VALUES (?, ?, ?, 'CZK', ?)"
        )->execute([$this->statementId, $d, -self::AMOUNT, self::BANK_VS]);
        $this->transactionId = (int) $pdo->lastInsertId();
    }
}
