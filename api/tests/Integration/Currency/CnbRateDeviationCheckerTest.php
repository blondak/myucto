<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Currency;

use DateTimeImmutable;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use MyInvoice\Service\Currency\CnbRateDeviationChecker;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Featura C (private/REAL_data_followup_UX.md) — validace kurzu na dokladu proti
 * dennímu kurzu ČNB. Vzor {@see FixedExchangeRateTest}/{@see ExchangeRateApplierDuzpTest}:
 * stubovaný {@see CnbExchangeRateClient} (bez síťového volání), reálná DB v transakci
 * s rollbackem v tearDown.
 */
#[Group('integration')]
final class CnbRateDeviationCheckerTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private AccountingSupplierSettingsRepository $settings;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $eurId = 0;
    private int $clientId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->settings = $container->get(AccountingSupplierSettingsRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $base = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($base === 0 || $this->userId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $iso = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        );
        $iso->execute(['Kurz vs CNB test s.r.o.', 'cnbdev@example.com', $base]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $cur = $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, is_active)
             VALUES (?, "EUR", "EUR test", "€", "Euro", "Euro", 1)'
        );
        $cur->execute([$this->supplierId]);
        $this->eurId = (int) $pdo->lastInsertId();

        $client = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, "FX klient", "Ulice 1", "Praha", "11000", ?, ?, ?)'
        );
        $client->execute([$this->supplierId, $czId, 'cnbdevclient@example.com', $this->eurId]);
        $this->clientId = (int) $pdo->lastInsertId();
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

    private function fixedCnbStub(float $rate): CnbExchangeRateClient
    {
        $cnb = $this->createStub(CnbExchangeRateClient::class);
        $cnb->method('getRate')->willReturnCallback(
            static fn (string $code, DateTimeImmutable $date): array => [
                'rate' => $rate, 'rate_date' => $date->format('Y-m-d'), 'fallback_used' => false, 'source' => 'fresh',
            ]
        );
        return $cnb;
    }

    private function eurInvoice(string $issue, float $exchangeRate, float $totalWithVat = 1000.0): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, client_id, issue_date, due_date, currency_id,
                                   created_by, total_with_vat, exchange_rate, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "issued")'
        );
        $vs = (string) random_int(1000000000, 1999999999);
        $stmt->execute([$this->supplierId, $vs, $this->clientId, $issue, $issue, $this->eurId, $this->userId, $totalWithVat, $exchangeRate]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function eurPurchase(string $issue, float $exchangeRate, float $totalWithVat = 1000.0): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, exchange_rate,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, "received", ?, "1", "full", ?)'
        );
        $vs = 'CNBDEV-' . random_int(100000, 999999);
        $stmt->execute([
            $this->supplierId, $this->clientId, $vs, $issue, $issue, $issue, $issue,
            $this->eurId, $totalWithVat, $totalWithVat, $exchangeRate, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function testFlagsInvoiceRateAboveThreshold(): void
    {
        // Použitý kurz 24,710 vs. ČNB 24,705 (reálný nález z auditu 2026-07) — odchylka
        // ~0,02 %, POD defaultním prahem 0,5 % → nesmí se flagovat na defaultní práh,
        // ale MUSÍ na nižší (0,01 %) práh použitý explicitně v tomhle testu.
        $invoiceId = $this->eurInvoice(self::YEAR . '-03-15', 24.710, 4800.0);

        $checker = new CnbRateDeviationChecker($this->db, $this->fixedCnbStub(24.705), $this->settings);
        $result = $checker->findDeviations($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31', 0.01);

        self::assertFalse($result['fixed_mode_skipped']);
        self::assertCount(1, $result['items']);
        $item = $result['items'][0];
        self::assertSame('invoice', $item['doc_type']);
        self::assertSame($invoiceId, $item['doc_id']);
        self::assertSame('EUR', $item['currency']);
        self::assertEqualsWithDelta(24.710, $item['used_rate'], 0.0001);
        self::assertEqualsWithDelta(24.705, $item['cnb_rate'], 0.0001);
        // Dopad = total_with_vat * (cnb_rate - used_rate) = 4800 * (24.705 - 24.710) = -24.
        self::assertEqualsWithDelta(-24.0, $item['impact_czk'], 0.01);
    }

    public function testDoesNotFlagWithinThreshold(): void
    {
        // Odchylka ~0,02 % — pod defaultním prahem 0,5 % — nesmí se hlásit.
        $this->eurInvoice(self::YEAR . '-03-16', 24.710, 4800.0);

        $checker = new CnbRateDeviationChecker($this->db, $this->fixedCnbStub(24.705), $this->settings);
        $result = $checker->findDeviations($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');

        self::assertSame([], $result['items']);
        self::assertSame(0, $result['missing_cnb_count']);
    }

    public function testFlagsPurchaseInvoiceDeviation(): void
    {
        // Druhý reálný nález: 25,420 vs. 25,460 → −207 Kč (přijatá faktura). Odchylka
        // ~0,16 % — nad defaultní 0,5% by neproskočila, proto stejně jako u prvního
        // nálezu použit nižší práh odpovídající reálné dávkové kontrole.
        $pfId = $this->eurPurchase(self::YEAR . '-04-10', 25.420, 10350.0);

        $checker = new CnbRateDeviationChecker($this->db, $this->fixedCnbStub(25.460), $this->settings);
        $result = $checker->findDeviations($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31', 0.1);

        self::assertCount(1, $result['items']);
        $item = $result['items'][0];
        self::assertSame('purchase_invoice', $item['doc_type']);
        self::assertSame($pfId, $item['doc_id']);
        // 10350 * (25.460 - 25.420) = +414.0
        self::assertEqualsWithDelta(414.0, $item['impact_czk'], 0.01);
    }

    public function testFixedRateModeSkipsCheckEntirely(): void
    {
        $this->eurInvoice(self::YEAR . '-05-10', 30.0, 1000.0);
        $this->settings->setFxRateMode($this->supplierId, 'fixed_annual');

        $checker = new CnbRateDeviationChecker($this->db, $this->fixedCnbStub(24.0), $this->settings);
        $result = $checker->findDeviations($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');

        self::assertTrue($result['fixed_mode_skipped'], '§24/7 pevný kurz — kontrola se pro firmu v pevném režimu nesmí spouštět.');
        self::assertSame([], $result['items']);
    }

    public function testCountsMissingCnbRateWithoutFlagging(): void
    {
        $this->eurInvoice(self::YEAR . '-06-10', 25.0, 1000.0);

        $cnb = $this->createStub(CnbExchangeRateClient::class);
        $cnb->method('getRate')->willReturn(null); // ČNB kurz pro daný den v DB chybí.
        $checker = new CnbRateDeviationChecker($this->db, $cnb, $this->settings);

        $result = $checker->findDeviations($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');

        self::assertSame([], $result['items']);
        self::assertSame(1, $result['missing_cnb_count']);
    }

    public function testDeviationWarningFlagsAboveThreshold(): void
    {
        // Save-time varování (§C) — stejný reálný nález 24,710 vs 24,705, nižší práh.
        $checker = new CnbRateDeviationChecker($this->db, $this->fixedCnbStub(24.705), $this->settings);
        $dev = $checker->deviationWarning($this->supplierId, 'EUR', self::YEAR . '-03-15', 24.710, 0.01);

        self::assertNotNull($dev);
        self::assertEqualsWithDelta(24.710, $dev['used_rate'], 0.0001);
        self::assertEqualsWithDelta(24.705, $dev['cnb_rate'], 0.0001);
        self::assertGreaterThan(0.0, abs($dev['diff_percent']));
    }

    public function testDeviationWarningNullWithinDefaultThreshold(): void
    {
        // Odchylka ~0,02 % pod defaultním prahem 0,5 % → nevaruje.
        $checker = new CnbRateDeviationChecker($this->db, $this->fixedCnbStub(24.705), $this->settings);
        self::assertNull($checker->deviationWarning($this->supplierId, 'EUR', self::YEAR . '-03-15', 24.710));
    }

    public function testDeviationWarningNullForCzkAndMissingRate(): void
    {
        $checker = new CnbRateDeviationChecker($this->db, $this->fixedCnbStub(24.705), $this->settings);
        // CZK (tenant base) se nikdy nekontroluje.
        self::assertNull($checker->deviationWarning($this->supplierId, 'CZK', self::YEAR . '-03-15', 30.0, 0.01));
        // Chybějící kurz na dokladu (NULL) — není co srovnávat.
        self::assertNull($checker->deviationWarning($this->supplierId, 'EUR', self::YEAR . '-03-15', null, 0.01));
    }

    public function testDeviationWarningSkippedInFixedRateMode(): void
    {
        // §24/7 pevný kurz — odchylka je záměrná, save-time varování se nesmí objevit.
        $this->settings->setFxRateMode($this->supplierId, 'fixed_annual');
        $checker = new CnbRateDeviationChecker($this->db, $this->fixedCnbStub(24.0), $this->settings);
        self::assertNull($checker->deviationWarning($this->supplierId, 'EUR', self::YEAR . '-03-15', 30.0, 0.01));
    }

    public function testIgnoresDraftAndCancelledDocuments(): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, client_id, issue_date, due_date, currency_id,
                                   created_by, total_with_vat, exchange_rate, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "draft")'
        );
        $stmt->execute([$this->supplierId, (string) random_int(1000000000, 1999999999), $this->clientId,
            self::YEAR . '-07-10', self::YEAR . '-07-10', $this->eurId, $this->userId, 1000.0, 30.0]);

        $checker = new CnbRateDeviationChecker($this->db, $this->fixedCnbStub(24.0), $this->settings);
        $result = $checker->findDeviations($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31');

        self::assertSame([], $result['items'], 'Koncepty (draft) se do auditu kurzu nesmí zahrnout.');
    }
}
