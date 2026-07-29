<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\SmallAssetRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy Task 11: časové rozlišení drobného majetku (381) jako VOLITELNÁ
 * účetní politika (§7 ZoÚ). Ověřuje 3 režimy náhledu (none/pro_rata/flat_pct),
 * idempotentní zaúčtování MD 381 / D 501 + rozpuštění v N+1 (open_next), a klíčový
 * předpoklad Tasku 10 — že zaúčtovaný source_type='small_asset_accrual' vstupuje do
 * VH před zdaněním (profitBeforeTax). Izolovaný supplier v transakci s rollbackem.
 */
#[Group('integration')]
final class ClosingSmallAssetAccrualTest extends TestCase
{
    // 2091 = nepřestupný rok (365 dnů) → čisté pro_rata podíly.
    private const YEAR = 2091;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private ClosingService $closing;
    private AccountingPeriodRepository $periods;
    private JournalEntryRepository $journal;
    private SmallAssetRepository $smallAssets;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $currencyId = 0;
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
            $this->db          = $container->get(Connection::class);
            $this->posting     = $container->get(PostingService::class);
            $this->closing     = $container->get(ClosingService::class);
            $this->periods     = $container->get(AccountingPeriodRepository::class);
            $this->journal     = $container->get(JournalEntryRepository::class);
            $this->smallAssets = $container->get(SmallAssetRepository::class);
            $seeder            = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId        = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        );
        $stmt->execute(['DM accrual test s.r.o.', $this->czId, 'dm-accrual@example.com', $this->currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);
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

    // ── náhled — 3 režimy ──────────────────────────────────────────────────────

    public function testPreviewNoneDefersNothing(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'none');
        self::assertSame('none', $preview['mode']);
        self::assertEqualsWithDelta(0.0, $preview['total'], 0.001);
        self::assertEqualsWithDelta(0.0, (float) $preview['items'][0]['deferred_amount'], 0.001);
        self::assertEqualsWithDelta(36500.00, $preview['cards_total'], 0.001);
    }

    public function testPreviewProRataDefersFutureUnusedPart(): void
    {
        // Pořízeno 1. 7. 2091 → 184 dnů užitku UPLYNULO do rozvahového dne (spotřeba období),
        // 181 dnů ZBÝVÁ za koncem roku → odloží se BUDOUCÍ část: 36 500 × 181/365 = 18 100.
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'pro_rata');
        self::assertSame('pro_rata', $preview['mode']);
        self::assertSame(365, $preview['period_days']);
        self::assertEqualsWithDelta(18100.00, $preview['total'], 0.001);
        self::assertEqualsWithDelta(181 / 365, (float) $preview['items'][0]['fraction'], 0.0001);
        self::assertEqualsWithDelta(18100.00, (float) $preview['items'][0]['deferred_amount'], 0.001);
        // Součtový invariant: odložená (budoucí) + spotřebovaná (náklad období) = cena.
        $deferred = (float) $preview['total'];
        $consumed = round((float) $preview['cards_total'] - $deferred, 2);
        self::assertEqualsWithDelta(18400.00, $consumed, 0.001);
        self::assertEqualsWithDelta(36500.00, round($deferred + $consumed, 2), 0.001);
    }

    public function testPreviewProRataAcquiredOnFirstDayDefersNothing(): void
    {
        // Pořízeno 1. 1. → celý roční užitek UPLYNUL v tomto období, za rozvahovým dnem nic
        // → odloží se ≈ 0, celá cena je náklad období.
        $this->card('Notebook', self::YEAR . '-01-01', 36500.00);
        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'pro_rata');
        self::assertEqualsWithDelta(0.00, $preview['total'], 0.001);
        self::assertEqualsWithDelta(0.00, (float) $preview['items'][0]['deferred_amount'], 0.001);
        self::assertEqualsWithDelta(0.0, (float) $preview['items'][0]['fraction'], 0.0001);
        // Součtový invariant: spotřebovaná = celá cena.
        $consumed = round((float) $preview['cards_total'] - (float) $preview['total'], 2);
        self::assertEqualsWithDelta(36500.00, $consumed, 0.001);
    }

    public function testPreviewProRataAcquiredOnLastDayDefersAlmostAll(): void
    {
        // Pořízeno 31. 12. → uplynul jen 1 den užitku, 364 dnů ZBÝVÁ za rozvahovým dnem
        // → odloží se téměř celá cena: 36 500 × 364/365 = 36 400.
        $this->card('Notebook', self::YEAR . '-12-31', 36500.00);
        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'pro_rata');
        self::assertEqualsWithDelta(36400.00, $preview['total'], 0.001);
        self::assertEqualsWithDelta(36400.00, (float) $preview['items'][0]['deferred_amount'], 0.001);
        self::assertEqualsWithDelta(364 / 365, (float) $preview['items'][0]['fraction'], 0.0001);
        // Součtový invariant: spotřebovaná = zbytek ceny (1 den užitku).
        $consumed = round((float) $preview['cards_total'] - (float) $preview['total'], 2);
        self::assertEqualsWithDelta(100.00, $consumed, 0.001);
        self::assertEqualsWithDelta(36500.00, round((float) $preview['total'] + $consumed, 2), 0.001);
    }

    public function testPreviewFlatPct(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'flat_pct', 50.0);
        self::assertSame('flat_pct', $preview['mode']);
        self::assertEqualsWithDelta(18250.00, $preview['total'], 0.001);
        self::assertEqualsWithDelta(50.0, (float) $preview['pct'], 0.001);
    }

    public function testPreviewFlatPctRequiresValidPercent(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->expectException(ClosingException::class);
        $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'flat_pct', 150.0);
    }

    // ── zaúčtování — idempotence, podvojnost, mazání ───────────────────────────

    public function testRunPostsDoubleEntry381Over501AndIsIdempotent(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $r1 = $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);
        self::assertEqualsWithDelta(18250.00, (float) $r1['total'], 0.001);

        $entry = $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId));
        self::assertNotNull($entry, 'Časové rozlišení musí být zaúčtované se source small_asset_accrual/period_id.');
        $entryId = (int) $entry['id'];
        $lines = $this->entryLines($entryId);
        self::assertEqualsWithDelta(18250.00, $this->sideAmount($lines, '381', 'debit'), 0.001);
        self::assertEqualsWithDelta(18250.00, $this->sideAmount($lines, '501', 'credit'), 0.001);

        // Re-run se změnou režimu (oprava) → tentýž zápis, jiná částka (in-place rewrite).
        // pro_rata karty z 1. 7.: odloží se BUDOUCÍ část 36 500 × 181/365 = 18 100.
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'pro_rata', null, $this->rv(), $this->meta());
        $entry2 = $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId));
        self::assertSame($entryId, (int) $entry2['id'], 'Re-run nesmí vytvořit duplicitní zápis.');
        self::assertEqualsWithDelta(18100.00, $this->sideAmount($this->entryLines($entryId), '381', 'debit'), 0.001);

        // Přepnutí na none → nulový návrh maže zápis.
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'none', null, $this->rv(), $this->meta());
        self::assertNull(
            $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId)),
            'Režim none musí odložený zápis smazat.',
        );
    }

    public function testRunPersistsPolicyOntoPeriodNotFirm(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 40.0, $this->rv(), $this->meta(), 1000000.00);

        // Politika §DM patří na OBDOBÍ (per-period), ne na firmu.
        $policy = $this->periods->getAccrualPolicy($this->supplierId, $this->periodId);
        self::assertSame('flat_pct', $policy['small_asset_accrual_mode']);
        self::assertEqualsWithDelta(40.0, (float) $policy['small_asset_accrual_pct'], 0.001);

        // Firemní default se uzávěrkou NESMÍ přepsat (zůstává na seedu 'none').
        $stmt = $this->db->pdo()->prepare(
            'SELECT small_asset_accrual_mode, small_asset_accrual_pct FROM accounting_supplier_settings WHERE supplier_id = ?'
        );
        $stmt->execute([$this->supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        // Buď žádný řádek (uzávěrka firmu nezaložila), nebo firma zůstala na defaultu 'none'.
        if ($row !== false) {
            self::assertSame('none', (string) $row['small_asset_accrual_mode'], 'Uzávěrka nesmí přepsat firemní §DM default.');
            self::assertNull($row['small_asset_accrual_pct']);
        } else {
            self::assertFalse($row);
        }
    }

    /**
     * Klíčový invariant §DM: uzávěrka JEDNOHO období nesmí změnit politiku JINÉHO období.
     * Účetní scénář — FY(N) zkrácené první období s flat_pct 50 %, FY(N+1) bez odkladu (none).
     */
    public function testRunOnOnePeriodDoesNotAffectAnotherPeriod(): void
    {
        // Druhé (navazující) období — čerstvě založené zdědí firemní default (none).
        $nextYear = self::YEAR + 1;
        $nextPeriodId = $this->periods->create(
            $this->supplierId,
            $nextYear,
            $nextYear . '-01-01',
            $nextYear . '-12-31',
        );
        $before = $this->periods->getAccrualPolicy($this->supplierId, $nextPeriodId);
        self::assertSame('none', $before['small_asset_accrual_mode']);
        self::assertNull($before['small_asset_accrual_pct']);

        // Uzavři PRVNÍ období s flat_pct 50 %.
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        // První období si drží flat_pct 50 %.
        $first = $this->periods->getAccrualPolicy($this->supplierId, $this->periodId);
        self::assertSame('flat_pct', $first['small_asset_accrual_mode']);
        self::assertEqualsWithDelta(50.0, (float) $first['small_asset_accrual_pct'], 0.001);

        // DRUHÉ období zůstalo netknuté (none) — a jeho pre-fill náhledu to reflektuje.
        $after = $this->periods->getAccrualPolicy($this->supplierId, $nextPeriodId);
        self::assertSame('none', $after['small_asset_accrual_mode'], 'Uzávěrka jednoho období nesmí měnit politiku jiného.');
        self::assertNull($after['small_asset_accrual_pct']);

        // Pre-fill náhledu druhého období (mode=null) vrací jeho VLASTNÍ politiku (none).
        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $nextPeriodId);
        self::assertSame('none', $preview['mode']);
    }

    /**
     * Pre-fill náhledu (mode=null) reflektuje politiku ULOŽENOU na období (ne firmu):
     * po zaúčtování flat_pct 50 % vrací náhled bez režimu opět flat_pct 50 %.
     */
    public function testPreviewPrefillReflectsPeriodPolicy(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);

        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId);
        self::assertSame('flat_pct', $preview['mode']);
        self::assertEqualsWithDelta(50.0, (float) $preview['pct'], 0.001);
        self::assertEqualsWithDelta(18250.00, $preview['total'], 0.001);
    }

    // ── rozpuštění v N+1 (open_next) ───────────────────────────────────────────

    public function testAccrualIsReleasedInNextPeriodOnOpenNext(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->driveCloseWithAccrual('flat_pct', 50.0);

        // Defer zápis N přežil uzavření knih.
        $defer = $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrual($this->periodId));
        self::assertNotNull($defer);

        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $open = $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        self::assertNotNull($open['small_asset_release_entry_id'], 'Open_next musí rozpustit odklad v N+1.');
        $release = $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($this->periodId));
        self::assertNotNull($release);
        $lines = $this->entryLines((int) $release['id']);
        // Zrcadlo defer zápisu: MD 501 / D 381 = náklad se objeví v N+1.
        self::assertEqualsWithDelta(18250.00, $this->sideAmount($lines, '501', 'debit'), 0.001);
        self::assertEqualsWithDelta(18250.00, $this->sideAmount($lines, '381', 'credit'), 0.001);
        self::assertSame((self::YEAR + 1) . '-01-01', (string) $release['entry_date']);
    }

    /**
     * Regrese: období, které odloží drobný majetek na 381, šlo NATRVALO zablokovat
     * proti znovuotevření. Open_next zrcadlí rozpouštěcí zápis (§DM release) do N+1
     * se source_id ve vysokém RELEASE pásmu a period_id = N+1. hasClosingEntries()
     * ho dřív počítala jako „vlastní uzávěrku N+1" → revert open_next hodil
     * invalid_status_transition a release v N+1 osiřel. Teď: revert projde a release
     * se smaže spolu s krokem.
     */
    public function testOpenNextRevertIsNotBlockedByOwnAccrualReleaseAndDeletesIt(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->driveCloseWithAccrual('flat_pct', 50.0);
        $this->closing->closeBooks($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        // Sanity: rozpouštěcí zápis v N+1 existuje před revertem.
        self::assertNotNull(
            $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($this->periodId)),
            'Sanity: §DM release v N+1 má existovat před revertem.'
        );

        // Revert open_next NESMÍ být zablokován vlastním release zápisem (jádro regrese).
        $this->closing->revertStep($this->supplierId, $this->periodId, 'open_next', $this->rv(), $this->meta());

        // …a release v N+1 musí zmizet, jinak by osiřel a nafoukl náklady dalšího roku.
        self::assertNull(
            $this->journal->findBySource($this->supplierId, 'small_asset_accrual', ClosingSourceId::smallAssetAccrualRelease($this->periodId)),
            'Revert open_next musí smazat rozpouštěcí zápis §DM v N+1.'
        );
    }

    // ── Task 10 předpoklad: accrual vstupuje do VH před zdaněním ────────────────

    public function testAccrualIncreasesProfitBeforeTax(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $vhBefore = $this->profitBeforeTax();
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 1000000.00);
        $vhAfter = $this->profitBeforeTax();

        // D 501 credit o 18 250 zvýší VH (náhled ř.10) přesně o odloženou částku —
        // jádro „reálných čísel" DPPO náhledu. Kdyby byl accrual zamětený jako 'closing',
        // profitBeforeTax by ho vyloučil a předpoklad by selhal.
        self::assertEqualsWithDelta(18250.00, $vhAfter - $vhBefore, 0.001);
    }

    // ── EP-15: metodika drobného majetku ───────────────────────────────────────

    public function testFlatPctBlockedWithoutMaterialityLimit(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        try {
            $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), null);
            self::fail('Paušál bez zdokumentovaného limitu významnosti měl být odmítnut.');
        } catch (ClosingException $e) {
            self::assertSame('flat_pct_not_documented', $e->errorCode);
        }
    }

    public function testFlatPctBlockedWhenBaseExceedsMaterialityLimit(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00);
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        try {
            // Báze karet 36 500 > limit 10 000 → významný soubor → paušál odmítnut.
            $this->closing->runSmallAssetAccrual($this->supplierId, $this->periodId, 'flat_pct', 50.0, $this->rv(), $this->meta(), 10000.00);
            self::fail('Paušál nad limitem významnosti měl být odmítnut.');
        } catch (ClosingException $e) {
            self::assertSame('flat_pct_not_material', $e->errorCode);
        }
    }

    public function testProRataUsesDocumentedUsefulMonthsNotPeriodLength(): void
    {
        // Karta uvedená do užívání 1.10., doložená doba 24 měsíců → odloží se BUDOUCÍ část
        // 24měsíčního intervalu za rozvahovým dnem (~638/730), NE roční proxy (~273/365).
        $id = $this->card('Server', self::YEAR . '-10-01', 24000.00);
        $this->setDuration($id, self::YEAR . '-10-01', 24);

        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'pro_rata', null);
        $item = $preview['items'][0];
        self::assertSame(24, $item['useful_months']);
        self::assertSame(self::YEAR . '-10-01', $item['in_use_date']);
        // Doložená doba odloží výrazně víc než roční proxy (17 951) — přes 20 000.
        self::assertGreaterThan(20000.0, (float) $item['deferred_amount'], 'Doložená doba 24 měs. odloží víc než roční proxy.');
        self::assertLessThan(22000.0, (float) $item['deferred_amount']);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function setDuration(int $id, string $inUseDate, int $months): void
    {
        $this->db->pdo()->prepare('UPDATE small_assets SET put_into_use_date = ?, useful_months = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$inUseDate, $months, $id, $this->supplierId]);
    }

    private function card(string $name, string $acquisitionDate, float $price): int
    {
        return $this->smallAssets->insert($this->supplierId, [
            'name' => $name,
            'acquisition_date' => $acquisitionDate,
            'price' => $price,
            'quantity' => 1,
            'unit_price' => $price,
            'status' => 'in_use',
        ], $this->userId);
    }

    /** Zaúčtuje řádek drobného majetku na 501 přes přijatou fakturu (expense_kind='small_asset'),
     *  aby smallAssetReport::expenseBreakdown vrátil daný obrat 501.200 (báze flat_pct). */
    private function expense501(float $net): void
    {
        $pdo = $this->db->pdo();
        $vat = round($net * 0.21, 2);
        $with = round($net + $vat, 2);
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $issue = self::YEAR . '-08-01';
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, "Ulice 1", "Praha", "11000", ?, "dm-vendor@example.com", ?)'
        )->execute([$this->supplierId, 'Dodavatel DM s.r.o.', $this->czId, $this->currencyId]);
        $vendorId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind,
                 vat_deduction, issue_date, tax_date, due_date, received_at, currency_id, reverse_charge,
                 is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", "invoice", "full", ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, 'DM501-' . self::YEAR, $issue, $issue, $issue, $issue, $this->currencyId, $net, $vat, $with, $this->userId]);
        $piId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, expense_kind)
             VALUES (?, 'Drobný majetek', 1, 'ks', ?, ?, 21.00, ?, ?, ?, 0, 'small_asset')"
        )->execute([$piId, $net, $vatRateId, $net, $vat, $with]);
    }

    /**
     * §DM báze flat_pct = ČISTÝ OBRAT 501.200 (net dobropisů), NE báze karet. Účetní odkládá
     * % ze skutečného nákladu; evidence karet bývá neúplná / obsahuje i majetek vrácený v témže
     * období. Per-karta deferred_amount je proto u flat_pct null (rozhoduje total).
     */
    public function testFlatPctDefersPercentOf501TurnoverNotCardBase(): void
    {
        $this->card('Notebook', self::YEAR . '-07-01', 36500.00); // cards_total 36 500
        $this->expense501(50000.00);                              // obrat 501.200 = 50 000

        $preview = $this->closing->smallAssetAccrualPreview($this->supplierId, $this->periodId, 'flat_pct', 50.0);
        self::assertEqualsWithDelta(50000.00, $preview['breakdown_501_small_asset'], 0.001);
        self::assertEqualsWithDelta(36500.00, $preview['cards_total'], 0.001);
        // 50 % z obratu 501 (25 000), NE 50 % z karet (18 250).
        self::assertEqualsWithDelta(25000.00, $preview['total'], 0.001);
        self::assertNull($preview['items'][0]['deferred_amount'], 'U flat_pct je per-karta deferred_amount null.');
    }

    /** start → precheck → povinné kroky, s zaúčtovaným rozlišením v kroku deferrals. */
    private function driveCloseWithAccrual(string $mode, ?float $pct, ?float $materialityLimit = 1000000.00): void
    {
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $this->closing->start($sid, $pid, $this->rv(), $this->meta());
        $this->closing->runPrecheck($sid, $pid, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'depreciation', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($sid, $pid, [], $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'estimates', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runSmallAssetAccrual($sid, $pid, $mode, $pct, $this->rv(), $this->meta(), $materialityLimit);
        $this->closing->confirmStep($sid, $pid, 'deferrals', 'done', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'provisions', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'income_tax', 'skipped', null, $this->rv(), $this->meta());
        $this->completeInventory($sid, $pid, $this->userId);
    }

    /** EP-6: dokončí inventarizaci rozvahových účtů (skutečný = účetní → resolved), aby closeBooks neblokoval. */
    private function completeInventory(int $sid, int $pid, ?int $uid): void
    {
        $rv = (int) $this->periods->findById($sid, $pid)['row_version'];
        $items = [];
        foreach ($this->closing->inventoryPreview($sid, $pid)['rows'] as $r) {
            $items[(int) $r['account_id']] = ['counted_balance' => (float) $r['book_balance'], 'resolution' => 'resolved', 'note' => null];
        }
        $this->closing->saveInventory($sid, $pid, $rv, ['complete' => true], $items, ['user_id' => $uid]);
    }

    /** Zrcadlí DppoReturnDataProvider::profitBeforeTax (VH před zdaněním, ř.10). */
    private function profitBeforeTax(): float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END), 0) AS vh
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND e.source_type <> 'closing'
                AND a.account_type IN ('revenue','expense')
                AND a.account_code NOT LIKE '59%'"
        );
        $stmt->execute([$this->supplierId, self::STARTS_ON, self::ENDS_ON]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function rv(): int
    {
        return (int) $this->periods->findById($this->supplierId, $this->periodId)['row_version'];
    }

    /** @return array{user_id:int, posted_by:int} */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }

    /** @return list<array{account_code:string, side:string, amount:float}> */
    private function entryLines(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ?'
        );
        $stmt->execute([$entryId, $this->supplierId]);
        return array_map(static fn (array $r): array => [
            'account_code' => (string) $r['account_code'],
            'side' => (string) $r['side'],
            'amount' => (float) $r['amount'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<array{account_code:string, side:string, amount:float}> $lines */
    private function sideAmount(array $lines, string $code, string $side): float
    {
        $sum = 0.0;
        foreach ($lines as $l) {
            if ($l['account_code'] === $code && $l['side'] === $side) {
                $sum += $l['amount'];
            }
        }
        return round($sum, 2);
    }
}
