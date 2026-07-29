<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\TaxEvidence\ReceivablesPayablesService;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integrace pohledávek/závazků daňové evidence (Epic DE, A3): ověřuje mapper 5→3+not_due
 * (overdue_60 + overdue_90 → 31-90) a NATIVNÍ částky per měna (žádný CZK přepočet, R13).
 * Reuse fixtur CashJournalTestCase (throwaway supplier, rollbackovaná tx).
 */
#[Group('integration')]
final class ReceivablesPayablesServiceTest extends CashJournalTestCase
{
    private function service(): ReceivablesPayablesService
    {
        return new ReceivablesPayablesService(new CrmAggregationService($this->db));
    }

    /** @param array{bucket:string,currency:string} $needle */
    private function findRow(array $rows, string $currency, string $bucket): ?array
    {
        foreach ($rows as $r) {
            if ($r['currency'] === $currency && $r['bucket'] === $bucket) {
                return $r;
            }
        }
        return null;
    }

    private function receivable(int $currencyId, float $total, string $dueDate): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, income_tax_exempt, vat_classification_code, created_by)
             VALUES (?, ?, 'invoice', ?, ?, ?, ?, ?, 25.0, 0, ?, 0, ?, 'issued', 0, '1', ?)"
        )->execute([
            $this->supplierId, (string) random_int(100000, 999999), $this->clientId,
            $dueDate, $dueDate, $dueDate, $currencyId, $total, $total, $this->userId,
        ]);
    }

    private function payable(int $currencyId, float $total, string $dueDate): void
    {
        $snapshot = json_encode(['company_name' => 'Dodavatel A s.r.o.'], JSON_UNESCAPED_UNICODE);
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, tax_deductible, status, created_by)
             VALUES (?, ?, ?, ?, 'invoice', 'full', ?, ?, ?, ?, ?, 25.0, 0, 0, ?, 0, ?, 1, 'received', ?)"
        )->execute([
            $this->supplierId, $this->vendorId, 'PF-' . random_int(100000, 999999), $snapshot,
            $dueDate, $dueDate, $dueDate, $dueDate, $currencyId, $total, $total, $this->userId,
        ]);
    }

    public function testBucketMapping5To3AndNativePerCurrency(): void
    {
        $eurId = $this->currencyRow($this->supplierId, 'EUR', '990000222', '2010');
        $d = static fn (int $daysAgo): string => (new \DateTimeImmutable())->modify(($daysAgo >= 0 ? '-' : '+') . abs($daysAgo) . ' days')->format('Y-m-d');

        // CZK pohledávky napříč všemi CRM kbelíky
        $this->receivable($this->currencyId, 1000.0, $d(15));   // overdue_30  → 1-30
        $this->receivable($this->currencyId, 500.0,  $d(50));   // overdue_60  → 31-90
        $this->receivable($this->currencyId, 300.0,  $d(80));   // overdue_90  → 31-90
        $this->receivable($this->currencyId, 200.0,  $d(200));  // overdue_90+ → 90+
        $this->receivable($this->currencyId, 999.0,  $d(-30));  // not_due
        // EUR pohledávka — nativní částka, žádný CZK přepočet (exchange_rate=25 se NESMÍ projevit)
        $this->receivable($eurId, 77.0, $d(15));                // EUR overdue_30 → 1-30

        $out = $this->service()->build($this->supplierId);
        $recv = $out['receivables'];

        // 5 CRM kbelíků → 3 DE + not_due; overdue_60 + overdue_90 slité do 31-90
        self::assertSame(1000.0, $this->findRow($recv, 'CZK', '1-30')['total']);
        $b3190 = $this->findRow($recv, 'CZK', '31-90');
        self::assertNotNull($b3190, '31-90 kbelík musí existovat (slití overdue_60+overdue_90).');
        self::assertSame(2, $b3190['count']);
        self::assertSame(800.0, $b3190['total']);
        self::assertSame(200.0, $this->findRow($recv, 'CZK', '90+')['total']);
        self::assertSame(999.0, $this->findRow($recv, 'CZK', 'not_due')['total']);

        // Nativně per měna — EUR zůstává 77 (ne 77×25), separátní řádek
        $eur = $this->findRow($recv, 'EUR', '1-30');
        self::assertNotNull($eur, 'EUR má vlastní per-měna řádek.');
        self::assertSame(77.0, $eur['total']);
        self::assertContains('EUR', $out['currencies']);
        self::assertContains('CZK', $out['currencies']);

        // KPI přítomná (reuse CRM)
        self::assertArrayHasKey('dso', $out['kpis']);
        self::assertArrayHasKey('dpo', $out['kpis']);
        self::assertArrayHasKey('punctuality', $out['kpis']);
    }

    public function testPayablesMappingNativePerCurrency(): void
    {
        $d = static fn (int $daysAgo): string => (new \DateTimeImmutable())->modify('-' . $daysAgo . ' days')->format('Y-m-d');

        $this->payable($this->currencyId, 5000.0, $d(50));   // overdue_60 → 31-90
        $this->payable($this->currencyId, 1500.0, $d(85));   // overdue_90 → 31-90

        $out = $this->service()->build($this->supplierId);
        $b = $this->findRow($out['payables'], 'CZK', '31-90');
        self::assertNotNull($b);
        self::assertSame(2, $b['count']);
        self::assertSame(6500.0, $b['total']);
    }
}
