<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Uzávěrková kontrola `estimates_unreleased` — ČÚS 019, nerozpuštěné dohadné položky.
 *
 * Dohad je odhad nákladu/výnosu, ke kterému k rozvahovému dni chybí doklad. Jakmile
 * faktura v N+1 dorazí, dohad se MUSÍ rozpustit (389: MD 389 / D 321). Když se nerozpustí,
 * knihy nesou náklad dvakrát — jednou v loňském dohadu, podruhé v letošní faktuře.
 *
 * Rozpuštění je čistě ruční úkon a nic ho nehlídalo: `estimates_balances` je `info`
 * s natvrdo `ok => true`, takže přenesený zůstatek procházel tiše.
 *
 * ── Proč se počáteční zůstatek bere k PRVNÍMU DNI období ────────────────────────────
 * Otevírací zápis má `entry_date = starts_on` ({@see \MyInvoice\Service\Accounting\Activation\OpeningBalanceService}).
 * Kdyby se počátek měřil „den před", firma přenášející zůstatky otevíracím zápisem by
 * měla vždy nulu a kontrola by NIKDY nesepnula — tichá nula je horší než chybějící
 * kontrola, protože vypadá jako pořádek. Pokrývá {@see testOpeningEntryOnFirstDayIsCounted()}.
 *
 * Izolovaný supplier v transakci s rollbackem (vzor ClosingCancelledWithEntryTest).
 */
#[Group('integration')]
final class ClosingUnreleasedEstimatesTest extends TestCase
{
    private const YEAR = 2092;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private ClosingService $closing;
    private ClosingRepository $closingRepo;
    private PostingService $posting;
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
            $this->db          = $container->get(Connection::class);
            $this->closing     = $container->get(ClosingService::class);
            $this->closingRepo = $container->get(ClosingRepository::class);
            $this->posting     = $container->get(PostingService::class);
            $this->periods     = $container->get(AccountingPeriodRepository::class);
            $seeder            = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId   = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId    = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId         = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        );
        $stmt->execute(['Dohad test s.r.o.', $czId, 'dohad-test@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        // Minulé období musí existovat — dohad se do něj účtuje a bez něj PostingService
        // zápis odmítne („pro datum … neexistuje účetní období").
        $this->periods->create($this->supplierId, self::YEAR - 1, self::YEAR - 1 . '-01-01', self::YEAR - 1 . '-12-31');
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

    /** Loňský dohad 389, letos nerozpuštěný → nález v plné výši. */
    public function testCarriedEstimateNotReleasedIsReported(): void
    {
        $this->bookEstimate389(10_000.0, self::YEAR - 1 . '-12-31');

        $rows = $this->closingRepo->unreleasedEstimates($this->supplierId, self::STARTS_ON, self::ENDS_ON);

        self::assertCount(1, $rows, 'Nerozpuštěný dohad musí být nahlášen.');
        self::assertSame('389', $rows[0]['account_code']);
        self::assertEqualsWithDelta(10_000.0, $rows[0]['opening'], 0.01);
        self::assertEqualsWithDelta(0.0, $rows[0]['released'], 0.01);
        self::assertEqualsWithDelta(10_000.0, $rows[0]['unreleased'], 0.01);
        self::assertFalse($this->checkOk(), 'Uzávěrková kontrola musí být v chybovém stavu.');
    }

    /** Rozpuštěný dohad (MD 389 / D 321 při doručení faktury) nález nezanechá. */
    public function testReleasedEstimatePasses(): void
    {
        $this->bookEstimate389(10_000.0, self::YEAR - 1 . '-12-31');
        $this->releaseEstimate389(10_000.0, self::YEAR . '-02-15');

        self::assertSame([], $this->closingRepo->unreleasedEstimates(
            $this->supplierId,
            self::STARTS_ON,
            self::ENDS_ON,
        ));
        self::assertTrue($this->checkOk());
    }

    /** Částečné rozpuštění hlásí jen zbytek — ne celý původní dohad. */
    public function testPartialReleaseReportsRemainder(): void
    {
        $this->bookEstimate389(10_000.0, self::YEAR - 1 . '-12-31');
        $this->releaseEstimate389(6_000.0, self::YEAR . '-03-01');

        $rows = $this->closingRepo->unreleasedEstimates($this->supplierId, self::STARTS_ON, self::ENDS_ON);

        self::assertCount(1, $rows);
        self::assertEqualsWithDelta(6_000.0, $rows[0]['released'], 0.01);
        self::assertEqualsWithDelta(4_000.0, $rows[0]['unreleased'], 0.01);
    }

    /**
     * Dohad zaúčtovaný na konci TOHOTO období na účtu zůstat MÁ — kontrola ho hlásit nesmí,
     * jinak by křičela na každou správně provedenou uzávěrku.
     */
    public function testEstimateBookedInCurrentPeriodIsNotReported(): void
    {
        $this->bookEstimate389(10_000.0, self::ENDS_ON);

        self::assertSame([], $this->closingRepo->unreleasedEstimates(
            $this->supplierId,
            self::STARTS_ON,
            self::ENDS_ON,
        ));
        self::assertTrue($this->checkOk());
    }

    /**
     * Zůstatek přenesený OTEVÍRACÍM zápisem (entry_date = první den období) se počítá.
     * Tohle je běžný způsob přenosu; kdyby se počátek měřil „den před", vyšla by nula
     * a kontrola by mlčela u většiny reálných firem.
     */
    public function testOpeningEntryOnFirstDayIsCounted(): void
    {
        // Zůstatek přenesený k prvnímu dni období. Protiúčtem je běžný rozvahový účet —
        // závěrkový 701 v `manual` zápisu použít nelze (PostingService ho hlídá).
        $this->post([
            ['account_code' => '311', 'side' => 'debit', 'amount' => 8_000.0],
            ['account_code' => '389', 'side' => 'credit', 'amount' => 8_000.0],
        ], self::STARTS_ON, 'Počáteční stav 389');

        $rows = $this->closingRepo->unreleasedEstimates($this->supplierId, self::STARTS_ON, self::ENDS_ON);

        self::assertCount(1, $rows, 'Dohad z otevíracího zápisu se musí započítat.');
        self::assertEqualsWithDelta(8_000.0, $rows[0]['unreleased'], 0.01);
    }

    /** Haléřový rozdíl mezi odhadem a fakturou není nerozpuštěný dohad. */
    public function testSubTolerationRemainderIsIgnored(): void
    {
        $this->bookEstimate389(10_000.0, self::YEAR - 1 . '-12-31');
        $this->releaseEstimate389(9_999.70, self::YEAR . '-02-15');

        self::assertSame([], $this->closingRepo->unreleasedEstimates(
            $this->supplierId,
            self::STARTS_ON,
            self::ENDS_ON,
        ));
    }

    /** Zrcadlo na aktivní straně: 388 se rozpouští kreditem. */
    public function testActiveEstimate388IsReported(): void
    {
        $this->post([
            ['account_code' => '388', 'side' => 'debit', 'amount' => 5_000.0],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 5_000.0],
        ], self::YEAR - 1 . '-12-31', 'Dohadná položka aktivní');

        $rows = $this->closingRepo->unreleasedEstimates($this->supplierId, self::STARTS_ON, self::ENDS_ON);

        self::assertCount(1, $rows);
        self::assertSame('388', $rows[0]['account_code']);
        self::assertEqualsWithDelta(5_000.0, $rows[0]['unreleased'], 0.01);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** Dohadná položka pasivní: MD 518 / D 389. */
    private function bookEstimate389(float $amount, string $date): void
    {
        $this->post([
            ['account_code' => '518', 'side' => 'debit', 'amount' => $amount],
            ['account_code' => '389', 'side' => 'credit', 'amount' => $amount],
        ], $date, 'Dohadná položka pasivní');
    }

    /** Rozpuštění při doručení faktury: MD 389 / D 321. */
    private function releaseEstimate389(float $amount, string $date): void
    {
        $this->post([
            ['account_code' => '389', 'side' => 'debit', 'amount' => $amount],
            ['account_code' => '321', 'side' => 'credit', 'amount' => $amount],
        ], $date, 'Rozpuštění dohadu při doručení faktury');
    }

    /** @param list<array<string,mixed>> $lines */
    private function post(array $lines, string $date, string $description): void
    {
        $this->posting->postDocument($this->supplierId, 'manual', null, $lines, [
            'entry_date'  => $date,
            'description' => $description,
            'posted'      => true,
            'user_id'     => $this->userId,
        ]);
    }

    private function checkOk(string $key = 'estimates_unreleased'): bool
    {
        $result = $this->closing->monthlyCheck($this->supplierId, $this->periodId, null, null);
        foreach ($result['checks'] as $c) {
            if ($c['key'] === $key) {
                return (bool) $c['ok'];
            }
        }
        self::fail('Kontrola ' . $key . ' chybí v seznamu kontrol.');
    }
}
