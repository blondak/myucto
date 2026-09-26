<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPredecessorGapService;
use MyInvoice\Service\Payroll\Submission\PayrollMonthlyChecklistService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Q15-17: převzatý měsíc, za který předchozí program hlášení JMHZ nepodal.
 * Měsíční přehled dřív tvrdil „žádná otevřená položka" a hlídač termínů
 * mlčel, přestože lhůta uplynula.
 */
#[Group('integration')]
final class JmhzPredecessorGapServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-08';

    private ContainerInterface $container;
    private Connection $db;
    private int $supplierId;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $db = $this->container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        if (!$db->hasTable('payroll_external_jmhz_submissions')) {
            self::markTestSkipped('Migrace historie podání předchozím programem neproběhla.');
        }
        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "active", "2026-09-01", ?, NOW())',
        )->execute([$this->supplierId, $userId]);
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, run_kind, status)
             VALUES (?, ?, "2026-09-10", "takeover", "closed")',
        )->execute([$this->supplierId, self::PERIOD . '-01']);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testTakeoverMonthWithoutSubmissionIsReportedEverywhere(): void
    {
        $gaps = $this->gaps()->missing($this->supplierId, 'production');
        self::assertSame([self::PERIOD], array_column($gaps, 'period'));
        self::assertSame([], $this->gaps()->missing($this->supplierId, 'test'), 'Test prostředí předchozí program nevede.');

        $checklist = $this->container->get(PayrollMonthlyChecklistService::class);
        self::assertInstanceOf(PayrollMonthlyChecklistService::class, $checklist);
        $items = $checklist->checklist($this->supplierId, 'production', self::PERIOD)['items'];
        $rows = array_values(array_filter($items, static fn (array $item): bool => $item['source'] === 'predecessor_jmhz'));
        self::assertCount(1, $rows, 'Měsíční přehled musí nepodané hlášení převzatého měsíce ukázat.');
        self::assertFalse($rows[0]['done']);
        self::assertStringContainsString('8/2026', (string) $rows[0]['action']['reason']);

        $deadlines = $this->container->get(PayrollDeadlineOverviewService::class);
        self::assertInstanceOf(PayrollDeadlineOverviewService::class, $deadlines);
        $references = array_column(
            $deadlines->itemsForWindow($this->supplierId, 'production', '2026-01-01', '2027-12-31'),
            'reference',
        );
        self::assertContains('predecessor_jmhz:' . self::PERIOD, $references, 'Hlídač termínů musí mluvit taky.');
    }

    public function testSentPredecessorSubmissionClosesTheGap(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_external_jmhz_submissions
                (supplier_id, environment, source, source_key, document_kind, period, submission_type, status,
                 payload_ciphertext, payload_hash, payload_sha256)
             VALUES (?, "production", "jmhz_xml", "syn-2026-08", "monthly", ?, "R", "sent", "x", ?, ?)',
        )->execute([$this->supplierId, self::PERIOD, str_repeat("\0", 32), str_repeat('a', 64)]);

        self::assertSame([], $this->gaps()->missing($this->supplierId, 'production'));
    }

    private function gaps(): JmhzPredecessorGapService
    {
        $service = $this->container->get(JmhzPredecessorGapService::class);
        self::assertInstanceOf(JmhzPredecessorGapService::class, $service);

        return $service;
    }
}
