<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use DateTimeImmutable;
use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Force-edit zaúčtované přijaté faktury v OTEVŘENÉM období, kde se změnilo jen DUZP.
 *
 * Díra, kterou to zavírá: o přeúčtování deníku rozhoduje
 * `UpdatePurchaseInvoiceAction::financialFieldsChanged()`, která porovnává TĚLO
 * REQUESTU proti uloženému dokladu. Kurz ale mění SERVER — v těle requestu nová
 * hodnota nebyla, takže deníku zůstaly korunové částky přepočtené starým kurzem,
 * zatímco doklad už nesl nový. Doklad × deník se rozešly beze stopy.
 *
 * Soft-skip bez cfg.php; vše v transakci s rollbackem.
 */
#[Group('integration')]
final class PurchaseRateRepostForceEditTest extends TestCase
{
    private const OLD_RATE = 24.000000;
    private const NEW_RATE = 30.000000;
    /** Základ v EUR — po přepočtu novým kurzem musí částka v deníku vyskočit. */
    private const BASE_EUR = 100.0;

    private ContainerInterface $container;
    private Connection $db;
    private PostingService $posting;
    private JournalEntryRepository $journal;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $vatRateId = 0;
    private int $eurId = 0;
    private int $vendorId = 0;
    private string $oldTaxDate = '';
    private string $newTaxDate = '';
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db      = $this->container->get(Connection::class);
            $this->posting = $this->container->get(PostingService::class);
            $this->journal = $this->container->get(JournalEntryRepository::class);
            $periods       = $this->container->get(AccountingPeriodRepository::class);
            $seeder        = $this->container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId             = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        // Dvě data v témže (aktuálním, otevřeném) roce — přesun mezi obdobími se tu netestuje.
        $year = (int) date('Y');
        $this->oldTaxDate = $year . '-03-10';
        $this->newTaxDate = $year . '-03-20';

        $pdo->beginTransaction();
        $this->inTx = true;

        $seeder->seedForSupplier($this->supplierId);
        if ($periods->findByYear($this->supplierId, $year) === null) {
            $periods->create($this->supplierId, $year, $year . '-01-01', $year . '-12-31');
        }
        $pdo->prepare('UPDATE accounting_supplier_settings SET locked_until = NULL WHERE supplier_id = ?')
            ->execute([$this->supplierId]);

        $cur = $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active)
             VALUES (?, "EUR", "EUR repost", "€", "euro", "euro", 2, 1)'
        );
        $cur->execute([$this->supplierId]);
        $this->eurId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_vendor, is_vat_payer)
             VALUES (?, "Repost dodavatel s.r.o.", "Test 1", "Praha", "11000", ?, "repost@example.com", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, $czId, $this->eurId]);
        $this->vendorId = (int) $pdo->lastInsertId();
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

    public function testTaxDateOnlyChangeReloadsRateAndRepostsJournal(): void
    {
        $id = $this->postedEurPurchase();
        $entryBefore = $this->journal->findBySource($this->supplierId, 'purchase_invoice', $id);
        self::assertNotNull($entryBefore, 'Doklad musí být zaúčtovaný, jinak test netestuje nic.');
        $amountBefore = $this->maxLineAmount((int) $entryBefore['id']);
        self::assertGreaterThan(0.0, $amountBefore);

        $result = $this->forceUpdate($id, $this->newTaxDate);

        self::assertSame(200, $result['status'], json_encode($result['body']));

        $row = $this->rateRow($id);
        self::assertEqualsWithDelta(self::NEW_RATE, (float) $row['exchange_rate'], 1e-6,
            'Kurz se k novému DUZP přenačetl.');

        self::assertArrayHasKey('_repost', $result['body'],
            'Server kurz přepsal → deník se MUSÍ přeúčtovat, jinak nese staré korunové částky.');

        $entryAfter = $this->journal->findBySource($this->supplierId, 'purchase_invoice', $id);
        self::assertNotNull($entryAfter);
        $amountAfter = $this->maxLineAmount((int) $entryAfter['id']);
        self::assertGreaterThan($amountBefore + 1.0, $amountAfter,
            'Korunové částky v deníku musí odpovídat novému kurzu.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────

    private function postedEurPurchase(): int
    {
        $pdo = $this->db->pdo();
        $with = self::BASE_EUR;
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_is_vat_payer, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate,
                 exchange_rate_date, exchange_rate_source, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, 1, ?, "invoice", ?, ?, ?, ?, ?, ?, ?, "cnb", 0, "{}", ?, 0, ?, "received", "40", "full", ?)'
        )->execute([
            $this->supplierId, $this->vendorId, 'REPOST-' . bin2hex(random_bytes(3)),
            $this->oldTaxDate, $this->oldTaxDate, $this->oldTaxDate, $this->oldTaxDate,
            $this->eurId, self::OLD_RATE, $this->oldTaxDate, $with, $with, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code)
             VALUES (?, "Služba", 1, "ks", ?, ?, 0, ?, 0, ?, 0, "40")'
        )->execute([$id, $with, $this->vatRateId, $with, $with]);

        $lines = $this->posting->buildFromPurchaseInvoice($this->supplierId, $id);
        $this->posting->postDocument($this->supplierId, 'purchase_invoice', $id, $lines, [
            'entry_date' => $this->oldTaxDate,
            'document_date' => $this->oldTaxDate,
            'posted_by' => $this->userId,
            'user_id' => $this->userId,
        ]);

        return $id;
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function forceUpdate(int $id, string $taxDate): array
    {
        $cnb = $this->createStub(CnbExchangeRateClient::class);
        $cnb->method('getRate')->willReturnCallback(
            static fn (string $code, DateTimeImmutable $date): array => [
                'rate' => self::NEW_RATE, 'rate_date' => $date->format('Y-m-d'),
                'fallback_used' => false, 'source' => 'fresh',
            ]
        );
        $this->container->set(CnbExchangeRateClient::class, $cnb);
        $action = $this->container->get(UpdatePurchaseInvoiceAction::class);

        // Tělo přesně jako z editoru: kurzová pole nese, ale se STAROU hodnotou —
        // novou dosadí až server.
        $body = [
            'vendor_id' => $this->vendorId,
            'vendor_invoice_number' => 'REPOST-UPDATED',
            'document_kind' => 'invoice',
            'issue_date' => $this->oldTaxDate,
            'tax_date' => $taxDate,
            'due_date' => $this->oldTaxDate,
            'currency_id' => $this->eurId,
            'exchange_rate' => self::OLD_RATE,
            'exchange_rate_date' => $this->oldTaxDate,
            'exchange_rate_source' => 'cnb',
            'vat_classification_code' => '40',
            'items' => [[
                'description' => 'Služba', 'quantity' => 1, 'unit' => 'ks',
                'unit_price_without_vat' => self::BASE_EUR, 'vat_rate_id' => $this->vatRateId,
                'vat_classification_code' => '40',
            ]],
        ];

        $req = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/purchase-invoices/' . $id)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withQueryParams(['force' => '1'])
            ->withParsedBody($body);

        $resp = $action($req, new Psr7Response(), ['id' => (string) $id]);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);

        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function maxLineAmount(int $entryId): float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT MAX(amount) FROM journal_entry_lines WHERE entry_id = ? AND supplier_id = ?'
        );
        $stmt->execute([$entryId, $this->supplierId]);

        return (float) ($stmt->fetchColumn() ?: 0.0);
    }

    /** @return array<string,mixed> */
    private function rateRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT exchange_rate, exchange_rate_date, exchange_rate_source
               FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $this->supplierId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
