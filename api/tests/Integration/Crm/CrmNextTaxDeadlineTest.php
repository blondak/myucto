<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Crm;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * CrmAggregationService::nextTaxDeadline() — "nejbližší termín" napříč typy pro
 * Přehled firem (Fáze F, audit 2026-07 P2/M), na rozdíl od taxDeadlineItems()
 * NENÍ omezené na okno kolem AKTUÁLNÍHO měsíce, počítá dopředu.
 *
 * Izolace: každý test používá vlastního prázdného dodavatele, aby termín SH
 * neovlivnily EU faktury uložené ve vývojové databázi. Soft-skip bez DB.
 */
#[Group('integration')]
final class CrmNextTaxDeadlineTest extends TestCase
{
    private Connection $db;
    private CrmAggregationService $crm;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->crm = $c->get(CrmAggregationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-21' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($countryId === 0 || $vatRateId === 0 || $currencyId === 0) {
            $this->markTestSkipped('Chybí předpoklady (country/vat/currency).');
        }
        $pdo->prepare(
            'INSERT INTO supplier
                (company_name, street, city, zip, country_id, email, default_currency_id,
                 default_vat_rate_id, taxpayer_type, is_vat_payer, vat_period)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
        )->execute([
            '__CRM_NEXT_TAX_DEADLINE_TEST__', 'Test 1', 'Praha', '11000', $countryId,
            'crm-deadline@example.invalid', $currencyId, $vatRateId, 'po', 'monthly',
        ]);
        $this->supplierId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->supplierId > 0) {
            $this->db->pdo()->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        }
    }

    private function configure(string $vatPeriod, string $taxpayerType): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE supplier SET is_vat_payer = 1, vat_period = ?, taxpayer_type = ? WHERE id = ?'
        );
        $stmt->execute([$vatPeriod, $taxpayerType, $this->supplierId]);
    }

    public function testNonVatPayerHasNoDeadline(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = 0 WHERE id = ?')->execute([$this->supplierId]);
        $result = $this->crm->nextTaxDeadline($this->supplierId, new \DateTimeImmutable('2026-06-19'));
        self::assertNull($result, 'Neplátce DPH nemá žádný nejbližší termín.');
    }

    public function testMonthlyPayerBeforeThe25thUsesCurrentMonth(): void
    {
        $this->configure('monthly', 'po');
        $result = $this->crm->nextTaxDeadline($this->supplierId, new \DateTimeImmutable('2026-06-19'));
        self::assertNotNull($result);
        self::assertSame('2026-06-25', $result['date']);
        self::assertSame(6, $result['days']);
        self::assertSame('DPH + KH', $result['label'], 'Měsíčně sloučené DPH+KH na stejný den.');
    }

    public function testMonthlyPayerAfterThe25thRollsToNextMonth(): void
    {
        $this->configure('monthly', 'po');
        $result = $this->crm->nextTaxDeadline($this->supplierId, new \DateTimeImmutable('2026-06-27'));
        self::assertNotNull($result);
        // 25. 7. 2026 je sobota → § 33/4 DŘ posouvá lhůtu na pondělí 27. 7.
        self::assertSame('2026-07-27', $result['date'], 'Po 25. musí termín posunout na příští měsíc.');
    }

    public function testQuarterlyFoMergesDphAndKhOnSameQuarterDate(): void
    {
        $this->configure('quarterly', 'fo');
        $result = $this->crm->nextTaxDeadline($this->supplierId, new \DateTimeImmutable('2026-06-19'));
        self::assertNotNull($result);
        // 25. 7. 2026 je sobota → zákonná lhůta je pondělí 27. 7. (§ 33/4 DŘ).
        self::assertSame('2026-07-27', $result['date'], 'V půli Q2 je nejbližší termín konec Q2 (posunutý z 25. 7.).');
        self::assertSame('DPH + KH', $result['label'], 'FO má KH sloučené s DPH (čtvrtletně).');
    }

    public function testQuarterlyPoSeparatesMonthlyKhFromQuarterlyDph(): void
    {
        $this->configure('quarterly', 'po');
        // V půlce kvartálu je nejbližší termín měsíční KH (dřív než čtvrtletní DPH).
        $result = $this->crm->nextTaxDeadline($this->supplierId, new \DateTimeImmutable('2026-06-19'));
        self::assertNotNull($result);
        self::assertSame('2026-06-25', $result['date'], 'Čtvrtletní PO má KH každý měsíc — nejbližší je měsíční KH.');
        self::assertSame('KH', $result['label']);
    }

    public function testResultHasShvPendingFlag(): void
    {
        $this->configure('monthly', 'po');
        $result = $this->crm->nextTaxDeadline($this->supplierId, new \DateTimeImmutable('2026-06-19'));
        self::assertNotNull($result);
        self::assertArrayHasKey('shv_pending', $result);
        self::assertIsBool($result['shv_pending']);
        self::assertFalse($result['shv_pending']);
    }
}
