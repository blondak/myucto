<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Codebooks;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Codebooks\CodebookXlsxExporter;
use MyInvoice\Service\Accounting\Codebooks\PostingRulesImportService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy Excel přenosu kontačních pravidel (Epic F5 §7.1, R11).
 * Vše v transakci s rollbackem. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class PostingRulesTransferTest extends TestCase
{
    private const KEY = 'invoice.services.issued';

    private Connection $db;
    private PostingRuleRepository $rules;
    private PostingRulesImportService $service;
    private CodebookXlsxExporter $exporter;

    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db       = $c->get(Connection::class);
            $this->rules    = $c->get(PostingRuleRepository::class);
            $this->service  = $c->get(PostingRulesImportService::class);
            $this->exporter = $c->get(CodebookXlsxExporter::class);
            $seeder         = $c->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/user v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $seeder->seedForSupplier($this->supplierId);
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

    public function testExportImportRoundtrip(): void
    {
        $rules = array_values($this->rules->effectiveMap($this->supplierId));
        $out = $this->exporter->postingRules($rules);

        $report = $this->service->import($this->supplierId, $this->userId, $out['bytes'], 'kontace.xlsx', true);

        self::assertTrue($report['ok']);
        self::assertSame(0, $report['created']);
        self::assertSame(0, $report['updated']);
        self::assertSame(0, $report['failed']);
        self::assertGreaterThan(0, $report['skipped']);
    }

    public function testCreateOverride(): void
    {
        $csv = "klic;md_ucet;d_ucet;priorita\n" . self::KEY . ";315;602;999\n";
        $report = $this->service->import($this->supplierId, $this->userId, $csv, 'kontace.csv', false);

        self::assertTrue($report['ok']);
        self::assertSame('create', $report['rows'][0]['status']);

        $row = $this->overrideRow();
        self::assertNotNull($row);
        self::assertSame('315', $row['debit_account_code']);
        self::assertSame(PostingRuleRepository::OVERRIDE_PRIORITY, (int) $row['priority']); // priorita ze souboru (999) ignorována
    }

    public function testUnknownRuleKeyError(): void
    {
        $csv = "klic;md_ucet;d_ucet\nneexistujici.klic.xyz;311;602\n";
        $report = $this->service->import($this->supplierId, $this->userId, $csv, 'kontace.csv', false);

        self::assertFalse($report['ok']);
        self::assertSame(1, $report['failed']);
        self::assertSame('error', $report['rows'][0]['status']);
    }

    public function testUnknownAccountError(): void
    {
        $csv = "klic;md_ucet;d_ucet\n" . self::KEY . ";ZZZ;602\n";
        $report = $this->service->import($this->supplierId, $this->userId, $csv, 'kontace.csv', false);

        self::assertFalse($report['ok']);
        self::assertSame('error', $report['rows'][0]['status']);
    }

    public function testUpdateExistingOverride(): void
    {
        $first = "klic;md_ucet;d_ucet\n" . self::KEY . ";315;602\n";
        $r1 = $this->service->import($this->supplierId, $this->userId, $first, 'k.csv', false);
        self::assertTrue($r1['ok']);
        self::assertSame('create', $r1['rows'][0]['status']);

        $second = "klic;md_ucet;d_ucet\n" . self::KEY . ";313;602\n";
        $r2 = $this->service->import($this->supplierId, $this->userId, $second, 'k.csv', false);
        self::assertTrue($r2['ok']);
        self::assertSame('update', $r2['rows'][0]['status']);

        // Jediný override řádek, aktualizovaný (žádná duplicita).
        $count = (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM posting_rules WHERE supplier_id = ' . $this->supplierId
            . " AND rule_key = '" . self::KEY . "' AND priority = " . PostingRuleRepository::OVERRIDE_PRIORITY
        )->fetchColumn();
        self::assertSame(1, $count);
        self::assertSame('313', $this->overrideRow()['debit_account_code']);
    }

    /** @return array<string,mixed>|null */
    private function overrideRow(): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM posting_rules WHERE supplier_id = ? AND rule_key = ? AND priority = ?'
        );
        $stmt->execute([$this->supplierId, self::KEY, PostingRuleRepository::OVERRIDE_PRIORITY]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
