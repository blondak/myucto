<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Crm;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Charakterizační (golden baseline) test pro CrmAggregationService::cashFlowForecast
 * PŘED přepisem SQL 10.6→11.8 (Epic SQL fáze 2, F5 — 2×weeksAhead dotazů v PHP smyčce
 * → jeden GROUP BY week bucket). Zamyká per-week atribuci in/out/net/running, aby přepis
 * na single-query zůstal behavior-preserving.
 *
 * In  = SUM(amount_to_pay - paid_total) nezaplacených vystavených faktur s due_date v týdnu.
 * Out = SUM(total_with_vat) nezaplacených přijatých faktur s due_date v týdnu.
 * amount_to_pay = total_with_vat − advance_paid_amount (STORED gen-col; advance=0 → =gross).
 *
 * Metoda: transakce + rollback, DELTA proti baseline (jiná reálná data se splatností
 * v okně nevadí). due_date sázíme přesně na hranice týdnů, které SQL počítá. Soft-skip.
 */
#[Group('integration')]
final class CrmCashFlowForecastTest extends TestCase
{
    private Connection $db;
    private CrmAggregationService $crm;
    private \PDO $pdo;

    private int $supplierId = 0;
    private int $czkId = 0;
    private int $userId = 0;
    private int $czId = 0;
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
            $this->crm = $container->get(CrmAggregationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->pdo = $this->db->pdo();

        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->czkId = (int) ($this->pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->czId === 0 || $this->czkId === 0) {
            $this->markTestSkipped('Chybí supplier/user/country/CZK.');
        }

        $this->pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testForecastBucketsInOutPerWeek(): void
    {
        $today = new \DateTimeImmutable('today');
        $nextMonday = $today->modify('+1 weeks')->modify('Monday this week');

        $client = $this->party('CF Klient', true, false);
        $vendor = $this->party('CF Dodavatel', false, true);

        $base = $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK');
        $baseW0In = (float) $base['weeks'][0]['in'];
        $baseW0Out = (float) $base['weeks'][0]['out'];
        $baseW1In = (float) $base['weeks'][1]['in'];
        $baseTotalIn = (float) $base['total_in'];
        $baseTotalOut = (float) $base['total_out'];

        // Týden 0 (od dneška): in 1210, out 605.
        $this->invoice($client, $today->format('Y-m-d'), 1210.0);
        $this->purchase($vendor, $today->format('Y-m-d'), 605.0);
        // Týden 1 (pondělí příštího týdne): in 2420.
        $this->invoice($client, $nextMonday->format('Y-m-d'), 2420.0);

        $after = $this->crm->cashFlowForecast($this->supplierId, 2, 'CZK');
        self::assertEqualsWithDelta($baseW0In + 1210.0, (float) $after['weeks'][0]['in'], 0.01, 'Týden 0 in += 1210.');
        self::assertEqualsWithDelta($baseW0Out + 605.0, (float) $after['weeks'][0]['out'], 0.01, 'Týden 0 out += 605.');
        self::assertEqualsWithDelta($baseW1In + 2420.0, (float) $after['weeks'][1]['in'], 0.01, 'Týden 1 in += 2420.');
        self::assertEqualsWithDelta($baseTotalIn + 3630.0, (float) $after['total_in'], 0.01, 'total_in += 1210+2420.');
        self::assertEqualsWithDelta($baseTotalOut + 605.0, (float) $after['total_out'], 0.01, 'total_out += 605.');

        // net týdne 0 = in − out; running týdne 1 = kumulativně.
        self::assertEqualsWithDelta(
            (float) $after['weeks'][0]['in'] - (float) $after['weeks'][0]['out'],
            (float) $after['weeks'][0]['net'],
            0.01,
            'net[0] = in[0] − out[0].',
        );
        self::assertEqualsWithDelta(
            (float) $after['weeks'][0]['net'] + (float) $after['weeks'][1]['net'],
            (float) $after['weeks'][1]['running'],
            0.01,
            'running[1] = net[0] + net[1].',
        );
    }

    // ── seed helpers ───────────────────────────────────────────────────────────

    private function party(string $name, bool $customer, bool $vendor): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ12345678", "cf@example.com", "cs", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->czkId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    private function invoice(int $clientId, string $dueDate, float $gross): int
    {
        $issue = date('Y-m-d');
        $net = round($gross / 1.21, 2);
        $stmt = $this->pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 1.0, 0, ?, ?, ?, "issued", "1", ?)'
        );
        $stmt->execute([
            $this->supplierId, 'CF-' . uniqid(), $clientId, $issue, $issue, $dueDate,
            $this->czkId, $net, round($gross - $net, 2), $gross, $this->userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function purchase(int $vendorId, string $dueDate, float $gross): int
    {
        $issue = date('Y-m-d');
        $net = round($gross / 1.21, 2);
        $stmt = $this->pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, ?, "invoice", ?, ?, ?, ?, ?, 1.0, 0, "{}", ?, ?, ?, "received", "40", "full", ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, 'CFP-' . uniqid(), 'CFP-' . uniqid(),
            $issue, $issue, $dueDate, $issue, $this->czkId, $net, round($gross - $net, 2), $gross, $this->userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
