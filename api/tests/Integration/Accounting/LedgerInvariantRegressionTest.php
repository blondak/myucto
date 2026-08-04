<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\LedgerInvariantService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regresní testy dvou invariantů, které nad ostrými daty vyráběly FALEŠNÝ POPLACH.
 *
 * Proč tady a ne v `tests/Invariants`: tamní sada záměrně nic neseeduje — bere databázi
 * tak, jak je, a nad prázdným deníkem se skipuje. Právě proto obě chyby přežily: žádný
 * test nikdy nepostavil situaci, ve které se projeví. Tenhle test si scénář POSTAVÍ
 * fixturami, takže patří mezi integrační (vlastní transakce + rollback, izolovaný
 * dodavatel). Logika zůstává v {@see LedgerInvariantService}, tady se jen měří.
 *
 * Opravované chyby:
 *   I26 — karta majetku se do deníku promítá TŘEMI druhy zápisů (`asset`,
 *         `asset_disposal` se `source_id` = id karty, `depreciation` se `source_id`
 *         = id řádku `depreciation_entries`). Původní verze joinovala jen `asset`,
 *         takže u vyřazené karty viděla pouze debet na 02x ze zařazení a označila
 *         KAŽDÉ korektně vyřazené aktivum.
 *   I20 — filtr `reversed_by IS NULL` vyhodil ze stornované dvojice jen ORIGINÁL
 *         (sloupec nese odkaz na storno, ne naopak). Storno zbylo jednostranné
 *         a vyrobilo na zúčtovacím účtu zůstatek, který v účetnictví není.
 *
 * Ke každé pozitivní větvi je i NEGATIVNÍ protějšek: kdyby se dotaz opravou utrhl
 * úplně (přestal hlásit cokoli), pozitivní test by zezelenal vakuózně a nikdo by se
 * to nedozvěděl. Vakuózní zelená je tady horší než žádný test — přesně ta umožnila
 * obě opravované chyby.
 */
#[Group('integration')]
final class LedgerInvariantRegressionTest extends TestCase
{
    use IsolatedSupplierTrait;

    /** Rok pro majetkové scénáře (I26) — nikdy nekoliduje s reálnými daty. */
    private const ASSET_YEAR = 2097;

    /** Rok pro scénáře na zúčtovacím účtu (I20) — musí být UZAVŘENÝ. */
    private const CLEARING_YEAR = 2096;

    private Connection $db;
    private LedgerInvariantService $invariants;

    private int $supplierId = 0;
    private bool $inTx = false;

    /** @var array<string,int> account_code => chart_of_accounts.id */
    private array $accounts = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container        = Bootstrap::buildApp()->getContainer();
            $this->db         = $container->get(Connection::class);
            $this->invariants = $container->get(LedgerInvariantService::class);
            $seeder           = $container->get(ChartOfAccountsSeeder::class);
            $this->db->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo    = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        // Vlastní dodavatel: invarianty jedou nad CELOU databází, takže nález musí jít
        // jednoznačně přiřadit tomuhle testu a ne tomu, co v DB leželo předtím.
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $seeder->seedForSupplier($this->supplierId);

        $stmt = $pdo->prepare('SELECT account_code, id FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        $this->accounts = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
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

    // ── I26: vyřazená karta majetku ──────────────────────────────────────────

    /**
     * Korektně vyřazená karta — zařazení + odpis + úplné vyřazení. I26 nesmí hlásit nic.
     *
     * Tohle je ta regrese: se starým dotazem (join jen na `source_type = 'asset'`) tu
     * zůstal viset osiřelý debet 480 000 na 022 ze zařazení, protože protikus z vyřazení
     * ani odpisy nebyly vidět. Falešný poplach na každém vyřazení je horší než žádný
     * invariant — odnaučí lidi hlášení číst.
     */
    public function testI26StaysSilentOnCorrectlyDisposedAsset(): void
    {
        $assetId = $this->createAsset('I26-OK', 480000.00, true);
        $periodId = $this->createPeriod(self::ASSET_YEAR, 'open');

        // Zařazení do užívání: MD 022 / D 042.
        $this->createEntry($periodId, self::ASSET_YEAR . '-01-31', 'asset', $assetId, [
            ['022', 'debit', 480000.00],
            ['042', 'credit', 480000.00],
        ]);

        // Účetní odpis: MD 551 / D 082. Zápis ukazuje na ŘÁDEK odpisu, ne na kartu —
        // vazbu na kartu nese teprve `depreciation_entries.asset_id`.
        $depreciationId = $this->createDepreciationEntry($assetId, self::ASSET_YEAR, 180000.00, 300000.00);
        $this->createEntry($periodId, self::ASSET_YEAR . '-12-31', 'depreciation', $depreciationId, [
            ['551', 'debit', 180000.00],
            ['082', 'credit', 180000.00],
        ]);

        // Vyřazení, všechny čtyři řádky: doodpis zůstatkové ceny 541/082
        // a odúčtování pořizovací ceny 082/022.
        $this->createEntry($periodId, self::ASSET_YEAR . '-12-31', 'asset_disposal', $assetId, [
            ['541', 'debit', 300000.00],
            ['082', 'credit', 300000.00],
            ['082', 'debit', 480000.00],
            ['022', 'credit', 480000.00],
        ]);

        // Pojistka proti vakuózní zelené: fixtura musí být pro dotaz vůbec viditelná.
        self::assertSame(3, $this->postedEntryCountForAsset($assetId), 'Karta musí mít všechny tři druhy zápisů.');

        $result = $this->invariant('I26');
        self::assertTrue($result['checked'], 'I26 se nesmí přeskočit — modul majetku v DB je.');
        self::assertSame([], $this->violationsMatching($result, 'I26-OK'), 'Korektně vyřazená karta nesmí být hlášená.');
    }

    /**
     * Vyřazená karta, které CHYBÍ zápis vyřazení — na 022 zůstala pořizovací cena.
     * Tohle invariant hlásit MUSÍ, jinak by ho oprava utrhla úplně.
     */
    public function testI26ReportsDisposedAssetWithoutDisposalEntry(): void
    {
        $assetId  = $this->createAsset('I26-BAD', 480000.00, true);
        $periodId = $this->createPeriod(self::ASSET_YEAR, 'open');

        $this->createEntry($periodId, self::ASSET_YEAR . '-01-31', 'asset', $assetId, [
            ['022', 'debit', 480000.00],
            ['042', 'credit', 480000.00],
        ]);

        $result = $this->invariant('I26');
        self::assertTrue($result['checked'], 'I26 se nesmí přeskočit — modul majetku v DB je.');

        $found = $this->violationsMatching($result, 'I26-BAD');
        self::assertNotSame([], $found, 'Vyřazená karta bez zápisu vyřazení musí být hlášená.');
        self::assertStringContainsString('022', implode("\n", $found), 'Nález musí pojmenovat účet, na kterém zůstatek visí.');
    }

    // ── I20: zúčtovací účty k rozvahovému dni ────────────────────────────────

    /**
     * Stornovaná dvojice na zúčtovacím účtu v uzavřeném období. I20 nesmí hlásit nic.
     *
     * Tohle je ta druhá regrese: `reversed_by` nese jen ORIGINÁL stornované dvojice,
     * takže filtr `reversed_by IS NULL` vyhodil originál a storno nechal. Zbyla
     * jednostranná částka a na účtu vznikl zůstatek, který v účetnictví není.
     */
    public function testI20StaysSilentOnReversedPairOnClearingAccount(): void
    {
        $periodId = $this->createPeriod(self::CLEARING_YEAR, 'closed');
        $this->markAccountAsClearing('314');

        $original = $this->createEntry($periodId, self::CLEARING_YEAR . '-06-30', 'manual', null, [
            ['314', 'debit', 35375.00],
            ['221', 'credit', 35375.00],
        ]);
        $reversal = $this->createEntry($periodId, self::CLEARING_YEAR . '-06-30', 'manual', null, [
            ['221', 'debit', 35375.00],
            ['314', 'credit', 35375.00],
        ]);
        $this->db->pdo()->prepare('UPDATE journal_entries SET reversed_by = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$reversal, $original, $this->supplierId]);

        // Pojistka proti vakuózní zelené: bez `is_clearing = 1` by dotaz na tuhle
        // fixturu vůbec nedosáhl a test by zelenal, i kdyby byl invariant rozbitý.
        self::assertSame(2, $this->postedClearingLineCount(), 'Fixtura musí ležet na účtu označeném jako zúčtovací.');

        $result = $this->invariant('I20');
        self::assertTrue($result['checked'], 'I20 se nesmí přeskočit — migrace 1112 proběhla.');
        self::assertSame([], $this->violationsForSupplier($result), 'Stornovaná dvojice se musí vyrušit, ne vyrobit zůstatek.');
    }

    /**
     * Nespárovaná záloha na 314 bez jakéhokoli storna — reálný nález na ostrých datech
     * (1 058,75 z roku 2024, nikdy nespárovaná). Tohle invariant hlásit MUSÍ.
     */
    public function testI20ReportsUnpairedBalanceOnClearingAccount(): void
    {
        // `approved` je druhý uzavřený stav, který invariant bere — ať je pokrytý taky.
        $periodId = $this->createPeriod(self::CLEARING_YEAR, 'approved');
        $this->markAccountAsClearing('314');

        $this->createEntry($periodId, self::CLEARING_YEAR . '-11-20', 'manual', null, [
            ['314', 'debit', 1058.75],
            ['221', 'credit', 1058.75],
        ]);

        $result = $this->invariant('I20');
        self::assertTrue($result['checked'], 'I20 se nesmí přeskočit — migrace 1112 proběhla.');

        $found = $this->violationsForSupplier($result);
        self::assertNotSame([], $found, 'Nespárovaný zůstatek na zúčtovacím účtu musí být hlášený.');
        $text = implode("\n", $found);
        self::assertStringContainsString('314', $text, 'Nález musí pojmenovat zúčtovací účet.');
        self::assertStringContainsString('1058.75', $text, 'Nález musí uvést zbylý zůstatek.');
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    private function createPeriod(int $year, string $status): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status, closed_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $year,
            $year . '-01-01',
            $year . '-12-31',
            $status,
            $status === 'open' ? null : $year . '-12-31 23:59:59',
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function createAsset(string $inventoryNumber, float $inputPrice, bool $disposed): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO assets
                (supplier_id, inventory_number, name, kind, asset_account_code, accumulated_account_code,
                 acquisition_account_code, input_price, acquisition_date, put_into_use_date,
                 disposal_date, disposal_type, status, tax_method, tax_group)
             VALUES (?, ?, ?, 'tangible', '022', '082', '042', ?, ?, ?, ?, ?, ?, 'straight', 2)"
        )->execute([
            $this->supplierId,
            $inventoryNumber,
            'Regresní karta ' . $inventoryNumber,
            $inputPrice,
            self::ASSET_YEAR . '-01-15',
            self::ASSET_YEAR . '-01-31',
            $disposed ? self::ASSET_YEAR . '-12-31' : null,
            $disposed ? 'sold' : null,
            $disposed ? 'disposed' : 'in_use',
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function createDepreciationEntry(int $assetId, int $year, float $amount, float $residualEnd): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO depreciation_entries
                (supplier_id, asset_id, kind, fiscal_year, amount, full_amount, residual_value_end, status)
             VALUES (?, ?, 'accounting', ?, ?, ?, ?, 'posted')"
        )->execute([$this->supplierId, $assetId, $year, $amount, $amount, $residualEnd]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param list<array{0:string,1:string,2:float}> $lines account_code, side, amount
     */
    private function createEntry(int $periodId, string $entryDate, string $sourceType, ?int $sourceId, array $lines): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO journal_entries
                (supplier_id, period_id, entry_date, description, source_type, source_id, posted_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        )->execute([$this->supplierId, $periodId, $entryDate, 'regresní fixtura ' . $sourceType, $sourceType, $sourceId]);
        $entryId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $index => [$code, $side, $amount]) {
            if (!isset($this->accounts[$code])) {
                self::fail('Účet ' . $code . ' není v účtovém rozvrhu testovacího dodavatele.');
            }
            $stmt->execute([$entryId, $this->supplierId, $this->accounts[$code], $side, $amount, $index + 1]);
        }

        return $entryId;
    }

    /**
     * Seeder osnovy `is_clearing` NENASTAVUJE (migrace 1112 označila jen účty, které
     * v té chvíli existovaly), takže čerstvě naseedovaný dodavatel žádný zúčtovací účet
     * nemá a I20 by nad ním z principu nic nenašel. Fixtura si příznak nastaví sama.
     */
    private function markAccountAsClearing(string $accountCode): void
    {
        $this->db->pdo()->prepare('UPDATE chart_of_accounts SET is_clearing = 1 WHERE supplier_id = ? AND account_code = ?')
            ->execute([$this->supplierId, $accountCode]);
    }

    // ── měření ───────────────────────────────────────────────────────────────

    /** @return array{code:string, rule:string, source:string, checked:bool, violations:list<string>, skipped_reason:?string} */
    private function invariant(string $code): array
    {
        foreach ($this->invariants->checkAll() as $result) {
            if ($result['code'] === $code) {
                return $result;
            }
        }

        self::fail('Invariant ' . $code . ' v registru chybí.');
    }

    /**
     * Nálezy, které patří tomuhle testu. Invarianty měří celou databázi, takže cizí
     * nález (zbytek po jiném testu, reálná data) nesmí tenhle test ani shodit, ani
     * falešně zezelenat.
     *
     * @param array{violations:list<string>} $result
     * @return list<string>
     */
    private function violationsMatching(array $result, string $needle): array
    {
        return array_values(array_filter(
            $result['violations'],
            static fn (string $violation): bool => str_contains($violation, $needle),
        ));
    }

    /**
     * @param array{violations:list<string>} $result
     * @return list<string>
     */
    private function violationsForSupplier(array $result): array
    {
        return $this->violationsMatching($result, 'firma ' . $this->supplierId . ',');
    }

    private function postedEntryCountForAsset(int $assetId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries e
              WHERE e.supplier_id = ?
                AND e.posted_at IS NOT NULL
                AND ((e.source_type IN ('asset', 'asset_disposal') AND e.source_id = ?)
                  OR (e.source_type = 'depreciation'
                      AND e.source_id IN (SELECT d.id FROM depreciation_entries d WHERE d.asset_id = ?)))"
        );
        $stmt->execute([$this->supplierId, $assetId, $assetId]);

        return (int) $stmt->fetchColumn();
    }

    private function postedClearingLineCount(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND a.is_clearing = 1 AND e.posted_at IS NOT NULL'
        );
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }
}
