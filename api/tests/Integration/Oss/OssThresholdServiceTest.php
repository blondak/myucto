<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Oss;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Oss\OssThresholdService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Celounijní práh 10 000 EUR pro přeshraniční B2C plnění — § 8 odst. 3 / § 10i ZDPH.
 *
 * OSS byl čistý ruční přepínač bez vazby na obrat; manuál sám přiznával, že „MyÚčto
 * nehlídá překročení prahu 10 000 EUR". Uživatel se mohl minout oběma směry — fakturovat
 * s českou daní i po vzniku povinnosti, nebo zdaňovat v cizině dřív, než mu vznikla.
 *
 * Nejdůležitější je {@see testDomesticallyInvoicedB2cCountsTowardThreshold()}: do prahu
 * se musí počítat i plnění, která ještě NEJSOU označená jako OSS. Kdyby se sčítala jen
 * ta označená, sledování by bylo k ničemu — před registrací žádná neexistují, takže
 * by práh nikdy nemohl být překročen.
 */
#[Group('integration')]
final class OssThresholdServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private OssThresholdService $service;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $eurId = 0;
    private int $rate21Id = 0;
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
            $this->service = $c->get(OssThresholdService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->eurId  = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'EUR' LIMIT 1")->fetchColumn() ?: 0);
        $this->rate21Id = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 21 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0 || $this->eurId === 0 || $this->rate21Id === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users / měna EUR / sazba 21 %).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
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

    /** Bez plnění je práh nedotčený a nic se nehlásí. */
    public function testNoSuppliesMeansNoWarning(): void
    {
        $p = $this->service->progress($this->supplierId, 2099);

        self::assertSame(0.0, $p['total_eur']);
        self::assertFalse($p['exceeded']);
        self::assertSame([], $p['warnings']);
    }

    /**
     * Plnění fakturované s českou daní (tedy BEZ příznaku OSS) se do prahu počítá.
     * Bez toho by práh nikdy nemohl být překročen a celé sledování by bylo mrtvé.
     */
    public function testDomesticallyInvoicedB2cCountsTowardThreshold(): void
    {
        $client = $this->euConsumer('DE');
        $this->sale($client, '2099-03-15', 4000.0, ossApplicable: false);

        $p = $this->service->progress($this->supplierId, 2099);

        self::assertEqualsWithDelta(4000.0, $p['total_eur'], 0.01);
        self::assertFalse($p['exceeded']);
    }

    /** B2B (odběratel s DIČ) do prahu nepatří — tam se uplatní reverse-charge. */
    public function testB2bSupplyIsExcluded(): void
    {
        $client = $this->euConsumer('DE', dic: 'DE123456789');
        $this->sale($client, '2099-03-15', 9000.0);

        self::assertSame(0.0, $this->service->progress($this->supplierId, 2099)['total_eur']);
    }

    /** Tuzemský odběratel do celounijního prahu nepatří. */
    public function testDomesticCustomerIsExcluded(): void
    {
        $client = $this->euConsumer('CZ');
        $this->sale($client, '2099-03-15', 9000.0);

        self::assertSame(0.0, $this->service->progress($this->supplierId, 2099)['total_eur']);
    }

    /** Překročení nese datum — účetní potřebuje vědět, od kdy povinnost vznikla. */
    public function testExceedingReportsTheDateItHappened(): void
    {
        $client = $this->euConsumer('AT');
        $this->sale($client, '2099-02-10', 6000.0);
        $this->sale($client, '2099-08-20', 5000.0);

        $p = $this->service->progress($this->supplierId, 2099);

        self::assertTrue($p['exceeded']);
        self::assertSame('2099-08-20', $p['exceeded_on'], 'Rozhodné je plnění, kterým součet práh přesáhl.');
        self::assertStringContainsString('20. 8. 2099', implode("\n", $p['warnings']));
    }

    /** Blízko prahu se varuje dřív, než je pozdě. */
    public function testNearThresholdWarnsInAdvance(): void
    {
        $client = $this->euConsumer('SK');
        $this->sale($client, '2099-05-10', 8500.0);

        $p = $this->service->progress($this->supplierId, 2099);

        self::assertFalse($p['exceeded']);
        self::assertTrue($p['near_threshold']);
        self::assertStringContainsString('85', implode("\n", $p['warnings']));
    }

    /** Práh je ROČNÍ — plnění z jiného roku se nepřičítá. */
    public function testThresholdIsPerCalendarYear(): void
    {
        $client = $this->euConsumer('PL');
        $this->sale($client, '2098-11-10', 9000.0);
        $this->sale($client, '2099-01-10', 3000.0);

        self::assertEqualsWithDelta(3000.0, $this->service->progress($this->supplierId, 2099)['total_eur'], 0.01);
        self::assertEqualsWithDelta(9000.0, $this->service->progress($this->supplierId, 2098)['total_eur'], 0.01);
    }

    /** Rozpad po zemích seřazený sestupně — kde obrat vzniká, je pro registraci podstatné. */
    public function testBreakdownByCountryIsSortedByAmount(): void
    {
        $this->sale($this->euConsumer('DE'), '2099-03-01', 2000.0);
        $this->sale($this->euConsumer('AT'), '2099-03-02', 5000.0);

        $by = $this->service->progress($this->supplierId, 2099)['by_country'];

        self::assertSame('AT', $by[0]['country']);
        self::assertSame('DE', $by[1]['country']);
    }

    /** Storno a koncept se do prahu nepočítají — nejde o uskutečněná plnění. */
    public function testCancelledAndDraftAreExcluded(): void
    {
        $client = $this->euConsumer('NL');
        $this->sale($client, '2099-03-01', 9000.0, status: 'cancelled');
        $this->sale($client, '2099-03-02', 9000.0, status: 'draft');

        self::assertSame(0.0, $this->service->progress($this->supplierId, 2099)['total_eur']);
    }

    /**
     * Zapnutý OSS pod prahem není chyba (registrace je dobrovolná), ale musí zaznít —
     * při zapnutém režimu bez skutečné registrace míří daň do nesprávného státu.
     */
    public function testOssEnabledBelowThresholdIsFlagged(): void
    {
        $this->sale($this->euConsumer('DE'), '2099-03-01', 1000.0);

        $w = implode("\n", $this->service->registrationSanityWarnings($this->supplierId, 2099, true));

        self::assertStringContainsString('Dobrovolná registrace', $w);
    }

    /** Překročený práh při vypnutém OSS je opačná, závažnější strana téhož. */
    public function testExceededWithOssDisabledIsFlagged(): void
    {
        $this->sale($this->euConsumer('DE'), '2099-03-01', 12000.0);

        $w = implode("\n", $this->service->registrationSanityWarnings($this->supplierId, 2099, false));

        self::assertStringContainsString('není zapnutý', $w);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function euConsumer(string $iso2, ?string $dic = null): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query(
            "SELECT id FROM countries WHERE UPPER(iso2) = '" . strtoupper($iso2) . "' LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát ' . $iso2 . ' není v číselníku zemí.');
        }

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Mesto", "11000", ?, ?, "c@example.com", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, 'Spotřebitel ' . $iso2, $countryId, $dic, $this->eurId]);

        return (int) $pdo->lastInsertId();
    }

    /** Prodej v EUR, ať přepočet nezávisí na dostupnosti kurzu ČNB pro fiktivní rok. */
    private function sale(
        int $clientId,
        string $taxDate,
        float $baseEur,
        bool $ossApplicable = false,
        string $status = 'issued',
    ): int {
        $pdo = $this->db->pdo();
        $vat = round($baseEur * 0.21, 2);
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, varsymbol, invoice_type, issue_date, tax_date,
                 due_date, currency_id, reverse_charge,
                 client_snapshot, supplier_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, 0, "{}", "{}", ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId, $clientId, substr(md5($taxDate . $baseEur . $clientId), 0, 10),
            $taxDate, $taxDate, $taxDate, $this->eurId,
            $baseEur, $vat, $baseEur + $vat, $status, $this->userId,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat,
                 order_index, oss_applicable)
             VALUES (?, "Plnění", 1, ?, ?, 21.00, ?, ?, ?, 1, ?)'
        )->execute([
            $invoiceId, $baseEur, $this->rate21Id, $baseEur, $vat, $baseEur + $vat,
            $ossApplicable ? 1 : 0,
        ]);

        return $invoiceId;
    }
}
