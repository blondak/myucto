<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * §2.4 (audit PODVOJNE-AUDIT.md) — "Generované XML není podané XML".
 *
 * Životní cyklus podání: draft → generated → downloaded → submitted → accepted/rejected.
 * Klíčová pravidla, která tento test chrání proti regresi:
 *   - ARCHIVACE (stažení XML) uloží snapshot jako `downloaded` a NEPOSOUVÁ daňový zámek;
 *   - teprve MARK SUBMITTED (prokazatelné podání) posune zámek u uzavřeného VAT období;
 *   - probíhající/budoucí období nezamyká ani při podání;
 *   - základnou opravného/následného tvrzení je prokazatelně podané `submitted` i `accepted`.
 *
 * Izolace: vše v transakci s rollbackem, zámek se resetuje na null.
 */
#[Group('integration')]
final class TaxSubmissionLifecycleTest extends TestCase
{
    private Connection $db;
    private TaxSubmissionArchiver $archiver;
    private TaxSubmissionRepository $repo;
    private AccountingSupplierSettingsRepository $settings;

    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db       = $c->get(Connection::class);
            $this->archiver = $c->get(TaxSubmissionArchiver::class);
            $this->repo     = $c->get(TaxSubmissionRepository::class);
            $this->settings = $c->get(AccountingSupplierSettingsRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->settings->setLockedUntil($this->supplierId, null);
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

    /** Přímý seed "staženého" (downloaded) snapshotu s danou validací — obchází XSD. */
    private function seedDownloaded(int $year, ?int $month, ?int $quarter, string $validation = 'passed'): int
    {
        return $this->repo->archive(
            $this->supplierId, 'dphkh1', $year, $month, $quarter,
            '<?xml version="1.0"?><Pisemnost/>', ['t' => 1],
            $validation, [], $this->userId, 'B', 'downloaded',
        );
    }

    // ── Archivace (stažení) NEZAMYKÁ a ukládá jako downloaded ──────────────────

    public function testArchiveMarksDownloadedAndDoesNotLock(): void
    {
        // Uzavřené minulé období — kdyby archivace zamykala, posunula by zámek.
        $res = $this->archiver->archive(
            $this->supplierId, 'dphkh1', 2020, 5, null,
            '<?xml version="1.0"?><Pisemnost/>', [], $this->userId, true, 'B',
        );
        self::assertSame('downloaded', $res['status']);

        $row = $this->repo->find((int) $res['submission_id'], $this->supplierId);
        self::assertSame('downloaded', $row['status'] ?? null);

        self::assertNull(
            $this->settings->getLockedUntil($this->supplierId),
            'Stažení/archivace XML NESMÍ posunout daňový zámek (§2.4).',
        );
    }

    // ── Podání posune zámek u uzavřeného VAT období ───────────────────────────

    public function testMarkSubmittedAdvancesLockForPastPeriod(): void
    {
        $id = $this->seedDownloaded(2020, 5, null);
        self::assertNull($this->settings->getLockedUntil($this->supplierId), 'Před podáním bez zámku.');

        $row = $this->archiver->markSubmitted($id, $this->supplierId, '2020-06-25 10:00:00', 'EPO-12345', $this->userId);

        self::assertNotNull($row);
        self::assertSame('submitted', $row['status']);
        self::assertNotEmpty($row['submitted_at']);
        self::assertSame('EPO-12345', $row['submission_ref']);
        self::assertSame(
            '2020-05-31',
            $this->settings->getLockedUntil($this->supplierId),
            'Teprve podání posune zámek na konec vykázaného období (§2.4).',
        );
    }

    // ── Podání probíhajícího období zámek NEposouvá ───────────────────────────

    public function testMarkSubmittedCurrentPeriodDoesNotLock(): void
    {
        $curYear  = (int) date('Y');
        $curMonth = (int) date('n');
        $id = $this->seedDownloaded($curYear, $curMonth, null);

        $row = $this->archiver->markSubmitted($id, $this->supplierId, date('Y-m-d H:i:s'), null, $this->userId);

        self::assertSame('submitted', $row['status']);
        self::assertNull(
            $this->settings->getLockedUntil($this->supplierId),
            'Podání probíhajícího období nesmí zamknout účtování.',
        );
    }

    // ── findLatestForPeriod bere JEN podané (submitted) ───────────────────────

    public function testFindLatestReturnsOnlySubmitted(): void
    {
        $id = $this->repo->archive(
            $this->supplierId, 'dphdp3', 2020, 5, null,
            '<?xml version="1.0"?><Pisemnost/>', [], 'passed', [], $this->userId, 'B', 'downloaded',
        );

        self::assertNull(
            $this->repo->findLatestForPeriod($this->supplierId, 'dphdp3', 2020, 5, null, ['B', 'O']),
            'Pouze stažený (downloaded) snapshot NESMÍ být základ opravného/následného tvrzení.',
        );

        $this->archiver->markSubmitted($id, $this->supplierId, '2020-06-25 10:00:00', 'EPO-9', $this->userId);

        $found = $this->repo->findLatestForPeriod($this->supplierId, 'dphdp3', 2020, 5, null, ['B', 'O']);
        self::assertNotNull($found, 'Po podání je snapshot základem tvrzení.');
        self::assertSame($id, (int) $found['id']);
        self::assertSame('submitted', $found['status']);
    }

    public function testAcceptedRemainsBasisForCorrectiveAndAmendedReturns(): void
    {
        $baseId = $this->repo->archive(
            $this->supplierId, 'dphdp3', 2020, 6, null,
            '<?xml version="1.0"?><Pisemnost><DPHDP3/></Pisemnost>',
            [], 'passed', [], $this->userId, 'B', 'downloaded',
        );
        $this->archiver->markSubmitted(
            $baseId,
            $this->supplierId,
            '2020-07-20 10:00:00',
            'EPO-ACCEPTED-BASE',
            $this->userId,
        );
        $this->db->pdo()->prepare(
            "UPDATE tax_submissions SET status = 'accepted' WHERE id = ?"
        )->execute([$baseId]);

        $latest = $this->repo->findLatestForPeriod(
            $this->supplierId,
            'dphdp3',
            2020,
            6,
            null,
            ['B', 'O'],
        );
        self::assertNotNull($latest);
        self::assertSame($baseId, (int) ($latest['id'] ?? 0));
        self::assertSame('accepted', $latest['status']);

        $amendmentId = $this->repo->archive(
            $this->supplierId, 'dphdp3', 2020, 6, null,
            '<?xml version="1.0"?><Pisemnost><DPHDP3/></Pisemnost>',
            [], 'passed', [], $this->userId, 'D', 'downloaded',
        );
        $this->archiver->markSubmitted(
            $amendmentId,
            $this->supplierId,
            '2020-08-01 10:00:00',
            'EPO-ACCEPTED-AMENDMENT',
            $this->userId,
        );
        $this->db->pdo()->prepare(
            "UPDATE tax_submissions SET status = 'accepted' WHERE id = ?"
        )->execute([$amendmentId]);

        $amendments = $this->repo->findAmendmentsForPeriod(
            $this->supplierId,
            'dphdp3',
            2020,
            6,
            null,
        );
        self::assertContains(
            $amendmentId,
            array_map(static fn (array $row): int => (int) $row['id'], $amendments),
        );
    }
}
