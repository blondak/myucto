<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\StatementMapper;
use MyInvoice\Service\Accounting\Reports\TaxAuthorityOffset;
use PHPUnit\Framework\TestCase;

/**
 * Souhrnné vykázání daní vůči finančnímu úřadu (§ 58 odst. 2 vyhl. 500/2002 Sb.): započte
 * se menší ze stran, obě strany klesnou o stejnou částku a nedaňové účty zůstanou.
 */
final class TaxAuthorityOffsetTest extends TestCase
{
    private const ROWS = [
        ['row_code' => 'C.II.2.4.3.', 'section' => 'assets'],
        ['row_code' => 'P.C.II.8.4.', 'section' => 'liabilities'],
        ['row_code' => 'P.C.II.8.5.', 'section' => 'liabilities'],
    ];

    private const MAP = [
        ['row_code' => 'C.II.2.4.3.', 'account_prefix' => '341', 'target' => 'gross', 'balance_condition' => 'debit', 'sign' => 1],
        ['row_code' => 'P.C.II.8.5.', 'account_prefix' => '341', 'target' => 'gross', 'balance_condition' => 'credit', 'sign' => 1],
        ['row_code' => 'C.II.2.4.3.', 'account_prefix' => '343', 'target' => 'gross', 'balance_condition' => 'debit', 'sign' => 1],
        ['row_code' => 'P.C.II.8.5.', 'account_prefix' => '343', 'target' => 'gross', 'balance_condition' => 'credit', 'sign' => 1],
        ['row_code' => 'C.II.2.4.3.', 'account_prefix' => '346', 'target' => 'gross', 'balance_condition' => 'debit', 'sign' => 1],
        ['row_code' => 'P.C.II.8.5.', 'account_prefix' => '346', 'target' => 'gross', 'balance_condition' => 'credit', 'sign' => 1],
        ['row_code' => 'P.C.II.8.4.', 'account_prefix' => '336', 'target' => 'gross', 'balance_condition' => 'any', 'sign' => 1],
    ];

    /** @param array<string,float> $balances kód účtu → md − d */
    private static function mapped(array $balances): array
    {
        $rows = [];
        $id = 1;
        foreach ($balances as $code => $balance) {
            $rows[] = [
                'account_id' => $id++, 'code' => (string) $code, 'name' => 'Účet ' . $code,
                'account_type' => $balance >= 0 ? 'asset' : 'liability',
                'md' => max(0.0, $balance), 'd' => max(0.0, -$balance),
            ];
        }

        return (new StatementMapper())->map(self::ROWS, self::MAP, $rows);
    }

    public function testSmallerSideIsOffsetOnBothSides(): void
    {
        $out = TaxAuthorityOffset::apply(self::ROWS, self::mapped(['341' => 30_000.0, '343' => -50_000.0]));

        self::assertSame(30_000.0, $out['amount']);
        self::assertSame(0.0, $out['mapped']['C.II.2.4.3.']['gross']);
        self::assertSame(20_000.0, $out['mapped']['P.C.II.8.5.']['gross']);
        self::assertSame(TaxAuthorityOffset::LABEL, $out['mapped']['P.C.II.8.5.']['accounts'][1]['name']);
        self::assertSame(-30_000.0, $out['mapped']['P.C.II.8.5.']['accounts'][1]['amount']);
    }

    public function testSubsidiesAndSocialInsuranceAreNotOffset(): void
    {
        $out = TaxAuthorityOffset::apply(self::ROWS, self::mapped(['346' => 10_000.0, '343' => -50_000.0, '336' => -8_000.0]));

        self::assertSame(0.0, $out['amount'], 'Dotace ani sociální pojištění nejsou daň vůči finančnímu úřadu.');
        self::assertSame(10_000.0, $out['mapped']['C.II.2.4.3.']['gross']);
        self::assertSame(50_000.0, $out['mapped']['P.C.II.8.5.']['gross']);
        self::assertSame(8_000.0, $out['mapped']['P.C.II.8.4.']['gross']);
    }

    public function testSubsidyInTheSameRowStaysWhenTaxesAreOffset(): void
    {
        $out = TaxAuthorityOffset::apply(self::ROWS, self::mapped(['341' => 5_000.0, '346' => 10_000.0, '343' => -2_000.0]));

        self::assertSame(2_000.0, $out['amount']);
        self::assertSame(13_000.0, $out['mapped']['C.II.2.4.3.']['gross'], 'Z daňové pohledávky se odečte jen daňový závazek.');
        self::assertSame(0.0, $out['mapped']['P.C.II.8.5.']['gross']);
    }

    public function testNothingToOffsetWithOneSideOnly(): void
    {
        $mapped = self::mapped(['341' => 5_000.0]);
        $out = TaxAuthorityOffset::apply(self::ROWS, $mapped);

        self::assertSame(0.0, $out['amount']);
        self::assertSame($mapped, $out['mapped']);
    }
}
