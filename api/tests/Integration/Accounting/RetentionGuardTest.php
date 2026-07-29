<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\RetentionHoldRepository;
use MyInvoice\Service\Accounting\RetentionGuard;
use MyInvoice\Service\Accounting\RetentionViolationException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Brána proti předčasnému smazání účetních záznamů — § 31/§ 32 ZoÚ, § 35a ZDPH.
 *
 * Do jejího doplnění šlo fyzicky smazat zaúčtovaný doklad i archiv účetnictví bez ohledu
 * na stáří, přestože UI i manuál tvrdily, že produkt archivační povinnost naplňuje.
 *
 * Klíčový je {@see testActiveHoldBlocksEvenAfterRetentionExpires()}: uplynutí lhůty podle
 * § 31 samo o sobě nestačí. Trvá-li daňové řízení, uchovává se záznam dál (§ 32) — a tuhle
 * skutečnost systém z účetních dat nezjistí, protože kontrola ani spor se v nich nikde
 * neobjeví. Bez evidence holdu by brána uvolnila právě to, co správce daně prověřuje.
 */
#[Group('integration')]
final class RetentionGuardTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private RetentionGuard $guard;
    private RetentionHoldRepository $holds;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db    = $c->get(Connection::class);
            $this->guard = $c->get(RetentionGuard::class);
            $this->holds = $c->get(RetentionHoldRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'retention_holds'")->fetch() === false) {
            $this->markTestSkipped('Migrace 1151 neproběhla.');
        }
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /** Nedávné období smazat nelze — a hláška musí říct DO KDY, ne jen „nelze". */
    public function testRecentPeriodCannotBeDeleted(): void
    {
        $year = (int) date('Y') - 1;
        $this->seedPeriod($year);

        try {
            $this->guard->assertDeletable($this->supplierId, $year, 'Faktura 2025001');
            self::fail('Brána musela smazání odmítnout.');
        } catch (RetentionViolationException $e) {
            self::assertStringContainsString('§ 31', $e->getMessage());
            self::assertStringContainsString((string) ($year + 10), $e->getMessage(),
                'Hláška musí nést konkrétní datum konce lhůty.');
        }
    }

    /** Po uplynutí nejdelší lhůty už brána nebrání — povinnost skončila. */
    public function testExpiredPeriodPassesGuard(): void
    {
        $year = (int) date('Y') - 15;
        $this->seedPeriod($year);

        $this->guard->assertDeletable($this->supplierId, $year, 'Faktura stará');
        $this->addToAssertionCount(1);
    }

    /**
     * § 32: aktivní zadržení drží záznamy i po uplynutí lhůty podle § 31. Bez toho by
     * brána uvolnila dokumenty, které správce daně právě prověřuje.
     */
    public function testActiveHoldBlocksEvenAfterRetentionExpires(): void
    {
        $year = (int) date('Y') - 15;
        $this->seedPeriod($year);
        $this->holds->place($this->supplierId, $year, 'tax_audit', '12345/26/2000-11111-000000', date('Y-m-d'), null);

        try {
            $this->guard->assertDeletable($this->supplierId, $year, 'Faktura stará');
            self::fail('Zadržení podle § 32 musí smazání zablokovat.');
        } catch (RetentionViolationException $e) {
            self::assertStringContainsString('§ 32', $e->getMessage());
            self::assertStringContainsString('daňová kontrola', $e->getMessage());
            self::assertStringContainsString('12345/26/2000-11111-000000', $e->getMessage(),
                'Uživatel musí vědět, které řízení mazání blokuje.');
        }
    }

    /** Hold bez uvedeného roku platí na celé účetnictví — rozsáhlá kontrola bez vymezení. */
    public function testHoldWithoutYearCoversAllPeriods(): void
    {
        $year = (int) date('Y') - 15;
        $this->seedPeriod($year);
        $this->holds->place($this->supplierId, null, 'litigation', 'Spor 5 C 100/2026', date('Y-m-d'), null);

        $this->expectException(RetentionViolationException::class);
        $this->guard->assertDeletable($this->supplierId, $year, 'Faktura stará');
    }

    /** Uvolněný hold už nebrání — a záznam o něm v evidenci zůstává. */
    public function testReleasedHoldNoLongerBlocks(): void
    {
        $year = (int) date('Y') - 15;
        $this->seedPeriod($year);
        $id = $this->holds->place($this->supplierId, $year, 'appeal', 'Odvolání', date('Y-m-d'), null);

        self::assertTrue($this->holds->release($this->supplierId, $id, date('Y-m-d'), null));
        $this->guard->assertDeletable($this->supplierId, $year, 'Faktura stará');

        self::assertSame([], $this->holds->all($this->supplierId), 'Aktivní hold už žádný není.');
        self::assertCount(1, $this->holds->all($this->supplierId, includeReleased: true),
            'Uvolnění hold nemaže — historie zadržení je sama účetním záznamem.');
    }

    /** Dvojí uvolnění téhož holdu neuspěje — jinak by šlo přepsat datum uvolnění. */
    public function testReleasingTwiceFails(): void
    {
        $id = $this->holds->place($this->supplierId, 2020, 'other', 'Test', date('Y-m-d'), null);

        self::assertTrue($this->holds->release($this->supplierId, $id, date('Y-m-d'), null));
        self::assertFalse($this->holds->release($this->supplierId, $id, date('Y-m-d'), null));
    }

    /**
     * Hospodářský rok posouvá konec lhůty — brána musí brát skutečný `ends_on`
     * z evidence období, ne 31. 12.
     */
    public function testNonCalendarPeriodEndDrivesDeadline(): void
    {
        $year = (int) date('Y') - 9;
        $this->seedPeriod($year, sprintf('%04d-07-01', $year - 1), sprintf('%04d-06-30', $year));

        self::assertSame(
            sprintf('%04d-06-30', $year + 10),
            $this->guard->retainUntil($this->supplierId, $year),
        );
    }

    /** Přehled musí označit uplynulé období — a hold ho vrátí zpátky mezi chráněná. */
    public function testOverviewMarksExpiredAndHeldPeriods(): void
    {
        $old = (int) date('Y') - 15;
        $recent = (int) date('Y') - 1;
        $this->seedPeriod($old);
        $this->seedPeriod($recent);

        $byYear = array_column($this->guard->overview($this->supplierId), null, 'year');
        self::assertTrue($byYear[$old]['expired']);
        self::assertFalse($byYear[$recent]['expired']);

        $this->holds->place($this->supplierId, $old, 'tax_audit', 'Kontrola', date('Y-m-d'), null);
        $byYear = array_column($this->guard->overview($this->supplierId), null, 'year');

        self::assertTrue($byYear[$old]['on_hold']);
        self::assertFalse($byYear[$old]['expired'], 'Zadržené období se nesmí tvářit jako uvolněné.');
    }

    private function seedPeriod(int $year, ?string $startsOn = null, ?string $endsOn = null): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
             VALUES (?, ?, ?, ?, "closed")'
        )->execute([
            $this->supplierId,
            $year,
            $startsOn ?? sprintf('%04d-01-01', $year),
            $endsOn ?? sprintf('%04d-12-31', $year),
        ]);
    }
}
