<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\StatementMapper;
use MyInvoice\Service\Accounting\Reports\StatementMapResolver;
use PHPUnit\Framework\TestCase;

/**
 * Slití výjimek firmy do mapy výkazu ({@see StatementMapResolver::applyOverrides()}).
 *
 * Pravidla: nejdelší prefix vyhrává, při stejné délce vyhrává výjimka; výjimka jen pro
 * jednu stranu saldového účtu převezme druhou stranu z mapy bez výjimky, jinak by ji mapper
 * nenamapoval a guard nepárového saldového prefixu by výkaz odmítl.
 */
final class StatementMapResolverTest extends TestCase
{
    private const ROWS = [
        ['row_code' => 'P.C.I.9.1.', 'section' => 'liabilities'],
        ['row_code' => 'P.C.II.8.1.', 'section' => 'liabilities'],
        ['row_code' => 'C.II.2.4.', 'section' => 'assets'],
        ['row_code' => 'P.C.II.8.5.', 'section' => 'liabilities'],
        ['row_code' => 'P.C.I.9.3.', 'section' => 'liabilities'],
    ];

    /** @return list<array<string,mixed>> */
    private static function globalMap(): array
    {
        return [
            ['row_code' => 'P.C.II.8.1.', 'account_prefix' => '365', 'target' => 'gross', 'balance_condition' => 'any', 'sign' => 1, 'source' => 'global'],
            ['row_code' => 'C.II.2.4.', 'account_prefix' => '343', 'target' => 'gross', 'balance_condition' => 'debit', 'sign' => 1, 'source' => 'global'],
            ['row_code' => 'P.C.II.8.5.', 'account_prefix' => '343', 'target' => 'gross', 'balance_condition' => 'credit', 'sign' => 1, 'source' => 'global'],
        ];
    }

    /** @param array<string,mixed> $extra */
    private static function override(string $prefix, string $row, string $condition = 'any', array $extra = []): array
    {
        return $extra + [
            'id' => 7, 'account_prefix' => $prefix, 'row_code' => $row,
            'target' => 'gross', 'balance_condition' => $condition, 'sign' => 1,
        ];
    }

    public function testWithoutOverridesTheGlobalMapIsReturnedUnchanged(): void
    {
        self::assertSame(self::globalMap(), StatementMapResolver::applyOverrides(self::globalMap(), []));
    }

    public function testAnalyticOverrideMovesOnlyThatAnalytic(): void
    {
        $map = StatementMapResolver::applyOverrides(self::globalMap(), [self::override('365.100', 'P.C.I.9.1.')]);
        $mapper = new StatementMapper();

        $mapped = $mapper->map(self::ROWS, $map, [
            ['account_id' => 1, 'code' => '365.100', 'name' => 'Půjčka společníka', 'account_type' => 'liability', 'md' => 0.0, 'd' => 500_000.0],
            ['account_id' => 2, 'code' => '365', 'name' => 'Ostatní závazky ke společníkům', 'account_type' => 'liability', 'md' => 0.0, 'd' => 20_000.0],
        ]);

        self::assertEqualsWithDelta(500_000.0, $mapped['P.C.I.9.1.']['gross'], 0.001, 'Analytika s výjimkou jde do dlouhodobých závazků.');
        self::assertEqualsWithDelta(20_000.0, $mapped['P.C.II.8.1.']['gross'], 0.001, 'Zbytek syntetiky zůstává podle globální mapy.');
    }

    public function testOverrideWinsOverGlobalEntryWithTheSamePrefix(): void
    {
        $map = StatementMapResolver::applyOverrides(self::globalMap(), [self::override('365', 'P.C.I.9.1.')]);

        $for365 = array_values(array_filter($map, static fn (array $m): bool => $m['account_prefix'] === '365'));
        self::assertCount(1, $for365, 'Globální záznam se stejným prefixem musí zmizet.');
        self::assertSame('P.C.I.9.1.', $for365[0]['row_code']);
        self::assertSame('override', $for365[0]['source']);
        self::assertSame(7, $for365[0]['override_id']);
    }

    public function testOneSidedOverrideInheritsTheOtherSideAndStaysPaired(): void
    {
        $map = StatementMapResolver::applyOverrides(self::globalMap(), [self::override('343.200', 'P.C.I.9.3.', 'credit')]);
        $mapper = new StatementMapper();

        $for = array_values(array_filter($map, static fn (array $m): bool => $m['account_prefix'] === '343.200'));
        self::assertCount(2, $for);
        $byCondition = array_column($for, 'row_code', 'balance_condition');
        self::assertSame('P.C.I.9.3.', $byCondition['credit']);
        self::assertSame('C.II.2.4.', $byCondition['debit'], 'Debetní strana se převezme z globální mapy syntetiky.');
        self::assertContains('343.200', $mapper->noCompensationPrefixes($map), 'Prefix musí zůstat párový.');

        $mapped = $mapper->map(self::ROWS, $map, [
            ['account_id' => 3, 'code' => '343.200', 'name' => 'DPH', 'account_type' => 'liability', 'md' => 1_000.0, 'd' => 0.0],
        ]);
        self::assertEqualsWithDelta(1_000.0, $mapped['C.II.2.4.']['gross'], 0.001, 'Debetní zůstatek jde dál do aktiv.');
    }

    public function testValidInKeepsOnlyOverridesValidInTheYear(): void
    {
        $overrides = [
            self::override('365.100', 'P.C.II.8.1.', 'any', ['valid_to_year' => 2023]),
            self::override('365.100', 'P.C.I.9.1.', 'any', ['valid_from_year' => 2024]),
            self::override('365.200', 'P.C.I.9.1.'),
        ];

        self::assertSame(['P.C.II.8.1.', 'P.C.I.9.1.'], array_column(StatementMapResolver::validIn($overrides, 2023), 'row_code'));
        self::assertSame(['P.C.I.9.1.', 'P.C.I.9.1.'], array_column(StatementMapResolver::validIn($overrides, 2024), 'row_code'));
        self::assertSame($overrides, StatementMapResolver::validIn($overrides, null), 'Bez roku se platnost nekontroluje.');
    }

    public function testCorrectionFollowsTheRowOfItsReceivable(): void
    {
        $global = [...self::globalMap(),
            ['row_code' => 'C.II.2.1.', 'account_prefix' => '391', 'target' => 'correction', 'balance_condition' => 'any', 'sign' => 1, 'source' => 'global'],
            ['row_code' => 'C.II.2.2.', 'account_prefix' => '351', 'target' => 'gross', 'balance_condition' => 'any', 'sign' => 1, 'source' => 'global'],
        ];
        $follower = self::override('391.100', 'C.II.2.1.', 'any', ['target' => 'correction', 'follows_prefix' => '351.100']);

        $withoutReceivableOverride = StatementMapResolver::applyOverrides($global, [$follower]);
        $entry = (new StatementMapper())->entriesFor($withoutReceivableOverride, '391.100', -500)[0];
        self::assertSame('C.II.2.2.', $entry['row_code'], 'Pohledávka podle globální mapy, korekce za ní.');

        $moved = StatementMapResolver::applyOverrides($global, [$follower, self::override('351.100', 'C.II.1.5.4.')]);
        $entry = (new StatementMapper())->entriesFor($moved, '391.100', -500)[0];
        self::assertSame('C.II.1.5.4.', $entry['row_code'], 'Po přeřazení pohledávky jde korekce s ní.');
        self::assertSame('correction', $entry['target']);
    }

    public function testLongerGlobalPrefixStillBeatsShorterOverride(): void
    {
        $global = [...self::globalMap(),
            ['row_code' => 'P.C.II.8.5.', 'account_prefix' => '365.9', 'target' => 'gross', 'balance_condition' => 'any', 'sign' => 1, 'source' => 'global'],
        ];
        $map = StatementMapResolver::applyOverrides($global, [self::override('365', 'P.C.I.9.1.')]);
        $entries = (new StatementMapper())->entriesFor($map, '365.900', -100);

        self::assertCount(1, $entries);
        self::assertSame('P.C.II.8.5.', $entries[0]['row_code'], 'Nejdelší prefix vyhrává i nad výjimkou.');
    }
}
