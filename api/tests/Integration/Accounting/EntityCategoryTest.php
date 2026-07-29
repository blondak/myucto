<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\EntityCategoryService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy kategorizace účetní jednotky (Epic F2, T15, §1d ZoÚ po
 * novele 316/2025 Sb.): prahy mikro/malá, pravidlo „nepřekračuje ≥2 ze 3
 * kritérií", override rozsahu a pravidlo 2 po sobě jdoucích období (R11).
 *
 * Vše běží v jedné transakci, kterou tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class EntityCategoryTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private EntityCategoryService $categories;
    private AccountingSupplierSettingsRepository $settings;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db         = $container->get(Connection::class);
            $this->posting    = $container->get(PostingService::class);
            $this->categories = $container->get(EntityCategoryService::class);
            $this->settings   = $container->get(AccountingSupplierSettingsRepository::class);
            $this->periods    = $container->get(AccountingPeriodRepository::class);
            $seeder           = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }
        $hasSeed = (int) $pdo->query(
            "SELECT COUNT(*) FROM statement_versions WHERE version_code = 'vyhl500-2002/2024'"
        )->fetchColumn();
        if ($hasSeed < 2) {
            $this->markTestSkipped('Seed výkazů 1012 není aplikovaný (statement_versions).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        // Izolovaný supplier (kopie FK hodnot z prvního): kumulativní PS rozvahových
        // účtů (R6) jde přes celou historii deníku, sdílený dev supplier s reálnými
        // zápisy by rozbil bilanční asserty a previousPeriod.
        $isoStmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        );
        $isoStmt->execute(['Izolovaný test s.r.o.', 'izolace@example.com', $this->supplierId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    // ── T15 ───────────────────────────────────────────────────────────────

    public function testDefaultIsMicroBelowThresholds(): void
    {
        // žádná data, žádní zaměstnanci → pod 11M/22M/10 → mikro
        $this->settings->upsert($this->supplierId, null, null);
        $result = $this->categories->evaluate($this->supplierId, $this->periodId);

        self::assertSame('micro', $result['category']);
        self::assertSame('micro', $result['raw_current']);
        self::assertNull($result['raw_previous'], 'Předchozí období neexistuje.');
        self::assertSame('micro', $result['scope']);
        self::assertNull($result['scope_override']);
        self::assertSame(0, $result['criteria']['employees'], 'avg_employees NULL → 0 (R10).');
        self::assertSame(0, self::cents($result['criteria']['net_turnover']));
        self::assertArrayHasKey('micro', $result['thresholds']);
        self::assertArrayHasKey('small', $result['thresholds']);
        self::assertArrayHasKey('medium', $result['thresholds']);
    }

    public function testSmallWhenTwoCriteriaExceeded(): void
    {
        // obrat nad 22M + 60 zaměstnanců → mikro překročena ≥2 kritérii → malá
        $this->settings->upsert($this->supplierId, 60, null);
        $this->manual([
            self::l('311', 'debit', 30000000.00),
            self::l('602', 'credit', 30000000.00),
        ], self::YEAR . '-06-15');

        $result = $this->categories->evaluate($this->supplierId, $this->periodId);

        self::assertSame(self::cents(30000000.00), self::cents($result['criteria']['net_turnover']), 'Čistý obrat = tržby 602 (R9).');
        self::assertSame(60, $result['criteria']['employees']);
        self::assertSame('small', $result['raw_current'], 'Obrat > 22M a zaměstnanci > 10 → není mikro; u malé překročeno jen 1 kritérium.');
        self::assertSame('small', $result['category'], 'Bez předchozího období platí raw_current.');
        self::assertSame('small', $result['scope']);
    }

    public function testScopeOverrideWins(): void
    {
        $this->settings->upsert($this->supplierId, 0, 'full');

        $result = $this->categories->evaluate($this->supplierId, $this->periodId);

        self::assertSame('micro', $result['category'], 'Kategorie se počítá dál.');
        self::assertSame('full', $result['scope'], 'Override rozsahu vyhrává.');
        self::assertSame('full', $result['scope_override']);
    }

    public function testCategoryChangeRequiresTwoConsecutivePeriods(): void
    {
        // 2098 bez dat (mikro), 2099 s obratem nad prahy → raw se liší jen 1
        // období → efektivní kategorie zůstává mikro (R11).
        $prevYear = self::YEAR - 1;
        $this->periods->create($this->supplierId, $prevYear, $prevYear . '-01-01', $prevYear . '-12-31');
        $this->settings->upsert($this->supplierId, 60, null);
        $this->manual([
            self::l('311', 'debit', 30000000.00),
            self::l('602', 'credit', 30000000.00),
        ], self::YEAR . '-06-15');

        $result = $this->categories->evaluate($this->supplierId, $this->periodId);

        self::assertSame('small', $result['raw_current']);
        self::assertNotNull($result['raw_previous']);
        self::assertNotSame($result['raw_current'], $result['raw_previous'], 'Raw kategorie se mezi obdobími liší.');
        self::assertSame($result['raw_previous'], $result['category'], 'Změna raw jen 1 období → efektivní kategorie se nemění.');
        self::assertSame($result['category'], 'micro');
        self::assertSame('micro', $result['scope']);
        self::assertSame('micro', $this->categories->statementScope($this->supplierId, $this->periodId));
    }

    public function testCategoryChangesAfterTwoConsecutiveClosedPeriods(): void
    {
        // 2097 i 2098 s obratem nad prahy (raw malá) → dle §1e odst. 2 platí
        // malá od následujícího období 2099, i když běžný rok je pod prahy.
        foreach ([self::YEAR - 2, self::YEAR - 1] as $year) {
            $this->periods->create($this->supplierId, $year, $year . '-01-01', $year . '-12-31');
            $this->manual([
                self::l('311', 'debit', 30000000.00),
                self::l('602', 'credit', 30000000.00),
            ], $year . '-06-15');
        }
        $this->settings->upsert($this->supplierId, 60, null);

        $result = $this->categories->evaluate($this->supplierId, $this->periodId);

        self::assertSame('micro', $result['raw_current'], 'Běžný rok bez obratu → raw mikro.');
        self::assertSame('small', $result['raw_previous']);
        self::assertSame('small', $result['category'], 'Dvě po sobě jdoucí uzavřená období malá → změna platí od následujícího období.');
        self::assertSame('small', $result['scope']);
    }

    // ── N4 (adversariální review D5): parita fallbacku bez zmraženého záznamu ──

    /**
     * Regrese N4: rawsForClosedPeriods() pro období BEZ záznamu v
     * entity_category_history (žádná uzávěrka / data z doby před migrací 1040)
     * MUSÍ dát STEJNÝ výsledek jako přímý přepočet (criteriaFor()+classify()) —
     * žádná tichá regrese na default/prázdnou hodnotu. Scénář se dvěma minulými
     * obdobími: P1 explicitně ZMRAŽENÉ (freeze() zavolán mimo ClosingService —
     * přímé volání servisní metody), P2 BEZ záznamu (fallback větev). Obě mají
     * odlišný, netriviální obrat nad prahem mikro (→ raw 'small'), aby test
     * chytil i regresi na tichý default 'micro' (0 kritérií).
     */
    public function testFallbackForUnfrozenPeriodMatchesDirectRecompute(): void
    {
        $this->settings->upsert($this->supplierId, 60, null); // zaměstnanci nad práh mikro (10)

        $p1Year = self::YEAR - 2;
        $p2Year = self::YEAR - 1;
        $p1Id = $this->periods->create($this->supplierId, $p1Year, $p1Year . '-01-01', $p1Year . '-12-31');
        $p2Id = $this->periods->create($this->supplierId, $p2Year, $p2Year . '-01-01', $p2Year . '-12-31');

        // P1: obrat 30M → 'small'; explicitně zmrazeno.
        $this->manual([
            self::l('311', 'debit', 30000000.00),
            self::l('602', 'credit', 30000000.00),
        ], $p1Year . '-06-15');
        $this->categories->freeze($this->supplierId, $p1Id);

        // P2: JINÝ obrat (25M, také nad prahem 22M → 'small'), ŽÁDNÉ freeze() volání —
        // rawsForClosedPeriods() pro P2 musí spadnout do fallback větve (přepočet).
        $this->manual([
            self::l('311', 'debit', 25000000.00),
            self::l('602', 'credit', 25000000.00),
        ], $p2Year . '-06-15');

        // sanity: P1 má zmražený řádek, P2 ne (ověřuje, že test skutečně cvičí obě větve)
        $historyCount = fn (int $periodId): int => (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM entity_category_history
              WHERE supplier_id = {$this->supplierId} AND period_id = {$periodId}"
        )->fetchColumn();
        self::assertSame(1, $historyCount($p1Id), 'P1 má zmražený řádek (freeze() zavolán).');
        self::assertSame(0, $historyCount($p2Id), 'P2 NEMÁ zmražený řádek — test cvičí fallback větev.');

        // nezávislý přímý přepočet P2 (evaluate() vždy počítá raw_current čerstvě,
        // nikdy z historie) — referenční hodnota, se kterou fallback větev musí souhlasit.
        $directP2 = $this->categories->evaluate($this->supplierId, $p2Id);
        self::assertSame('small', $directP2['raw_current'], 'Přímý přepočet P2: obrat 25M nad prahem 22M → small (ne tichý default micro).');

        // evaluate() aktuálního období čte P2 (poslední minulé období) přes fallback větev
        // rawsForClosedPeriods() — MUSÍ dát identický raw jako přímý přepočet výše.
        $current = $this->categories->evaluate($this->supplierId, $this->periodId);
        self::assertSame(
            $directP2['raw_current'],
            $current['raw_previous'],
            'Fallback větev (P2 bez zmraženého záznamu) == přímý přepočet criteriaFor()+classify() — žádná tichá regrese D5.',
        );
        self::assertSame('small', $current['raw_previous'], 'Explicitně: fallback nesmí spadnout na default micro.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     * @param array<string,mixed> $meta
     */
    private function manual(array $lines, string $date, array $meta = []): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            $lines,
            array_merge(['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId], $meta),
        );
    }

    /**
     * @return array{account_code:string, side:string, amount:float}
     */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
