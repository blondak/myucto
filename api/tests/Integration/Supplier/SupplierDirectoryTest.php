<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Supplier;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Supplier\SupplierDirectory;
use MyInvoice\Service\Supplier\SupplierDirectoryQuery;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Seznam firem ve správě firem: urgence, řazení a filtry nad syntetickými firmami
 * v transakci (rollback v tearDown). Cizí firma s urgentními doklady se do výsledku
 * nesmí dostat, protože není v předaném rozsahu firem.
 */
#[Group('integration')]
final class SupplierDirectoryTest extends TestCase
{
    private Connection $db;
    private SupplierDirectory $directory;
    private bool $inTx = false;

    private int $czId = 0;
    private int $vatRateId = 0;
    private int $anyCurrencyId = 0;
    private int $creatorId = 0;

    /** @var array<int, array{currency:int, client:int}> */
    private array $refs = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->directory = $c->get(SupplierDirectory::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->anyCurrencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->creatorId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->czId === 0 || $this->vatRateId === 0 || $this->anyCurrencyId === 0 || $this->creatorId === 0) {
            $this->markTestSkipped('Chybí základní data (country/vat_rate/currency/user) v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        $this->inTx = false;
    }

    public function testUrgencyOrderLevelsAndForeignCompanyExcluded(): void
    {
        [$receivables, $vatLate, $quiet, $drafts, $foreign] = $this->fixture();
        $now = new \DateTimeImmutable('2026-10-08 10:00:00');

        $rows = $this->directory->list([$receivables, $vatLate, $quiet, $drafts], new SupplierDirectoryQuery(), $now);
        $byId = array_column($rows, null, 'id');

        self::assertSame([$vatLate, $receivables, $drafts, $quiet], array_column($rows, 'id'));
        self::assertArrayNotHasKey($foreign, $byId);

        self::assertSame('overdue', $byId[$vatLate]['urgency']['vat']['status']);
        self::assertSame('2026-08', $byId[$vatLate]['urgency']['vat']['period']);
        self::assertSame('2026-09-25', $byId[$vatLate]['urgency']['vat']['deadline']);
        self::assertSame('high', $byId[$vatLate]['urgency']['level']);

        self::assertSame(2, $byId[$receivables]['urgency']['overdue_receivables']);
        self::assertSame(2 * SupplierDirectory::WEIGHT_OVERDUE_RECEIVABLE, $byId[$receivables]['urgency']['score']);
        self::assertNull($byId[$receivables]['urgency']['vat'], 'Firma bez podání DPH v aplikaci nemá stav přiznání.');

        self::assertSame(1, $byId[$drafts]['urgency']['purchase_drafts'], 'Koncept čekající na schválení se nepočítá.');
        self::assertSame(0, $byId[$quiet]['urgency']['score']);
        self::assertSame('none', $byId[$quiet]['urgency']['level']);
    }

    public function testFiltersAndAlternativeSorts(): void
    {
        [$receivables, $vatLate, $quiet, $drafts] = $this->fixture();
        $scope = [$receivables, $vatLate, $quiet, $drafts];
        $now = new \DateTimeImmutable('2026-10-08 10:00:00');
        $ids = fn (array $params): array => array_column(
            $this->directory->list($scope, SupplierDirectoryQuery::fromArray($params), $now), 'id');

        self::assertSame([$quiet], $ids(['urgency' => 'without']));
        self::assertSame([$vatLate, $receivables, $drafts], $ids(['urgency' => 'with']));
        self::assertSame([$receivables], $ids(['mode' => 'double_entry']));
        self::assertSame([$quiet, $drafts], $ids(['vat' => 'non_payer', 'sort' => 'name']));
        self::assertSame([$receivables], $ids(['q' => '99000011']));
        self::assertSame([$vatLate], $ids(['q' => 'pozdní']));

        self::assertSame([$quiet, $drafts, $receivables, $vatLate], $ids(['sort' => 'name']));
        self::assertSame([$vatLate, $receivables, $drafts, $quiet], $ids(['sort' => 'name', 'dir' => 'desc']));
        // Poslední vystavená faktura: nejnovější nahoře, firmy bez faktury na konci.
        self::assertSame([$quiet, $receivables, $vatLate, $drafts], $ids(['sort' => 'last_invoice']));
        self::assertSame([$vatLate, $receivables, $quiet, $drafts], $ids(['sort' => 'last_invoice', 'dir' => 'asc']));
        self::assertSame($receivables, $ids(['sort' => 'overdue'])[0]);
        self::assertSame([], $this->directory->list([], new SupplierDirectoryQuery(), $now));
    }

    public function testVatPeriodFiledDueSoonAndPayerFromMidPeriod(): void
    {
        $filedAugust = $this->supplier('__TEST DIR Podáno včas', 'tax_evidence', true, '99000031');
        $this->submission($filedAugust, 2026, 7);
        $this->submission($filedAugust, 2026, 8);

        // Plátcem od poloviny srpna: za srpen přiznání podat musí, i když 1. 8. plátcem nebyla.
        $midAugust = $this->supplier('__TEST DIR Plátce od srpna', 'tax_evidence', true, '99000032');
        $this->vatHistory($midAugust, '1900-01-01', 0);
        $this->vatHistory($midAugust, '2026-08-15', 1);
        $this->submission($midAugust, 2026, 6, 'generated');
        $this->submission($midAugust, 2026, 9, 'accepted');

        $scope = [$filedAugust, $midAugust];
        $early = array_column($this->directory->list($scope, new SupplierDirectoryQuery(), new \DateTimeImmutable('2026-10-08')), null, 'id');
        self::assertSame('ok', $early[$filedAugust]['urgency']['vat']['status']);
        self::assertSame('2026-10-26', $early[$filedAugust]['urgency']['vat']['deadline'], '25. 10. 2026 je neděle.');
        self::assertSame(0, $early[$filedAugust]['urgency']['score']);
        self::assertSame('overdue', $early[$midAugust]['urgency']['vat']['status']);
        self::assertSame('2026-08', $early[$midAugust]['urgency']['vat']['period']);

        $late = array_column($this->directory->list($scope, new SupplierDirectoryQuery(), new \DateTimeImmutable('2026-10-20')), null, 'id');
        self::assertSame('due_soon', $late[$filedAugust]['urgency']['vat']['status']);
        self::assertSame(6, $late[$filedAugust]['urgency']['vat']['days']);
        self::assertSame(SupplierDirectory::WEIGHT_VAT_DUE_SOON, $late[$filedAugust]['urgency']['score']);
    }

    public function testVatSlotsForMonthlyAndQuarterlyCycles(): void
    {
        $slots = [];
        foreach (SupplierDirectory::vatSlots(new \DateTimeImmutable('2026-10-08')) as $s) {
            $slots[$s['cycle'] . ':' . $s['slot']] = [$s['from'], $s['to'], $s['month'], $s['quarter'], $s['deadline']];
        }

        self::assertSame([
            'monthly:past'   => ['2026-08-01', '2026-08-31', 8, null, '2026-09-25'],
            'monthly:next'   => ['2026-09-01', '2026-09-30', 9, null, '2026-10-26'],
            'quarterly:past' => ['2026-04-01', '2026-06-30', null, 2, '2026-07-27'],
            'quarterly:next' => ['2026-07-01', '2026-09-30', null, 3, '2026-10-26'],
        ], $slots);
    }

    /** @return array{int,int,int,int,int} */
    private function fixture(): array
    {
        $receivables = $this->supplier('__TEST DIR Beta pohledávky', 'double_entry', true, '99000011');
        $this->invoice($receivables, 'issued', '2026-08-01', '2026-09-01', 1000);
        $this->invoice($receivables, 'sent', '2026-08-15', '2026-09-15', 500);
        $this->invoice($receivables, 'paid', '2026-03-01', '2026-03-15', 800);

        $vatLate = $this->supplier('__TEST DIR Gama pozdní DPH', 'tax_evidence', true, '99000012');
        $this->invoice($vatLate, 'paid', '2026-02-01', '2026-02-15', 100);
        $this->submission($vatLate, 2026, 7);

        $quiet = $this->supplier('__TEST DIR Alfa klidná', 'tax_evidence', false, '99000013');
        $this->invoice($quiet, 'paid', '2026-10-01', '2026-10-15', 100);

        $drafts = $this->supplier('__TEST DIR Alfa koncepty', 'tax_evidence', false, '99000014');
        $this->purchaseDraft($drafts, 'none');
        $this->purchaseDraft($drafts, 'pending');

        $foreign = $this->supplier('__TEST DIR Cizí', 'double_entry', true, '99000015');
        $this->invoice($foreign, 'issued', '2026-08-01', '2026-09-01', 1000);

        return [$receivables, $vatLate, $quiet, $drafts, $foreign];
    }

    private function supplier(string $name, string $mode, bool $vatPayer, string $ic): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO supplier (company_name, display_name, street, city, zip, country_id, email, ic,
                                   default_currency_id, default_vat_rate_id, accounting_mode, is_vat_payer, vat_period)
             VALUES (?, ?, 'Testovací 1', 'Brno', '60200', ?, 'directory@example.invalid', ?, ?, ?, ?, ?, 'monthly')"
        )->execute([$name, $name, $this->czId, $ic, $this->anyCurrencyId, $this->vatRateId, $mode, $vatPayer ? 1 : 0]);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
        )->execute([$id]);
        $currency = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currency, $id]);

        $pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id)
             VALUES (?, 'Syntetický partner s.r.o.', 'Partnerská 2', 'Praha', '11000', ?, ?)"
        )->execute([$id, $this->czId, $currency]);

        $this->refs[$id] = ['currency' => $currency, 'client' => (int) $pdo->lastInsertId()];
        return $id;
    }

    private function invoice(int $supplierId, string $status, string $issue, string $due, float $total): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO invoices (supplier_id, invoice_type, status, client_id, issue_date, due_date, currency_id, total_with_vat)
             VALUES (?, 'invoice', ?, ?, ?, ?, ?, ?)"
        )->execute([$supplierId, $status, $this->refs[$supplierId]['client'], $issue, $due, $this->refs[$supplierId]['currency'], $total]);
    }

    private function purchaseDraft(int $supplierId, string $approval): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, document_kind, status, approval_status,
                                            issue_date, due_date, received_at, currency_id, vendor_snapshot, created_by)
             VALUES (?, ?, ?, 'invoice', 'draft', ?, '2026-09-01', '2026-09-15', '2026-09-02', ?, '{}', ?)"
        )->execute([
            $supplierId, $this->refs[$supplierId]['client'], 'PF-' . bin2hex(random_bytes(4)), $approval,
            $this->refs[$supplierId]['currency'], $this->creatorId,
        ]);
    }

    private function submission(int $supplierId, int $year, int $month, string $status = 'submitted'): void
    {
        $xml = '<Pisemnost/>';
        $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, period_quarter,
                                          xml_content, xml_size_bytes, xml_sha256, status)
             VALUES (?, 'dphdp3', ?, ?, NULL, ?, ?, ?, ?)"
        )->execute([$supplierId, $year, $month, $xml, strlen($xml), hash('sha256', $xml . $supplierId . $month), $status]);
    }

    private function vatHistory(int $supplierId, string $from, int $payer): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE is_vat_payer = VALUES(is_vat_payer)'
        )->execute([$supplierId, $from, $payer]);
    }
}
