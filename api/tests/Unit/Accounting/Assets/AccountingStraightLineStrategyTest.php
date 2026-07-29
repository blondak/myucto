<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Assets;

use MyInvoice\Service\Accounting\Assets\DepreciationContext;
use MyInvoice\Service\Accounting\Assets\Strategy\AccountingStraightLineStrategy;
use MyInvoice\Service\Accounting\Assets\Strategy\TaxByAccountingStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Unit testy účetních rovnoměrných měsíčních odpisů dle ČÚS 013 (Epic F3,
 * spec §6.1 U20–U24) + zrcadlení daňových odpisů DNM (§24/2/v, U24).
 * Očekávané hodnoty jsou ZÁVAZNÁ ručně spočtená čísla ze specu.
 */
final class AccountingStraightLineStrategyTest extends TestCase
{
    private AccountingStraightLineStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new AccountingStraightLineStrategy();
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function ctx(array $overrides = []): DepreciationContext
    {
        $args = array_merge([
            'inputPrice' => 360000.0,
            'taxGroup' => null,
            'firstYearIncrease' => 'none',
            'isFirstOwner' => false,
            'isM1Vehicle' => false,
            'm1LimitException' => false,
            'putIntoUseDate' => '2026-05-15',
            'disposalDate' => null,
            'accUsefulLifeMonths' => 60,
            'accResidualValue' => 0.0,
            'openingTaxYears' => 0,
            'openingTaxAmount' => 0.0,
            'openingAccMonths' => 0,
            'openingAccAmount' => 0.0,
            'improvements' => [],
            'confirmedEntries' => [],
        ], $overrides);

        return new DepreciationContext(...$args);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{month:string, amount:float}>
     */
    private function allMonths(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            foreach ((array) $row['months'] as $m) {
                $out[] = ['month' => (string) $m['month'], 'amount' => (float) $m['amount']];
            }
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,float> fiscal_year => amount
     */
    private function amountsByYear(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['fiscal_year']] = (float) $r['amount'];
        }
        return $out;
    }

    public function testU20BasicMonthlyCourse(): void
    {
        $rows = $this->strategy->plan($this->ctx());

        self::assertSame(
            [2026 => 42000.0, 2027 => 72000.0, 2028 => 72000.0, 2029 => 72000.0, 2030 => 72000.0, 2031 => 30000.0],
            $this->amountsByYear($rows),
            'U20: 42 000; 4× 72 000; 30 000.',
        );

        $months = $this->allMonths($rows);
        self::assertCount(60, $months);
        self::assertSame('2026-06', $months[0]['month'], 'Start měsícem následujícím po zařazení.');
        self::assertSame(6000.0, $months[0]['amount'], 'Měsíční odpis 6 000.');
        self::assertSame(360000.0, round(array_sum(array_column($months, 'amount')), 2), 'Σ = VC.');
    }

    public function testU21ResidualValueIsNotDepreciated(): void
    {
        $rows = $this->strategy->plan($this->ctx([
            'inputPrice' => 100000.0,
            'accUsefulLifeMonths' => 36,
            'accResidualValue' => 10000.0,
            'putIntoUseDate' => '2098-12-15',
        ]));

        $months = $this->allMonths($rows);
        self::assertCount(36, $months);
        self::assertSame(array_fill(0, 36, 2500.0), array_column($months, 'amount'), 'U21: měsíčně 2 500.');
        self::assertSame(90000.0, round(array_sum(array_column($months, 'amount')), 2), 'U21: Σ = 90 000.');

        $last = $rows[count($rows) - 1];
        self::assertSame(10000.0, (float) $last['residual_end'], 'U21: účetní ZC na konci = zbytková hodnota 10 000.');
    }

    public function testU22ImprovementRecomputesProspectively(): void
    {
        $rows = $this->strategy->plan($this->ctx([
            'inputPrice' => 240000.0,
            'accUsefulLifeMonths' => 24,
            'putIntoUseDate' => '2025-12-10',
            'improvements' => [['completed_on' => '2026-06-20', 'amount' => 60000.0]],
        ]));

        $months = $this->allMonths($rows);
        self::assertCount(24, $months);
        self::assertSame('2026-01', $months[0]['month']);

        self::assertSame(array_fill(0, 6, 10000.0), array_column(array_slice($months, 0, 6), 'amount'), 'U22: 1–6/2026 à 10 000.');
        self::assertSame(array_fill(0, 17, 13333.0), array_column(array_slice($months, 6, 17), 'amount'), 'U22: od 7/2026 à 13 333 (round 240 000/18).');
        self::assertSame('2027-12', $months[23]['month']);
        self::assertSame(13339.0, $months[23]['amount'], 'U22: poslední měsíc 12/2027 = dorovnání 13 339.');
        self::assertSame(300000.0, round(array_sum(array_column($months, 'amount')), 2), 'U22: Σ = 300 000.');
    }

    public function testU23DisposalDepreciatesThroughDisposalMonth(): void
    {
        $rows = $this->strategy->plan($this->ctx(['disposalDate' => '2028-09-20']));

        $months = $this->allMonths($rows);
        self::assertCount(28, $months, 'U23: 6/2026–9/2028 = 28 měsíců.');
        self::assertSame('2028-09', $months[count($months) - 1]['month'], 'U23: odpis včetně měsíce vyřazení.');
        self::assertSame(168000.0, round(array_sum(array_column($months, 'amount')), 2), 'U23: celkem 168 000.');

        $last = $rows[count($rows) - 1];
        self::assertSame(2028, (int) $last['fiscal_year']);
        self::assertSame(54000.0, (float) $last['amount'], '2028: 9 měsíců à 6 000.');
        self::assertSame(192000.0, (float) $last['residual_end'], 'U23: účetní ZC = 192 000 (do disposal zápisu, R19).');
    }

    public function testU24TaxByAccountingMirrorsAccountingYearlySums(): void
    {
        $ctx = $this->ctx([
            'inputPrice' => 90000.0,
            'accUsefulLifeMonths' => 36,
            'putIntoUseDate' => '2098-06-10',
        ]);

        $accounting = $this->strategy->plan($ctx);
        $tax = (new TaxByAccountingStrategy())->plan($ctx);

        self::assertNotEmpty($tax);
        self::assertCount(count($accounting), $tax);
        foreach ($accounting as $i => $acc) {
            self::assertSame((int) $acc['fiscal_year'], (int) $tax[$i]['fiscal_year']);
            self::assertSame((float) $acc['amount'], (float) $tax[$i]['amount'], 'U24: daňový roční řádek = Σ účetních odpisů roku (§24/2/v).');
            self::assertSame((float) $acc['amount'], (float) $tax[$i]['full_amount']);
            self::assertFalse($tax[$i]['is_half'], 'U24: žádný půlodpis u DNM.');
        }
        self::assertSame(
            90000.0,
            round(array_sum(array_map(static fn (array $r): float => (float) $r['amount'], $tax)), 2),
            'Σ daňových = VC.',
        );
    }

    public function testYearRowMatchesPlanRow(): void
    {
        $row = $this->strategy->yearRow($this->ctx(), 2027);

        self::assertNotNull($row);
        self::assertSame(2027, (int) $row['fiscal_year']);
        self::assertSame(72000.0, (float) $row['amount']);
        self::assertSame(12, (int) $row['months_count']);
    }
}
