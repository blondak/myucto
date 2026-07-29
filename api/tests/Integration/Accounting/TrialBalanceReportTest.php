<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy obratové předvahy (Epic F2, T1–T4): kontrolní rovnice
 * Σ obrat MD = Σ obrat D = obrat deníku (haléře), drafty mimo agregace,
 * PS kontinuita (R6), tenant izolace a roll-up analytik na syntetiku (R15).
 *
 * Vše běží v jedné transakci, kterou tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class TrialBalanceReportTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private TrialBalanceService $trialBalance;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
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
            $this->db           = $container->get(Connection::class);
            $this->posting      = $container->get(PostingService::class);
            $this->trialBalance = $container->get(TrialBalanceService::class);
            $this->periods      = $container->get(AccountingPeriodRepository::class);
            $this->seeder       = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
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

        $this->seeder->seedForSupplier($this->supplierId);
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

    // ── T1 ────────────────────────────────────────────────────────────────

    public function testTurnoverBalancedMatchesJournalAndDraftsExcluded(): void
    {
        // sada zápisů: 311/602/343, 518/321, pokladna 211/261, draft, storno pár
        $this->manual([
            self::l('311', 'debit', 1210.00),
            self::l('602', 'credit', 1000.00),
            self::l('343', 'credit', 210.00),
        ], self::YEAR . '-03-10');
        $this->manual([
            self::l('518', 'debit', 500.00),
            self::l('343', 'debit', 105.00),
            self::l('321', 'credit', 605.00),
        ], self::YEAR . '-03-12');
        $this->manual([
            self::l('211', 'debit', 500.00),
            self::l('261', 'credit', 500.00),
        ], self::YEAR . '-04-01');
        // draft — NESMÍ vstoupit do obratů
        $this->manual([
            self::l('518', 'debit', 50.00),
            self::l('211', 'credit', 50.00),
        ], self::YEAR . '-05-01', ['posted' => false]);
        // storno pár — obě strany zůstávají v obratech (R3)
        $stornoOriginal = $this->manual([
            self::l('211', 'debit', 200.00),
            self::l('602', 'credit', 200.00),
        ], self::YEAR . '-06-01');
        $this->posting->reverse($this->supplierId, $stornoOriginal, ['entry_date' => self::YEAR . '-06-15', 'posted_by' => $this->userId]);

        $data = $this->trialBalance->build($this->supplierId, $this->periodId, null, null);

        // Σ obrat MD == Σ obrat D == obrat deníku (v haléřích)
        $tMd = self::cents($data['totals']['turnover_md']);
        $tD  = self::cents($data['totals']['turnover_d']);
        self::assertSame($tMd, $tD, 'Σ obrat MD == Σ obrat D (haléře).');
        self::assertSame(self::cents(2715.00), $tMd, 'Obrat = posted zápisy vč. storno páru, bez draftu.');
        self::assertSame($tMd, self::cents($data['checks']['journal_turnover_md']), 'Obrat předvahy == obrat deníku MD.');
        self::assertSame($tD, self::cents($data['checks']['journal_turnover_d']), 'Obrat předvahy == obrat deníku D.');
        self::assertTrue($data['checks']['turnover_balanced']);
        self::assertTrue($data['checks']['matches_journal']);

        self::assertSame(1, $data['draft_count'], 'V rozsahu je právě 1 draft.');

        $r518 = $this->rowByCode($data['rows'], '518');
        self::assertNotNull($r518);
        self::assertSame(self::cents(500.00), self::cents($r518['turnover_md']), 'Draft částka 50 NENÍ v obratu 518.');
        $r211 = $this->rowByCode($data['rows'], '211');
        self::assertNotNull($r211);
        self::assertSame(self::cents(200.00), self::cents($r211['turnover_d']), 'Obrat 211 D = jen storno zrcadlo (200), draft 50 NENÍ zahrnut.');
    }

    // ── T2 ────────────────────────────────────────────────────────────────

    public function testOpeningBalanceContinuityFromMidPeriod(): void
    {
        $this->manual([
            self::l('311', 'debit', 1000.00),
            self::l('602', 'credit', 1000.00),
        ], self::YEAR . '-01-15');

        $data = $this->trialBalance->build($this->supplierId, $this->periodId, self::YEAR . '-02-01', self::YEAR . '-12-31');

        $r311 = $this->rowByCode($data['rows'], '311');
        self::assertNotNull($r311, 'Účet 311 má PS, musí být v předvaze.');
        self::assertSame(self::cents(1000.00), self::cents($r311['ps_md']), 'PS 311 = lednový pohyb.');
        self::assertSame(0, self::cents($r311['turnover_md']), 'Obrat od února je nulový.');
        self::assertTrue($data['checks']['opening_balanced'], 'Bilanční kontinuita: Σ PS MD == Σ PS D.');
    }

    // ── T3 ────────────────────────────────────────────────────────────────

    public function testTenantIsolation(): void
    {
        // druhý supplier s vlastní osnovou, obdobím a zápisem
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 2", "Brno", "60200", ?, "druha@example.com", ?, ?)'
        );
        $stmt->execute(['Druhá firma s.r.o.', $this->czId, $this->currencyId, $this->vatRateId]);
        $supplier2 = (int) $this->db->pdo()->lastInsertId();
        $this->seeder->seedForSupplier($supplier2);
        $period2 = $this->periods->create($supplier2, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->posting->postDocument($supplier2, 'manual', null, [
            self::l('211', 'debit', 777.00),
            self::l('602', 'credit', 777.00),
        ], ['entry_date' => self::YEAR . '-03-01', 'posted_by' => $this->userId]);

        $this->manual([
            self::l('211', 'debit', 100.00),
            self::l('602', 'credit', 100.00),
        ], self::YEAR . '-03-01');

        $data = $this->trialBalance->build($this->supplierId, $this->periodId, null, null);
        self::assertSame(self::cents(100.00), self::cents($data['totals']['turnover_md']), 'Zápisy druhého supplieru se v předvaze prvního neobjeví.');
        foreach ($data['rows'] as $row) {
            self::assertNotSame(self::cents(777.00), self::cents($row['turnover_md']), 'Částka cizího tenanta nesmí prosáknout.');
        }

        // Období druhého supplieru je pro prvního nedostupné (404).
        $this->expectException(ReportException::class);
        $this->trialBalance->build($this->supplierId, $period2, null, null);
    }

    // ── T4 ────────────────────────────────────────────────────────────────

    public function testAnalyticAccountRollsUpToSynthetic(): void
    {
        $parentId = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '311'"
        )->fetchColumn();
        $ins = $this->db->pdo()->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id)
             VALUES (?, ?, ?, "asset", "debit", 0, ?)'
        );
        $ins->execute([$this->supplierId, '311001', 'Odběratelé — analytika', $parentId]);

        $this->manual([
            self::l('311001', 'debit', 300.00),
            self::l('602', 'credit', 300.00),
        ], self::YEAR . '-03-01');
        $this->manual([
            self::l('311', 'debit', 200.00),
            self::l('602', 'credit', 200.00),
        ], self::YEAR . '-03-02');

        // analytics=0 → analytika rolovaná do syntetiky 311
        $rolled = $this->trialBalance->build($this->supplierId, $this->periodId, null, null, false);
        $r311 = $this->rowByCode($rolled['rows'], '311');
        self::assertNotNull($r311);
        self::assertSame(self::cents(500.00), self::cents($r311['turnover_md']), 'Roll-up: 311 = 300 (analytika) + 200 (syntetika).');
        self::assertNull($this->rowByCode($rolled['rows'], '311001'), 'Analytika se při analytics=0 nezobrazuje samostatně.');

        // analytics=1 → rozpad po analytikách
        $split = $this->trialBalance->build($this->supplierId, $this->periodId, null, null, true);
        $a = $this->rowByCode($split['rows'], '311001');
        self::assertNotNull($a, 'Analytika je při analytics=1 samostatný řádek.');
        self::assertSame(self::cents(300.00), self::cents($a['turnover_md']));
        $s = $this->rowByCode($split['rows'], '311');
        self::assertNotNull($s);
        self::assertSame(self::cents(200.00), self::cents($s['turnover_md']), 'Syntetika nese jen vlastní pohyb.');
    }

    // ── D3 (audit 2026-07): PS jako NETTO počáteční zůstatek, ne hrubé obraty ──

    /**
     * Regrese D3: počáteční stav (PS) v předvaze/hlavní knize se vykazuje NETTO per
     * účet na jedné straně, ne jako hrubé kumulativní obraty MD i D proti sobě. Účet
     * s pohyby na obou stranách před `from` (1000 MD − 300 D) → PS = 700 MD, 0 D.
     */
    public function testOpeningBalanceIsNettedPerAccount(): void
    {
        // dva rozvahové účty, oba s pohyby na obou stranách před `from`
        $this->manual([
            self::l('311', 'debit', 1000.00),
            self::l('321', 'credit', 1000.00),
        ], self::YEAR . '-01-10');
        $this->manual([
            self::l('321', 'debit', 300.00),
            self::l('311', 'credit', 300.00),
        ], self::YEAR . '-02-10');

        $data = $this->trialBalance->build($this->supplierId, $this->periodId, self::YEAR . '-06-01', self::YEAR . '-12-31');

        $r311 = $this->rowByCode($data['rows'], '311');
        self::assertNotNull($r311);
        self::assertSame(self::cents(700.00), self::cents($r311['ps_md']), 'PS 311 = netto 1000 − 300 = 700 na MD (ne 1000 hrubě).');
        self::assertSame(0, self::cents($r311['ps_d']), 'Znettovaný účet nemá druhou stranu PS.');

        $r321 = $this->rowByCode($data['rows'], '321');
        self::assertNotNull($r321);
        self::assertSame(self::cents(700.00), self::cents($r321['ps_d']), 'PS 321 = netto 700 na D.');
        self::assertSame(0, self::cents($r321['ps_md']), 'Znettovaný závazek nemá debetní stranu PS.');

        self::assertTrue($data['checks']['opening_balanced'], 'Σ PS MD == Σ PS D i po nettování.');
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

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function rowByCode(array $rows, string $code): ?array
    {
        foreach ($rows as $row) {
            if ((string) $row['account_code'] === $code) {
                return $row;
            }
        }
        return null;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
