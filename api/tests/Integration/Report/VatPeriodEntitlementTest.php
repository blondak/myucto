<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\VatPeriodEntitlementService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Nárok na čtvrtletní zdaňovací období — § 99 a § 99a ZDPH.
 *
 * `supplier.vat_period` byl ruční přepínač BEZ kontroly nároku. Matice to vedla jako
 * CHYBÍ s poznámkou, že nesprávné nastavení znamená celoročně pozdě podávaná přiznání —
 * tedy ne jednu chybu, ale sérii pokut.
 *
 * Nejdůležitější je {@see testWarnsAboutUnverifiableConditionsEvenWhenTurnoverFits()}:
 * systém ověří jen obrat. Rok registrace, nespolehlivý plátce ani skupinová registrace
 * z účetnictví zjistit nejdou, takže se o nich MLUVÍ i tehdy, když obrat sedí. Tvrdit
 * „nárok máte" na základě jediné ověřené podmínky by bylo horší než dnešní stav — dnes
 * uživatel ví, že si to musí ohlídat sám.
 */
#[Group('integration')]
final class VatPeriodEntitlementTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2093;

    private Connection $db;
    private VatPeriodEntitlementService $service;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $seq = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->service = $c->get(VatPeriodEntitlementService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0 || $this->currencyId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1, vat_period = "quarterly" WHERE id = ?')
            ->execute([$this->supplierId]);

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Odběratel", "Test 1", "Praha", "11000", ?, "o@example.com", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
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

    /** Obrat pod limitem → ověřitelná podmínka splněna. */
    public function testTurnoverUnderLimitIsOk(): void
    {
        $this->sale(5000000.0, (self::YEAR - 1) . '-06-15');

        $r = $this->service->evaluate($this->supplierId, self::YEAR);

        self::assertTrue($r['ok']);
        self::assertFalse($r['over_limit']);
        self::assertEqualsWithDelta(5000000.0, $r['prior_year_turnover'], 0.01);
    }

    /** Nad limitem → čtvrtletní období nelze, hlásí se to jako chyba. */
    public function testTurnoverOverLimitIsNotOk(): void
    {
        $this->sale(16000000.0, (self::YEAR - 1) . '-06-15');

        $r = $this->service->evaluate($this->supplierId, self::YEAR);

        self::assertFalse($r['ok']);
        self::assertTrue($r['over_limit']);
        self::assertStringContainsString('MĚSÍČNĚ', implode("\n", $r['warnings']));
    }

    /**
     * Systém ověří JEN obrat. O zbývajících podmínkách § 99a se mluví i tehdy, když
     * obrat sedí — mlčení by vypadalo jako potvrzení nároku.
     */
    public function testWarnsAboutUnverifiableConditionsEvenWhenTurnoverFits(): void
    {
        $this->sale(1000000.0, (self::YEAR - 1) . '-06-15');

        $r = $this->service->evaluate($this->supplierId, self::YEAR);

        self::assertTrue($r['ok']);
        self::assertStringContainsString('rok registrace', implode("\n", $r['warnings']));
        self::assertStringContainsString('nespolehlivého plátce', implode("\n", $r['warnings']));
    }

    /** Měsíčnímu plátci se nárok neposuzuje — na měsíční období ho mít nemusí. */
    public function testMonthlyPayerIsNotWarned(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET vat_period = "monthly" WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->sale(20000000.0, (self::YEAR - 1) . '-06-15');

        $r = $this->service->evaluate($this->supplierId, self::YEAR);

        self::assertTrue($r['ok']);
        self::assertSame([], $r['warnings']);
    }

    /** Rozhoduje PŘEDCHÁZEJÍCÍ rok, ne běžný — jinak by se limit posuzoval z neúplných dat. */
    public function testOnlyPriorYearCounts(): void
    {
        $this->sale(20000000.0, self::YEAR . '-06-15');

        $r = $this->service->evaluate($this->supplierId, self::YEAR);

        self::assertSame(0.0, $r['prior_year_turnover'], 'Obrat běžného roku se do limitu nepočítá.');
        self::assertTrue($r['ok']);
    }

    /**
     * Dobropis obrat VŽDY snižuje. U dobropisu chybně zadaného s kladnou částkou by se
     * obrat navýšil — a právě obrat rozhoduje o povinnosti podávat měsíčně.
     */
    public function testCreditNoteAlwaysReducesTurnover(): void
    {
        $this->sale(16000000.0, (self::YEAR - 1) . '-03-01');
        $this->sale(2000000.0, (self::YEAR - 1) . '-04-01', 'credit_note');

        $r = $this->service->evaluate($this->supplierId, self::YEAR);

        self::assertEqualsWithDelta(14000000.0, $r['prior_year_turnover'], 0.01);
        self::assertTrue($r['ok'], 'Po snížení dobropisem je obrat pod limitem.');
    }

    /** Koncepty a proformy do obratu nepatří — nejsou uskutečněným plněním. */
    public function testDraftsAndProformasAreExcluded(): void
    {
        $this->sale(20000000.0, (self::YEAR - 1) . '-03-01', 'invoice', 'draft');
        $this->sale(20000000.0, (self::YEAR - 1) . '-04-01', 'proforma');

        self::assertSame(0.0, $this->service->evaluate($this->supplierId, self::YEAR)['prior_year_turnover']);
    }

    private function sale(
        float $withoutVat,
        string $taxDate,
        string $type = 'invoice',
        string $status = 'issued',
    ): void {
        $this->seq++;
        $vat = round($withoutVat * 0.21, 2);
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, varsymbol, invoice_type, issue_date, tax_date,
                 due_date, currency_id, reverse_charge,
                 client_snapshot, supplier_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, "{}", "{}", ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId, $this->clientId, 'F' . $this->seq, $type,
            $taxDate, $taxDate, $taxDate, $this->currencyId,
            $withoutVat, $vat, $withoutVat + $vat, $status, $this->userId,
        ]);
    }
}
