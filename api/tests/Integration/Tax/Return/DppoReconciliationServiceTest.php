<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax\Return;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Return\DppoReconciliationService;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Audit VADA 2 (`private/AUDIT-*`, úkol #40): rekonciliace proti podanému přiznání
 * dřív TICHE spočítala a vykreslila nesmyslný řádkový diff, i když nahrané EPO XML
 * bylo za jiný rok, než jaký byl vybraný na obrazovce (2024 XML na obrazovce 2025 →
 * fiktivní rozdíl VH 5 819 280 vs 3 295 583). {@see DppoReconciliationService::reconcile()}
 * teď rok podaného XML (zdobd_do/od) proti vybranému roku TVRDĚ ověří ještě před
 * výpočtem a při neshodě vyhodí {@see TaxReturnException} ('reconcile_year_mismatch', 422) —
 * diff se v tom případě vůbec nespočítá.
 *
 * Izolovaný supplier, transakce s rollbackem v tearDown, soft-skip bez cfg.php.
 */
#[Group('integration')]
final class DppoReconciliationServiceTest extends TestCase
{
    private Connection $db;
    private DppoReconciliationService $service;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(DppoReconciliationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($czId === 0 || $currencyId === 0 || $vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data (currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, ic, dic)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "dppo-reconcile-test@example.com", ?, ?, "12345678", "CZ12345678")'
        );
        $stmt->execute(['DPPO reconcile test s.r.o.', $czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
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

    private function sampleSupplier(): array
    {
        return [
            'company_name' => 'DPPO reconcile test s.r.o.', 'street' => 'Testovací 1',
            'city' => 'Praha', 'zip' => '110 00', 'country_iso2' => 'CZ',
            'ic' => '12345679', 'dic' => 'CZ12345679', 'taxpayer_type' => 'po',
            'financial_office_code' => '451', 'cz_nace_code' => '62020',
        ];
    }

    /** @return string XML DPPDP9 za daný rok, postavené naším vlastním builderem (round-trip). */
    private function xmlForYear(int $year): string
    {
        $calc = (new DppoReturnCalculator())->compute(
            ['vh' => 500000, 'non_deductible_costs' => 20000, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            [],
            TaxConstants::forYear($year)
        );
        return (new DppoXmlBuilder())->build($this->sampleSupplier(), $year, $calc)['xml'];
    }

    public function testUploadedYearMismatchIsHardBlockedBeforeDiffIsComputed(): void
    {
        $xml2024 = $this->xmlForYear(2024);

        try {
            $this->service->reconcile($this->supplierId, 2025, $xml2024);
            self::fail('Očekávána TaxReturnException při neshodě roku XML vs. vybraného roku.');
        } catch (TaxReturnException $e) {
            self::assertSame('reconcile_year_mismatch', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
            self::assertStringContainsString('rok 2024', $e->getMessage());
            self::assertStringContainsString('rok 2025', $e->getMessage());
        }
    }

    public function testMatchingYearReconcilesNormally(): void
    {
        $xml2025 = $this->xmlForYear(2025);

        $result = $this->service->reconcile($this->supplierId, 2025, $xml2025);

        self::assertSame('2025-01-01', $result['filing']['zdobd_od']);
        self::assertSame('2025-12-31', $result['filing']['zdobd_do']);
        self::assertArrayHasKey('diff', $result);
        self::assertArrayHasKey('warnings', $result);
    }

    public function testUploadYearMismatchAlsoBlocksReversedDirection(): void
    {
        $xml2025 = $this->xmlForYear(2025);

        try {
            $this->service->reconcile($this->supplierId, 2024, $xml2025);
            self::fail('Očekávána TaxReturnException při neshodě roku XML vs. vybraného roku.');
        } catch (TaxReturnException $e) {
            self::assertSame('reconcile_year_mismatch', $e->errorCode);
            self::assertStringContainsString('rok 2025', $e->getMessage());
            self::assertStringContainsString('rok 2024', $e->getMessage());
        }
    }
}
