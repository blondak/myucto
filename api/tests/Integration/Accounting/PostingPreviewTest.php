<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Náhled kontace před zaúčtováním dokladu.
 *
 * Do jeho zavedení se doklad zaúčtoval rovnou po `confirm()` a uživatel návrh nikdy
 * neviděl. U dokladu v cizí měně nebo v přenesené daňové povinnosti má zápis víc než
 * dva řádky, takže slepé odklepnutí je přesně ten úkon, po kterém se chyba hledá
 * zpětně v deníku.
 *
 * Testy hlídají to podstatné: že náhled NIC NEZAPÍŠE a že vrací TYTÉŽ řádky, které
 * zaúčtování opravdu vyrobí. Náhled, který se s výsledkem rozejde, je horší než žádný —
 * uživatel by potvrzoval něco jiného, než co vznikne.
 */
#[Group('integration')]
final class PostingPreviewTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private JournalAction $action;
    private \MyInvoice\Service\Accounting\PostingService $posting;

    private int $supplierId = 0;
    private int $vendorId = 0;
    private int $userId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db      = $c->get(Connection::class);
            $this->action  = $c->get(JournalAction::class);
            $this->posting = $c->get(\MyInvoice\Service\Accounting\PostingService::class);
            $seeder        = $c->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if (in_array(0, [$source, $this->userId, $this->currencyId, $czId, $this->vatRateId], true)) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $seeder->seedForSupplier($this->supplierId);

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email,
                                  language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Dodavatel", "Test 1", "Praha", "11000", ?, "v@example.com", "cs", ?, 0, 1)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /** Náhled vrátí vyrovnané řádky s názvy účtů — uživatel nepotvrzuje holá čísla. */
    public function testPreviewReturnsBalancedLinesWithAccountNames(): void
    {
        $id = $this->purchase(12_100.0);

        $data = $this->preview($id);

        self::assertTrue($data['balanced'], 'Návrh musí být vyrovnaný.');
        self::assertNotSame([], $data['lines']);
        self::assertNull($data['already_posted']);
        foreach ($data['lines'] as $line) {
            self::assertArrayHasKey('account_name', $line, 'Řádek nese název účtu.');
            self::assertContains($line['side'], ['debit', 'credit']);
        }
    }

    /** JÁDRO: náhled NIC nezapíše. Kdyby zapisoval, nebyl by to náhled. */
    public function testPreviewDoesNotWriteAnything(): void
    {
        $id = $this->purchase(12_100.0);
        $before = $this->entryCount();

        $this->preview($id);

        self::assertSame($before, $this->entryCount(), 'Náhled nesmí založit zápis v deníku.');
        $booked = $this->db->pdo()->prepare('SELECT booked_at FROM purchase_invoices WHERE id = ?');
        $booked->execute([$id]);
        self::assertNull($booked->fetchColumn(), 'Náhled nesmí označit doklad za zaúčtovaný.');
    }

    /**
     * Náhled a skutečné zaúčtování musí dát TYTÉŽ řádky.
     *
     * Kdyby si náhled počítal vlastní návrh, uživatel by potvrzoval něco jiného, než
     * co vznikne — a rozdíl by se našel až zpětně v deníku.
     */
    public function testPreviewMatchesWhatPostingActuallyProduces(): void
    {
        $id = $this->purchase(12_100.0);

        $previewLines = array_map(
            static fn (array $l): string => $l['account_code'] . '|' . $l['side'] . '|' . number_format($l['amount'], 2, '.', ''),
            $this->preview($id)['lines'],
        );
        $realLines = array_map(
            static fn (array $l): string => $l['account_code'] . '|' . $l['side'] . '|' . number_format((float) $l['amount'], 2, '.', ''),
            $this->posting->buildFromPurchaseInvoice($this->supplierId, $id),
        );

        self::assertSame($realLines, $previewLines);
    }

    /** Doklad ve stavu, který zaúčtovat nelze, vrátí chybu — ne prázdný návrh. */
    public function testPreviewReportsNonPostableDocument(): void
    {
        $id = $this->purchase(12_100.0);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'draft' WHERE id = ?")->execute([$id]);

        $res = $this->call($id);

        self::assertSame(422, $res->getStatusCode());
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function purchase(float $withVat): int
    {
        $vat = round($withVat - $withVat / 1.21, 2);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, "PP-1", "invoice", "2026-03-01", "2026-03-01", "2026-03-15", "2026-03-01",
                     ?, 0, "{}", ?, ?, ?, "received", "1", "full", ?)'
        )->execute([
            $this->supplierId, $this->vendorId, $this->currencyId,
            round($withVat - $vat, 2), $vat, $withVat, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();

        // Bez položek nemá doklad co zaúčtovat — builder by vrátil prázdný návrh.
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Služby", 1, ?, ?, 21.00, ?, ?, ?, 1)'
        )->execute([$id, round($withVat - $vat, 2), $this->vatRateId, round($withVat - $vat, 2), $vat, $withVat]);

        return $id;
    }

    /** @return array<string,mixed> */
    private function preview(int $docId): array
    {
        $res = $this->call($docId);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $res->getBody()->rewind();

        // Json::ok zapisuje payload PŘÍMO, bez obálky `data`.
        return (array) json_decode((string) $res->getBody(), true);
    }

    private function call(int $docId): Psr7Response
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/journal/posting-preview/purchase-invoices/' . $docId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);

        return ($this->action)->postingPreview($req, new Psr7Response(), [
            'source' => 'purchase-invoices',
            'id'     => (string) $docId,
        ]);
    }

    private function entryCount(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }
}
