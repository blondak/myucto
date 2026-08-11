<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\InvoiceSeriesCompletenessService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FR3 (vendor audit 2026-08) — report úplnosti číselné řady vydaných dokladů.
 *
 * Klíčový nález z bugreportu, který tenhle test musí ověřit: faktury a dobropisy mohou
 * sdílet jednu řadu (stejná šablona), a kontrola úplnosti to MUSÍ brát dohromady — jinak
 * hlásí falešné mezery přesně tam, kde číslo ve skutečnosti použil ten druhý typ dokladu.
 *
 * Izolováno pod existujícím supplierem (číslovací šablony dočasně přepsané a v tearDown
 * vrácené), doklady rok 2098 uklizené v tearDown. Soft-skip pokud chybí cfg.php.
 */
#[Group('integration')]
final class InvoiceSeriesCompletenessTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private InvoiceSeriesCompletenessService $service;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $clientId = 0;

    /** @var array<string,mixed> */
    private array $originalSupplierRow = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container     = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->service = $container->get(InvoiceSeriesCompletenessService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $stmt = $pdo->prepare(
            'SELECT invoice_number_format, credit_note_number_format, invoice_number_period FROM supplier WHERE id = ?'
        );
        $stmt->execute([$this->supplierId]);
        $this->originalSupplierRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, ic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "FR3 Test Client", "Test 1", "Praha", "11000", ?, "10000004",
                     "fr3@example.com", "cs", ?, 1, 0)'
        );
        $stmt->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();

        $pdo->prepare(
            'UPDATE supplier SET invoice_number_format = ?, credit_note_number_format = ?, invoice_number_period = ?
              WHERE id = ?'
        )->execute([
            $this->originalSupplierRow['invoice_number_format'] ?? null,
            $this->originalSupplierRow['credit_note_number_format'] ?? null,
            $this->originalSupplierRow['invoice_number_period'] ?? null,
            $this->supplierId,
        ]);

        if ($this->clientId !== 0) {
            $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ? AND client_id = ?')
                ->execute([$this->supplierId, $this->clientId]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        $this->db->close();
    }

    private function setTemplates(string $invoiceTpl, string $creditNoteTpl, string $period = 'year'): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET invoice_number_format = ?, credit_note_number_format = ?, invoice_number_period = ?
              WHERE id = ?'
        )->execute([$invoiceTpl, $creditNoteTpl, $period, $this->supplierId]);
    }

    private function insertInvoice(string $varsymbol, string $type = 'invoice'): void
    {
        $issue = self::YEAR . '-06-15';
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1000.00, 210.00, 1210.00, "issued", "1", ?)'
        )->execute([
            $this->supplierId, $varsymbol, $type, $this->clientId, $issue, $issue, $issue,
            $this->currencyId, $this->userId,
        ]);
    }

    public function testSharedSeriesCombinesInvoiceAndCreditNoteNumbers(): void
    {
        // Sdílená šablona (stejný digit skeleton) pro invoice i credit_note.
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');

        $y = self::YEAR;
        $this->insertInvoice("{$y}000001", 'invoice');
        $this->insertInvoice("{$y}000002", 'invoice');
        // 000003 chybí u FAKTUR, ale číslo použil DOBROPIS — nesmí to být hlášeno jako mezera.
        $this->insertInvoice("{$y}000003", 'credit_note');
        $this->insertInvoice("{$y}000004", 'invoice');
        // 000005 chybí OPRAVDU — ani faktura, ani dobropis ho nepoužily.
        $this->insertInvoice("{$y}000006", 'invoice');

        $series = $this->service->build($this->supplierId, self::YEAR);

        self::assertCount(1, $series, 'Sdílený skeleton musí sloučit invoice+credit_note do JEDNÉ řady.');
        $group = $series[0];
        self::assertSame(['invoice', 'credit_note'], $group['types']);
        self::assertCount(1, $group['buckets']);
        $bucket = $group['buckets'][0];
        self::assertSame([5], $bucket['missing'], 'Jen 000005 je skutečná mezera — 000003 pokryl dobropis.');
        self::assertSame(6, $bucket['range_to']);
        self::assertSame(5, $bucket['used_count']);
    }

    public function testDistinctSeriesAreReportedIndependently(): void
    {
        // Odlišné šablony (jiný skeleton) → faktury a dobropisy NESMÍ se míchat.
        $this->setTemplates('F{YYYY}{CCC}', 'D{YYYY}{CCCC}');

        $y = self::YEAR;
        $this->insertInvoice("F{$y}001", 'invoice');
        $this->insertInvoice("F{$y}003", 'invoice'); // 002 chybí

        $this->insertInvoice("D{$y}0001", 'credit_note');
        $this->insertInvoice("D{$y}0002", 'credit_note'); // bez mezery

        $series = $this->service->build($this->supplierId, self::YEAR);

        self::assertCount(2, $series, 'Odlišné šablony zůstávají DVĚ samostatné řady.');
        $byType = [];
        foreach ($series as $s) {
            $byType[$s['types'][0]] = $s;
        }
        self::assertSame([2], $byType['invoice']['buckets'][0]['missing']);
        self::assertSame([], $byType['credit_note']['buckets'][0]['missing']);
    }

    public function testNoGapsReportsEmptyMissingList(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');
        $y = self::YEAR;
        $this->insertInvoice("{$y}000001", 'invoice');
        $this->insertInvoice("{$y}000002", 'credit_note');
        $this->insertInvoice("{$y}000003", 'invoice');

        $series = $this->service->build($this->supplierId, self::YEAR);

        self::assertCount(1, $series);
        self::assertSame([], $series[0]['buckets'][0]['missing']);
    }

    public function testDifferentYearIsNotPolluted(): void
    {
        $this->setTemplates('{YYYY}{CCCCCC}', '{YYYY}{CCCCCC}');
        $this->insertInvoice(self::YEAR . '000001', 'invoice');

        // Report za JINÝ rok nesmí najít doklady z self::YEAR (roční period bucketing).
        $series = $this->service->build($this->supplierId, self::YEAR + 1);

        self::assertSame([], $series, 'Rok bez dokladů nesmí vyrobit falešnou zprávu o mezerách.');
    }
}
