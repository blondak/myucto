<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Pdf\MonthlyReportPdfRenderer;
use MyInvoice\Service\Report\MonthlyReportService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * MonthlyReportService MUSÍ jen SKLÁDAT existující sestavy (FinancialStatementService,
 * SaldoService, DphPriznaniBuilder, TaxAdvanceScheduleService) — ne duplikovat jejich
 * výpočet. Testy proto ověřují, že výstup service je bod-po-bodu shodný s přímým
 * voláním zdrojových služeb se stejnými parametry (asOf = konec/začátek měsíce).
 *
 * Reálná DB data (supplier_id=1, double_entry, jako IsdocExportXsdValidationTest) —
 * soft skip, pokud cfg.php nebo účetní období pro danou firmu chybí.
 */
#[Group('integration')]
final class MonthlyReportServiceTest extends TestCase
{
    private const SUPPLIER_ID = 1;

    private ?Connection $conn = null;
    private MonthlyReportService $service;
    private FinancialStatementService $statements;
    private SaldoService $saldo;
    private AccountingPeriodRepository $periods;

    protected function tearDown(): void
    {
        $this->conn?->close();
    }

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection (CI runner skipne).');
        }

        $container = Bootstrap::buildApp()->getContainer();
        $this->service = $container->get(MonthlyReportService::class);
        $this->statements = $container->get(FinancialStatementService::class);
        $this->saldo = $container->get(SaldoService::class);
        $this->periods = $container->get(AccountingPeriodRepository::class);
        $this->conn = $container->get(Connection::class);

        $stmt = $this->conn->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([self::SUPPLIER_ID]);
        if ((string) $stmt->fetchColumn() !== 'double_entry') {
            $this->markTestSkipped('supplier_id=' . self::SUPPLIER_ID . ' není v podvojném účetnictví.');
        }
    }

    public function testYtdAndBalanceSheetMatchDirectFinancialStatementServiceCalls(): void
    {
        [$year, $month, $asOf] = $this->pickPeriodWithData();

        $data = $this->service->build(self::SUPPLIER_ID, $year, $month);

        $period = $this->periods->findForDate(self::SUPPLIER_ID, $asOf);
        self::assertNotNull($period, 'Očekávané účetní období pro ' . $asOf . ' nenalezeno.');
        $periodId = (int) $period['id'];

        $directYtd = $this->statements->incomeStatement(self::SUPPLIER_ID, $periodId, $asOf, 'auto');
        self::assertSame($directYtd['rows'], $data['income_statement_ytd']['rows'],
            'income_statement_ytd musí být identický přímému FinancialStatementService::incomeStatement() volání.');
        self::assertSame($directYtd['checks'], $data['income_statement_ytd']['checks']);

        $directBalance = $this->statements->balanceSheet(self::SUPPLIER_ID, $periodId, $asOf, 'auto');
        self::assertSame($directBalance['assets'], $data['balance_sheet']['assets']);
        self::assertSame($directBalance['liabilities'], $data['balance_sheet']['liabilities']);
        self::assertSame($directBalance['checks'], $data['balance_sheet']['checks']);
    }

    public function testMonthOnlyIsDeltaOfTwoDirectYtdSnapshotsNotReimplementedLogic(): void
    {
        [$year, $month, $asOf] = $this->pickPeriodWithData();
        $period = $this->periods->findForDate(self::SUPPLIER_ID, $asOf);
        self::assertNotNull($period);
        $periodId = (int) $period['id'];

        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $prevAsOf = (new \DateTimeImmutable($monthStart))->modify('-1 day')->format('Y-m-d');

        $data = $this->service->build(self::SUPPLIER_ID, $year, $month);

        if ($prevAsOf < (string) $period['starts_on']) {
            // Leden fiskálního roku: měsíc == YTD, žádný diff k ověření.
            self::assertSame($data['income_statement_ytd']['rows'], $data['income_statement_month']);
            return;
        }

        $ytd = $this->statements->incomeStatement(self::SUPPLIER_ID, $periodId, $asOf, 'auto');
        $prevYtd = $this->statements->incomeStatement(self::SUPPLIER_ID, $periodId, $prevAsOf, 'auto');
        $prevByCode = [];
        foreach ($prevYtd['rows'] as $r) {
            $prevByCode[(string) $r['row_code']] = (float) $r['amount'];
        }

        self::assertCount(count($ytd['rows']), $data['income_statement_month']);
        foreach ($ytd['rows'] as $i => $row) {
            $expected = round((float) $row['amount'] - ($prevByCode[(string) $row['row_code']] ?? 0.0), 2);
            self::assertSame($row['row_code'], $data['income_statement_month'][$i]['row_code']);
            self::assertEqualsWithDelta($expected, (float) $data['income_statement_month'][$i]['amount'], 0.005,
                'Řádek ' . $row['row_code'] . ' musí být přesný rozdíl dvou YTD snímků.');
        }
    }

    public function testOverdueListsAreSubsetOfSaldoServiceOutputSortedDescending(): void
    {
        [$year, $month, $asOf] = $this->pickPeriodWithData();
        $period = $this->periods->findForDate(self::SUPPLIER_ID, $asOf);
        self::assertNotNull($period);

        $data = $this->service->build(self::SUPPLIER_ID, $year, $month);
        $directSaldo = $this->saldo->build(self::SUPPLIER_ID, (int) $period['id'], $asOf);

        $allDaysOverdue311 = [];
        foreach ($directSaldo['accounts'] as $acc) {
            if ($acc['account']['code'] !== '311') continue;
            foreach ($acc['partners'] as $p) {
                foreach ($p['items'] as $it) {
                    if ($it['days_overdue'] > 0) $allDaysOverdue311[] = $it['days_overdue'];
                }
            }
        }
        rsort($allDaysOverdue311);
        $expectedTop = array_slice($allDaysOverdue311, 0, 10);
        $actualTop = array_map(static fn (array $r) => $r['days_overdue'], $data['receivables_overdue']);

        self::assertSame($expectedTop, $actualTop, 'Top pohledávky po splatnosti musí odpovídat SaldoService výstupu.');
    }

    public function testPdfRendersFromServiceOutput(): void
    {
        [$year, $month] = $this->pickPeriodWithData();
        $data = $this->service->build(self::SUPPLIER_ID, $year, $month, 'Testovací komentář účetní.');

        $renderer = new MonthlyReportPdfRenderer();
        $pdf = $renderer->render($data);

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertGreaterThan(1000, strlen($pdf));
    }

    /**
     * Najde nejnovější měsíc, pro který má supplier_id=1 účetní období (obvykle
     * aktuální/nedávný fiskální rok v dev datech).
     *
     * @return array{0:int,1:int,2:string}
     */
    private function pickPeriodWithData(): array
    {
        $today = new \DateTimeImmutable('today');
        $period = $this->periods->findForDate(self::SUPPLIER_ID, $today->format('Y-m-d'));
        if ($period === null) {
            $this->markTestSkipped('Žádné účetní období pro dnešní datum u supplier_id=' . self::SUPPLIER_ID . '.');
        }
        $year = (int) $today->format('Y');
        $month = (int) $today->format('n');
        $asOf = min((string) $period['ends_on'], $today->format('Y-m-d'));
        if ((int) substr($asOf, 0, 4) !== $year || (int) substr($asOf, 5, 2) !== $month) {
            // asOf ořízlé na konec předchozího měsíce fiskálního roku — použij ten měsíc.
            $year = (int) substr($asOf, 0, 4);
            $month = (int) substr($asOf, 5, 2);
        }
        return [$year, $month, $asOf];
    }
}
