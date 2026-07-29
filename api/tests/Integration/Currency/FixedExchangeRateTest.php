<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Currency;

use DateTimeImmutable;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\FixedExchangeRateRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Currency\FixedExchangeRateService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Integrační testy pevného kurzu per firma (§24/7 ZoÚ — Fáze F).
 *
 * Ověřuje: pevný měsíční kurz se použije MÍSTO denního ČNB kurzu (ExchangeRateApplier
 * zapíše pevný kurz do invoices.exchange_rate = jeden zdroj pravdy pro PostingService/
 * VatLedgerService); přepnutí režimu neovlivní už zafixovaný kurz dokladu (forward-only);
 * neexistující pevný kurz období vrátí NULL (fallback na ČNB).
 *
 * Vše v jedné transakci, tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class FixedExchangeRateTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private AccountingSupplierSettingsRepository $settings;
    private FixedExchangeRateRepository $rates;
    private FixedExchangeRateService $service;
    private ExchangeRateApplier $applier;
    private InvoiceRepository $invoiceRepo;

    private int $supplierId = 0;
    private int $eurId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db       = $container->get(Connection::class);
            $this->settings = $container->get(AccountingSupplierSettingsRepository::class);
            $this->rates    = $container->get(FixedExchangeRateRepository::class);
            $this->service  = $container->get(FixedExchangeRateService::class);
            $this->applier  = $container->get(ExchangeRateApplier::class);
            $this->invoiceRepo = $container->get(InvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $base = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($base === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $iso = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        );
        $iso->execute(['Pevny kurz test s.r.o.', 'fx@example.com', $base]);
        $this->supplierId = (int) $pdo->lastInsertId();

        // EUR měna pro tento supplier (currencies je per-firma).
        $cur = $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, is_active)
             VALUES (?, "EUR", "EUR test", "€", "Euro", "Euro", 1)'
        );
        $cur->execute([$this->supplierId]);
        $this->eurId = (int) $pdo->lastInsertId();
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

    public function testResolveReturnsFixedRateInFixedModeAndNullInDaily(): void
    {
        // Daily (default) → null (volající použije ČNB).
        $this->settings->setFxRateMode($this->supplierId, 'daily');
        self::assertNull($this->service->resolve($this->supplierId, 'EUR', new DateTimeImmutable(self::YEAR . '-03-15')));

        // Pevný měsíční kurz března.
        $this->rates->upsert($this->supplierId, 'EUR', self::YEAR, 3, 25.500000, 'manual');
        $this->settings->setFxRateMode($this->supplierId, 'fixed_monthly');

        $resolved = $this->service->resolve($this->supplierId, 'EUR', new DateTimeImmutable(self::YEAR . '-03-15'));
        self::assertNotNull($resolved);
        self::assertSame(2550000, (int) round($resolved['rate'] * 100000), 'Použije pevný kurz 25,5.');
        self::assertSame(self::YEAR . '-03-01', $resolved['rate_date']);

        // Měsíc bez nastaveného kurzu → null (fallback na ČNB).
        self::assertNull($this->service->resolve($this->supplierId, 'EUR', new DateTimeImmutable(self::YEAR . '-04-15')));
    }

    public function testApplierWritesFixedRateInsteadOfCnb(): void
    {
        $this->rates->upsert($this->supplierId, 'EUR', self::YEAR, 3, 24.000000, 'manual');
        $this->settings->setFxRateMode($this->supplierId, 'fixed_monthly');

        $invoiceId = $this->eurInvoice(self::YEAR . '-03-20');
        $meta = $this->applier->applyToInvoice($invoiceId);

        self::assertNotNull($meta);
        self::assertSame('fixed', $meta['source'], 'Použit pevný kurz, ne ČNB.');
        self::assertSame(2400000, (int) round(((float) $meta['rate']) * 100000));

        $stored = $this->db->pdo()->query('SELECT exchange_rate FROM invoices WHERE id = ' . $invoiceId)->fetchColumn();
        self::assertSame(2400000, (int) round(((float) $stored) * 100000), 'Kurz zapsán do invoices.exchange_rate (jeden zdroj pravdy).');
    }

    public function testModeSwitchDoesNotChangeAlreadyFixedRate(): void
    {
        $this->rates->upsert($this->supplierId, 'EUR', self::YEAR, 3, 24.000000, 'manual');
        $this->settings->setFxRateMode($this->supplierId, 'fixed_monthly');

        $invoiceId = $this->eurInvoice(self::YEAR . '-03-20');
        $this->applier->applyToInvoice($invoiceId); // zafixuje 24,0

        // Změna pevného kurzu i po jeho zafixování na dokladu → ensureRate nesmí přepsat.
        $this->rates->upsert($this->supplierId, 'EUR', self::YEAR, 3, 30.000000, 'manual');
        $this->applier->ensureRate($invoiceId);

        $stored = $this->db->pdo()->query('SELECT exchange_rate FROM invoices WHERE id = ' . $invoiceId)->fetchColumn();
        self::assertSame(2400000, (int) round(((float) $stored) * 100000), 'Už zafixovaný kurz se retroaktivně nemění (forward-only).');
    }

    /**
     * Adversariální review 2026-07 (STŘEDNÍ nález): firma je v pevném režimu, ale
     * pro dané období/měnu pevný kurz NENÍ nastavený — dřívější chování tiše spadlo
     * na denní ČNB kurz beze stopy pro účetní. `fixed_missing` musí uložení nechat
     * projít (doklad musí mít kurz), jen ho označit pro FE warning.
     */
    public function testApplierFlagsFixedMissingWhenNoRateForPeriod(): void
    {
        $this->settings->setFxRateMode($this->supplierId, 'fixed_monthly');
        // Žádný pevný kurz pro březen self::YEAR — jen jiný měsíc, ať firma reálně
        // je v pevném režimu, ale pro tenhle konkrétní doklad chybí konfigurace.
        $this->rates->upsert($this->supplierId, 'EUR', self::YEAR, 4, 26.000000, 'manual');

        // Stubovaný ČNB klient (bez reálného síťového volání, vzor ExchangeRateApplierDuzpTest).
        $cnb = $this->createStub(CnbExchangeRateClient::class);
        $cnb->method('getRate')->willReturnCallback(
            static fn (string $code, DateTimeImmutable $date): array => [
                'rate' => 25.0, 'rate_date' => $date->format('Y-m-d'), 'fallback_used' => false, 'source' => 'fresh',
            ]
        );
        $applier = new ExchangeRateApplier($this->db, $this->invoiceRepo, $cnb, $this->service);

        $invoiceId = $this->eurInvoice(self::YEAR . '-03-20');
        $meta = $applier->applyToInvoice($invoiceId);

        self::assertNotNull($meta, 'Uložení dokladu není blokováno — jen chybí pevný kurz.');
        self::assertSame('fresh', $meta['source'], 'Bez pevného kurzu použije ČNB.');
        self::assertTrue($meta['fixed_missing'], 'Firma je v pevném režimu, ale kurz pro tohle období chybí — musí to FE nahlásit.');

        // Kontrola negativu: v denním režimu (nebo když pevný kurz existuje) je flag false.
        $this->settings->setFxRateMode($this->supplierId, 'daily');
        $invoiceId2 = $this->eurInvoice(self::YEAR . '-03-21');
        $meta2 = $applier->applyToInvoice($invoiceId2);
        self::assertNotNull($meta2);
        self::assertFalse($meta2['fixed_missing'], 'V denním režimu se flag nenastavuje.');
    }

    private function eurInvoice(string $issue): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             SELECT ?, "FX klient", "Ulice 1", "Praha", "11000", country_id, ?, ?
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$this->supplierId, 'fx' . uniqid() . '@example.com', $this->eurId, $this->supplierId]);
        $clientId = (int) $this->db->pdo()->lastInsertId();

        $inv = $this->db->pdo()->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, client_id, issue_date, due_date, currency_id, created_by, total_with_vat, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1000.00, "issued")'
        );
        $vs = (string) random_int(1000000000, 1999999999);
        $inv->execute([$this->supplierId, $vs, $clientId, $issue, $issue, $this->eurId, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
