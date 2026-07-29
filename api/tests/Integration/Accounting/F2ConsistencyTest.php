<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use MyInvoice\Service\Pdf\BalanceSheetPdfRenderer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * KRITICKÉ testy konzistence F2 sestav s uzávěrkou (Epic F4, §6.2 I18–I22, R16):
 * behavior-preserving výkazů uzavřeného roku (closing vyloučen ze syntheticBalances),
 * PS nového roku z opening zápisu bez zdvojení historie (openingAnchor), 431 = VH
 * minulého roku, anchor NULL = bitově dnešní chování a výkazy v tis. Kč (R17).
 *
 * Izolovaný supplier (vzor FinancialStatementTest — kumulativní PS nesmí záviset
 * na sdíleném supplieru), transakce s rollbackem v tearDown, soft-skip bez cfg.php.
 */
#[Group('integration')]
final class F2ConsistencyTest extends TestCase
{
    private const YEAR = 2098;
    private const AS_OF = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private ClosingService $closing;
    private FinancialStatementService $statements;
    private TrialBalanceService $trialBalance;
    private ReportXlsxExporter $xlsx;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    /** @var list<string> temp soubory ke smazání */
    private array $tempFiles = [];

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
            $this->closing      = $container->get(ClosingService::class);
            $this->statements   = $container->get(FinancialStatementService::class);
            $this->trialBalance = $container->get(TrialBalanceService::class);
            $this->xlsx         = $container->get(ReportXlsxExporter::class);
            $this->periods      = $container->get(AccountingPeriodRepository::class);
            $seeder             = $container->get(ChartOfAccountsSeeder::class);
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
        $hasSeed = (int) $pdo->query(
            "SELECT COUNT(*) FROM statement_versions WHERE version_code = 'vyhl500-2002/2024'"
        )->fetchColumn();
        if ($hasSeed < 2) {
            $this->markTestSkipped('Seed výkazů 1012 není aplikovaný (statement_versions).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "f4-f2konzistence@example.com", ?, ?)'
        );
        $stmt->execute(['F4 konzistence F2 s.r.o.', $czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::AS_OF);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    // ── I18: behavior-preserving — výkazy roku N před == po closeBooks ───────

    public function testI18StatementsOfClosedYearUnchangedByClosing(): void
    {
        $this->seedBaseScenario();

        $bsBefore  = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $vzzBefore = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $tbBefore  = $this->trialBalance->build($this->supplierId, $this->periodId, null, null);

        $this->runChainToClosed();

        $bsAfter  = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $vzzAfter = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $tbAfter  = $this->trialBalance->build($this->supplierId, $this->periodId, null, null);

        // Rozvaha řádek po řádku (syntheticBalances vylučuje closing, R16)
        self::assertSame(
            $this->bsRowMap($bsBefore),
            $this->bsRowMap($bsAfter),
            'Rozvaha uzavřeného roku k ends_on se closingem NEMĚNÍ (R16).',
        );
        self::assertSame(
            $this->vzzRowMap($vzzBefore),
            $this->vzzRowMap($vzzAfter),
            'VZZ uzavřeného roku se closingem NEMĚNÍ (R16).',
        );
        self::assertTrue($bsAfter['checks']['balanced'], 'A = P i po uzávěrce.');

        // Předvaha: PS beze změny; closing JE v obratech → KS všech účtů 0 (§8/5c)
        $psBefore = $this->tbPsMap($tbBefore);
        $psAfter = $this->tbPsMap($tbAfter);
        foreach ($psBefore as $code => $ps) {
            self::assertSame($ps, $psAfter[$code] ?? 0, "PS účtu {$code} v předvaze beze změny.");
        }
        // Výchozí zobrazení předvahy je stav PŘED uzavřením (rozhodnutí R-1 auditu):
        // uzávěrka konečné stavy NEMĚNÍ, protože se do nich nezapočítá. Bez toho
        // vycházely u uzavřeného roku všechny konečné stavy jako nula a účetní
        // neměla jak dostat zůstatky k rozvahovému dni.
        $ksMap = static function (array $tb): array {
            $out = [];
            foreach ($tb['rows'] as $row) {
                $out[(string) $row['account_code']] = self::cents((float) $row['ks_md']) - self::cents((float) $row['ks_d']);
            }
            return $out;
        };
        self::assertSame($ksMap($tbBefore), $ksMap($tbAfter), 'KS v předvaze se uzávěrkou nemění.');

        // Po přepnutí na stav PO uzavření musí být konečné stavy nulové — právě tím
        // se ověří, že uzávěrka rozvahové účty skutečně převedla (§ 8 odst. 5 písm. c).
        $tbClosed = $this->trialBalance->build($this->supplierId, $this->periodId, null, null, false, true);
        foreach ($tbClosed['rows'] as $row) {
            $ks = self::cents((float) $row['ks_md']) - self::cents((float) $row['ks_d']);
            self::assertSame(0, $ks, 'KS účtu ' . $row['account_code'] . ' po uzavření = 0.');
        }
    }

    // ── I19: nový rok — PS z opening, žádné zdvojení, 431 = VH roku N ───────

    public function testI19NewYearOpeningBalancesAnd431(): void
    {
        $this->seedBaseScenario();
        $this->runChainToClosed();
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $next = $this->periods->nextPeriod($this->supplierId, self::AS_OF);
        self::assertNotNull($next);
        $nextId = (int) $next['id'];

        // Předvaha 2099 s from po 1. 1.: PS rozvahových == opening řádky (anchor
        // vylučuje historii před opening zápisem — žádné zdvojení, R16).
        $tb = $this->trialBalance->build($this->supplierId, $nextId, (self::YEAR + 1) . '-02-01', (self::YEAR + 1) . '-12-31');
        $ps = $this->tbPsMap($tb);
        self::assertSame(self::cents(1210.00), $ps['311'] ?? 0, 'PS 311 = opening 1 210 (bez zdvojení historie).');
        self::assertSame(self::cents(-105.00), $ps['343'] ?? 0, 'PS 343 = opening −105.');
        self::assertSame(self::cents(-605.00), $ps['321'] ?? 0, 'PS 321 = opening −605.');
        self::assertSame(self::cents(-300.00), $ps['081'] ?? 0, 'PS 081 = opening −300.');
        self::assertSame(self::cents(-100.00), $ps['391'] ?? 0, 'PS 391 = opening −100.');
        self::assertSame(self::cents(-100.00), $ps['431'] ?? 0, 'PS 431 = VH roku N (100 kreditně).');

        foreach ($tb['rows'] as $row) {
            if (in_array((string) $row['account_type'], ['revenue', 'expense'], true)) {
                self::assertSame(
                    0,
                    self::cents((float) $row['ps_md']) - self::cents((float) $row['ps_d']),
                    'PS výsledkového účtu ' . $row['account_code'] . ' v novém roce = 0.',
                );
            }
        }

        // Rozvaha nového roku: Σ aktiva == Σ pasiva; 431 ve vlastním kapitálu == VH roku N
        $bs = $this->statements->balanceSheet($this->supplierId, $nextId, (self::YEAR + 1) . '-06-30', 'full');
        self::assertTrue($bs['checks']['balanced'], 'A = P v novém roce.');
        self::assertSame(
            self::cents($bs['checks']['assets_net']),
            self::cents($bs['checks']['liabilities_total']),
        );
        self::assertSame(self::cents(100.00), $this->accountAmountInRows($bs['liabilities'], '431'), '431 (VH minulých let) = VH roku N.');
    }

    // ── I20: výkazy roku N mid-year beze změny ───────────────────────────────

    public function testI20MidYearStatementsUnchanged(): void
    {
        $this->seedBaseScenario();
        $midYear = self::YEAR . '-06-30';

        $bsBefore = $this->statements->balanceSheet($this->supplierId, $this->periodId, $midYear, 'full');
        $this->runChainToClosed();
        $this->closing->openNext($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $bsAfter = $this->statements->balanceSheet($this->supplierId, $this->periodId, $midYear, 'full');

        self::assertSame($this->bsRowMap($bsBefore), $this->bsRowMap($bsAfter), 'Výkazy roku N mid-year beze změny (asOf < ends_on).');
    }

    // ── I21: anchor NULL — firma bez uzávěrky, dnešní chování (F2 T8) ────────

    public function testI21NoClosingKeepsF2Behavior(): void
    {
        $this->seedBaseScenario();

        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full');
        self::assertTrue($bs['checks']['balanced'], 'Bez uzávěrky: bilanční rovnice platí (F2 T8).');
        self::assertSame(self::cents(810.00), self::cents($bs['checks']['assets_net']), 'AKTIVA netto = 1110 − 300 (F2 T8 hodnota).');
        $pav = $this->rowByCode($bs['liabilities'], 'P.A.V.');
        self::assertNotNull($pav);
        self::assertSame(self::cents(100.00), self::cents($pav['amount']), 'P.A.V. = VH běžného období 100 (F2 T8).');

        $vzz = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full');
        self::assertSame(self::cents(100.00), self::cents($vzz['checks']['profit_current']));
    }

    // ── I22: výkazy v tis. Kč (R17) ──────────────────────────────────────────

    public function testI22ThousandsUnitInExportsJsonUnchanged(): void
    {
        // Jediný pohyb 12 100 → v tis. Kč řádek 12
        $this->manual([
            self::l('311', 'debit', 12100.00),
            self::l('602', 'credit', 12100.00),
        ], self::YEAR . '-03-01');

        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full');

        // JSON API beze změny — vždy Kč
        $receivables = $this->rowByCode($bs['assets'], 'C.II.2.1.');
        self::assertNotNull($receivables);
        self::assertSame(self::cents(12100.00), self::cents($receivables['net']), 'JSON zůstává v Kč (R17).');

        // XLSX unit=czk obsahuje 12 100; unit=thousands 12 + poznámku o tis. Kč
        $czkValues = $this->xlsxNumericValues($this->xlsx->balanceSheet($bs, 'czk'));
        self::assertContains(12100.0, $czkValues, 'XLSX (Kč) nese hodnotu 12 100.');

        $out = $this->xlsx->balanceSheet($bs, 'thousands');
        $thousandsValues = $this->xlsxNumericValues($out);
        self::assertNotContains(12100.0, $thousandsValues, 'XLSX (tis.) hodnotu v Kč neobsahuje.');
        self::assertContains(12.0, $thousandsValues, 'XLSX (tis.) nese 12 100 / 1000 → 12.');
        self::assertTrue($this->xlsxContainsText($out, 'tisících'), 'XLSX (tis.) nese hlavičku/poznámku o celých tisících Kč.');

        // PDF renderer: hodnoty VŽDY v tis. (per řádek nezávisle, (int) round(v/1000))
        $renderer = (new \ReflectionClass(BalanceSheetPdfRenderer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(BalanceSheetPdfRenderer::class, 'toThousands');
        $converted = $method->invoke($renderer, $bs);
        $convertedRow = $this->rowByCode($converted['assets'], 'C.II.2.1.');
        self::assertSame(12, $convertedRow['net'], 'PDF rozvaha: 12 100 Kč → 12 tis. Kč (R17).');
        self::assertSame('thousands', $converted['unit']);
        // Zaokrouhlení per řádek: 499 → 0, 500 → 1 (haléřová hrana)
        $edge = $method->invoke($renderer, ['assets' => [['gross' => 499.0, 'correction' => 0.0, 'net' => 500.0, 'prev_net' => 1499.0]], 'liabilities' => [], 'checks' => []]);
        self::assertSame(0, $edge['assets'][0]['gross']);
        self::assertSame(1, $edge['assets'][0]['net']);
        self::assertSame(1, $edge['assets'][0]['prev_net']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Syntetická sada F2 T8: výnos 602, náklad 518, odpisy 551+081, OP 559+391, DPH 343. */
    private function seedBaseScenario(): void
    {
        $this->manual([
            self::l('311', 'debit', 1210.00),
            self::l('602', 'credit', 1000.00),
            self::l('343', 'credit', 210.00),
        ], self::YEAR . '-03-01');
        $this->manual([
            self::l('518', 'debit', 500.00),
            self::l('343', 'debit', 105.00),
            self::l('321', 'credit', 605.00),
        ], self::YEAR . '-03-05');
        $this->manual([
            self::l('551', 'debit', 300.00),
            self::l('081', 'credit', 300.00),
        ], self::YEAR . '-06-30');
        $this->manual([
            self::l('559', 'debit', 100.00),
            self::l('391', 'credit', 100.00),
        ], self::YEAR . '-06-30');
    }

    /** Celý workflow do stavu closed (kroky skip, FX bez položek). */
    private function runChainToClosed(): void
    {
        $sid = $this->supplierId;
        $pid = $this->periodId;
        $this->closing->start($sid, $pid, $this->rv(), $this->meta());
        $this->closing->runPrecheck($sid, $pid, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'depreciation', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->runFxRevaluation($sid, $pid, [], $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'estimates', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'deferrals', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'provisions', 'skipped', null, $this->rv(), $this->meta());
        $this->closing->confirmStep($sid, $pid, 'income_tax', 'skipped', null, $this->rv(), $this->meta());
        $this->completeInventory($sid, $pid, $this->userId);
        $this->closing->closeBooks($sid, $pid, $this->rv(), $this->meta());
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

    private function rv(): int
    {
        return (int) $this->periods->findById($this->supplierId, $this->periodId)['row_version'];
    }

    /** @return array{user_id:int, posted_by:int} */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }

    /**
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     */
    private function manual(array $lines, string $date): int
    {
        return $this->posting->postDocument($this->supplierId, 'manual', null, $lines, [
            'entry_date' => $date,
            'posted_by' => $this->userId,
        ]);
    }

    /** @return array{account_code:string, side:string, amount:float} */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    /**
     * Mapa rozvahy row_code → [gross, correction, net, amount] v haléřích.
     *
     * @param array<string,mixed> $bs
     * @return array<string,list<int>>
     */
    private function bsRowMap(array $bs): array
    {
        $map = [];
        foreach ($bs['assets'] as $row) {
            $map['A:' . $row['row_code']] = [
                self::cents((float) ($row['gross'] ?? 0)),
                self::cents((float) ($row['correction'] ?? 0)),
                self::cents((float) ($row['net'] ?? 0)),
            ];
        }
        foreach ($bs['liabilities'] as $row) {
            $map['P:' . $row['row_code']] = [self::cents((float) ($row['amount'] ?? 0))];
        }
        return $map;
    }

    /**
     * @param array<string,mixed> $vzz
     * @return array<string,int>
     */
    private function vzzRowMap(array $vzz): array
    {
        $map = [];
        foreach ($vzz['rows'] as $row) {
            $map[(string) $row['row_code']] = self::cents((float) ($row['amount'] ?? 0));
        }
        return $map;
    }

    /**
     * Mapa předvahy account_code → signed PS v haléřích.
     *
     * @param array<string,mixed> $tb
     * @return array<string,int>
     */
    private function tbPsMap(array $tb): array
    {
        $map = [];
        foreach ($tb['rows'] as $row) {
            $map[(string) $row['account_code']] = self::cents((float) $row['ps_md']) - self::cents((float) $row['ps_d']);
        }
        return $map;
    }

    /**
     * Σ částky řádků výkazu, jejichž rozpad účtů obsahuje daný kód.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function accountAmountInRows(array $rows, string $accountCode): int
    {
        foreach ($rows as $row) {
            foreach ($row['accounts'] ?? [] as $acc) {
                if ((string) $acc['account_code'] === $accountCode) {
                    return self::cents((float) $row['amount']);
                }
            }
        }
        self::fail("Účet {$accountCode} není v žádném řádku výkazu.");
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function rowByCode(array $rows, string $code): ?array
    {
        foreach ($rows as $row) {
            if ((string) $row['row_code'] === $code) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Numerické hodnoty všech buněk XLSX exportu.
     *
     * @param array{bytes:string, filename:string, mime:string} $out
     * @return list<float>
     */
    private function xlsxNumericValues(array $out): array
    {
        $sheet = $this->loadXlsx($out);
        $values = [];
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $value = $cell->getValue();
                if (is_int($value) || is_float($value)) {
                    $values[] = (float) $value;
                }
            }
        }
        return $values;
    }

    /**
     * @param array{bytes:string, filename:string, mime:string} $out
     */
    private function xlsxContainsText(array $out, string $needle): bool
    {
        $sheet = $this->loadXlsx($out);
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $value = $cell->getValue();
                if (is_string($value) && str_contains($value, $needle)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array{bytes:string, filename:string, mime:string} $out
     */
    private function loadXlsx(array $out): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $path = tempnam(sys_get_temp_dir(), 'f4x');
        self::assertNotFalse($path);
        file_put_contents($path, $out['bytes']);
        $this->tempFiles[] = $path;
        return IOFactory::load($path)->getActiveSheet();
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
