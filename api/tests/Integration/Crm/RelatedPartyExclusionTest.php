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
 * Přehled skupiny umí vynechat doklady se spojenou osobou (vnitroskupinové převody).
 * Výchozí volání je beze změny, s vyřazením zmizí tržba i náklad spojené osoby
 * a její pohledávka i závazek. Syntetická data v roce 2001, úklid v tearDown.
 */
#[Group('integration')]
final class RelatedPartyExclusionTest extends TestCase
{
    private const FROM = '2001-03-01';
    private const TO = '2001-04-01';

    private Connection $db;
    private PDO $pdo;
    private CrmAggregationService $crm;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $currencyId = 0;
    /** @var list<int> */
    private array $clientIds = [];
    /** @var list<int> */
    private array $invoiceIds = [];
    /** @var list<int> */
    private array $purchaseIds = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->pdo = $this->db->pdo();
            $this->crm = $c->get(CrmAggregationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $stmt = $this->pdo->prepare("SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' ORDER BY is_default DESC, id LIMIT 1");
        $stmt->execute([$this->supplierId]);
        $this->currencyId = (int) $stmt->fetchColumn();
        $czId = (int) ($this->pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        if (!$this->supplierId || !$this->userId || !$this->currencyId || !$czId) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $related = $this->client('TEST Spojená s.r.o. (PHPUnit)', true, $czId);
        $other = $this->client('TEST Nespojená s.r.o. (PHPUnit)', false, $czId);
        $this->invoice($related, 'RP2001A', 7000.0);
        $this->invoice($other, 'RP2001B', 3000.0);
        $this->purchase($related, 'RP-P-1', 4000.0);
        $this->purchase($other, 'RP-P-2', 1000.0);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        foreach ($this->invoiceIds as $id) {
            $this->pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->purchaseIds as $id) {
            $this->pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->clientIds as $id) {
            $this->pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testDocumentRangeExcludesRelatedPartyRevenueAndCosts(): void
    {
        $all = $this->czk($this->crm->documentRange($this->supplierId, self::FROM, self::TO));
        $unrelated = $this->czk($this->crm->documentRange($this->supplierId, self::FROM, self::TO, null, true));

        self::assertEqualsWithDelta(7000.0, $all['revenue'] - $unrelated['revenue'], 0.001);
        self::assertEqualsWithDelta(4000.0, $all['costs'] - $unrelated['costs'], 0.001);
        self::assertEqualsWithDelta(7000.0, ($all['revenue_czk'] ?? 0) - ($unrelated['revenue_czk'] ?? 0), 0.001);
        self::assertEqualsWithDelta(4000.0, ($all['costs_czk'] ?? 0) - ($unrelated['costs_czk'] ?? 0), 0.001);
    }

    public function testAgingExcludesRelatedPartyBalances(): void
    {
        $sum = static fn (array $rows): float => array_sum(array_map(
            static fn (array $r): float => $r['currency'] === 'CZK' ? $r['total'] : 0.0, $rows));

        $receivables = $sum($this->crm->agingReceivables($this->supplierId)) - $sum($this->crm->agingReceivables($this->supplierId, true));
        $payables = $sum($this->crm->agingPayables($this->supplierId)) - $sum($this->crm->agingPayables($this->supplierId, true));

        self::assertEqualsWithDelta(7000.0, $receivables, 0.001);
        self::assertEqualsWithDelta(4000.0, $payables, 0.001);
    }

    /** @param list<array<string,mixed>> $rows */
    private function czk(array $rows): array
    {
        foreach ($rows as $row) {
            if ($row['currency'] === 'CZK') return $row;
        }
        return ['revenue' => 0.0, 'costs' => 0.0, 'revenue_czk' => 0.0, 'costs_czk' => 0.0];
    }

    private function client(string $name, bool $related, int $countryId): int
    {
        $this->pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id, related_party)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $name, 'Ulice 1', 'Praha', '11000', $countryId, 'rp@example.test', $this->currencyId, $related ? 1 : 0]);
        return $this->clientIds[] = (int) $this->pdo->lastInsertId();
    }

    private function invoice(int $clientId, string $varsymbol, float $amount): void
    {
        $this->pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, created_by)
             VALUES (?, ?, "invoice", ?, "2001-03-10", "2001-03-10", "2001-03-24", ?, 0, ?, 0, ?, "issued", ?)'
        )->execute([$this->supplierId, $varsymbol, $clientId, $this->currencyId, $amount, $amount, $this->userId]);
        $this->invoiceIds[] = (int) $this->pdo->lastInsertId();
    }

    private function purchase(int $vendorId, string $number, float $amount): void
    {
        $this->pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind,
                 vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, "{}", "invoice", "full", "2001-03-10", "2001-03-10", "2001-03-24", "2001-03-10", ?, 0, 0, ?, 0, ?, "received", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $this->currencyId, $amount, $amount, $this->userId]);
        $this->purchaseIds[] = (int) $this->pdo->lastInsertId();
    }
}
