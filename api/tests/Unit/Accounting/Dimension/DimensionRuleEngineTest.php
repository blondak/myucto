<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Dimension;

use MyInvoice\Service\Accounting\Dimension\DimensionAccountMask;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleEngine;
use MyInvoice\Service\Accounting\Dimension\DimensionStamper;
use PHPUnit\Framework\TestCase;

/**
 * Maska účtů a čisté jádro pravidel dimenzí ({@see DimensionRuleEngine}) + náhrada
 * zástupného rozpadu dokladu ({@see DimensionStamper::expandSplits()}).
 */
final class DimensionRuleEngineTest extends TestCase
{
    private const CENTER = 20;
    private const VEHICLE = 30;

    public function testMaskIncludesExcludesAndNormalizes(): void
    {
        $mask = DimensionAccountMask::parse(' 6;5  !59, !69 518 ');
        self::assertSame('5, 518, 6, !59, !69', $mask->normalized());
        self::assertTrue($mask->matches('518100'));
        self::assertTrue($mask->matches('602'));
        self::assertFalse($mask->matches('591'), 'Vyloučená předpona má přednost.');
        self::assertFalse($mask->matches('311'));
        self::assertSame(3, $mask->specificity('518.100'));
        self::assertSame(1, $mask->specificity('501'));
    }

    public function testMaskIsCaseInsensitiveLikeTheDatabaseCollation(): void
    {
        $mask = DimensionAccountMask::parse('311d');
        self::assertTrue($mask->matches('311D'));
        self::assertSame('311D', $mask->normalized());
    }

    public function testMaskSqlUsesPrefixLikes(): void
    {
        [$sql, $params] = DimensionAccountMask::parse('5, 6, !59')->sql('a.account_code');
        self::assertSame('((a.account_code LIKE ? OR a.account_code LIKE ?) AND a.account_code NOT LIKE ?)', $sql);
        self::assertSame(['5%', '6%', '59%'], $params);
    }

    public function testMaskRejectsGarbageAndExcludeOnly(): void
    {
        foreach (['5%', '', '!59', "5\n6x'"] as $bad) {
            try {
                DimensionAccountMask::parse($bad);
                self::fail('Maska „' . $bad . '" měla být odmítnuta.');
            } catch (DimensionException $e) {
                self::assertSame('invalid_account_mask', $e->errorCode);
            }
        }
    }

    public function testMissingDimensionIsViolationWithStrictestEnforcement(): void
    {
        $rules = [
            $this->rule(1, '5', 'warning'),
            $this->rule(2, '518', 'error'),
        ];
        $result = DimensionRuleEngine::apply($rules, [
            ['account_code' => '518100', 'amount' => 100],
            ['account_code' => '501', 'amount' => 50],
            ['account_code' => '321', 'amount' => 150],
        ], '2026-05-01', null);
        self::assertSame([
            ['line' => 0, 'account_code' => '518100', 'type_id' => self::CENTER, 'enforcement' => 'error', 'rule_id' => 2],
            ['line' => 1, 'account_code' => '501', 'type_id' => self::CENTER, 'enforcement' => 'warning', 'rule_id' => 1],
        ], $result['violations']);
    }

    public function testExistingValueOrSplitSatisfiesRule(): void
    {
        $result = DimensionRuleEngine::apply([$this->rule(1, '5', 'error', 999)], [
            ['account_code' => '518', 'dimensions' => [self::CENTER => 5]],
            ['account_code' => '518', 'dimension_splits' => [self::CENTER => [5 => 0.5, 6 => 0.5]]],
        ], '2026-05-01', null);
        self::assertSame([], $result['violations']);
        self::assertSame(0, $result['defaults'], 'Hodnota z dokladu nebo rozpad mají přednost před výchozí hodnotou.');
        self::assertSame([self::CENTER => 5], $result['lines'][0]['dimensions']);
        self::assertArrayNotHasKey('dimensions', $result['lines'][1]);
    }

    public function testDefaultFromMostSpecificRuleFillsMissingType(): void
    {
        $rules = [
            $this->rule(1, '5', 'error', 100),
            $this->rule(2, '518', 'none', 200),
        ];
        $result = DimensionRuleEngine::apply($rules, [
            ['account_code' => '518', 'dimensions' => [7 => 70]],
            ['account_code' => '501'],
        ], '2026-05-01', null);
        self::assertSame([], $result['violations']);
        self::assertSame(2, $result['defaults']);
        self::assertSame([7 => 70, self::CENTER => 200], $result['lines'][0]['dimensions']);
        self::assertSame([self::CENTER => 100], $result['lines'][1]['dimensions']);
    }

    public function testCheckOnlyModeNeverFillsDefaults(): void
    {
        $result = DimensionRuleEngine::apply([$this->rule(1, '5', 'error', 100)], [['account_code' => '518']], '2026-05-01', null, false);
        self::assertCount(1, $result['violations']);
        self::assertArrayNotHasKey('dimensions', $result['lines'][0]);
    }

    public function testVehicleFromCardBeforeFixedDefaultAndResolvedOnce(): void
    {
        $calls = 0;
        $card = function (int $typeId) use (&$calls): ?int {
            $calls++;
            return $typeId === self::VEHICLE ? 300 : null;
        };
        $rules = [$this->rule(1, '50, 51', 'error', 301, self::VEHICLE, true)];
        $result = DimensionRuleEngine::apply($rules, [
            ['account_code' => '501'],
            ['account_code' => '511'],
        ], '2026-05-01', $card);
        self::assertSame([self::VEHICLE => 300], $result['lines'][0]['dimensions']);
        self::assertSame([self::VEHICLE => 300], $result['lines'][1]['dimensions']);
        self::assertSame(1, $calls, 'Karta dokladu se dohledá jednou za typ.');

        $noCard = DimensionRuleEngine::apply($rules, [['account_code' => '501']], '2026-05-01', static fn (int $t): ?int => null);
        self::assertSame([self::VEHICLE => 301], $noCard['lines'][0]['dimensions'], 'Bez karty platí pevná výchozí hodnota.');
    }

    public function testValidityWindowByEntryDate(): void
    {
        $rule = $this->rule(1, '5', 'error') + [];
        $rule['valid_from'] = '2026-01-01';
        $rule['valid_to'] = '2026-12-31';
        $line = [['account_code' => '518']];
        self::assertSame([], DimensionRuleEngine::apply([$rule], $line, '2025-12-31', null)['violations']);
        self::assertCount(1, DimensionRuleEngine::apply([$rule], $line, '2026-01-01', null)['violations']);
        self::assertCount(1, DimensionRuleEngine::apply([$rule], $line, '2026-12-31', null)['violations']);
        self::assertSame([], DimensionRuleEngine::apply([$rule], $line, '2027-01-01', null)['violations']);
    }

    public function testNoneEnforcementWithoutUsableDefaultIsSilent(): void
    {
        $result = DimensionRuleEngine::apply([$this->rule(1, '5', 'none')], [['account_code' => '518']], '2026-05-01', null);
        self::assertSame([], $result['violations']);
    }

    public function testExpandSplitsReplacesPlaceholderAndExplicitSplitWins(): void
    {
        $lines = DimensionStamper::expandSplits([
            ['account_id' => 1, 'dimensions' => [self::CENTER => -1, 7 => 70]],
            ['account_id' => 1, 'dimensions' => [self::CENTER => 5], 'dimension_splits' => [self::CENTER => [8 => 0.25, 9 => 0.75]]],
            ['account_id' => 1, 'dimensions' => [self::CENTER => -2]],
        ], [[5 => 0.6, 6 => 0.4], [11 => 0.5, 12 => 0.5]]);
        self::assertSame([7 => 70], $lines[0]['dimensions']);
        self::assertSame([self::CENTER => [5 => 0.6, 6 => 0.4]], $lines[0]['dimension_splits']);
        self::assertArrayNotHasKey('dimensions', $lines[1], 'Ruční rozpad řádku přebíjí jedinou hodnotu téhož typu.');
        self::assertSame([self::CENTER => [8 => 0.25, 9 => 0.75]], $lines[1]['dimension_splits']);
        self::assertSame([self::CENTER => [11 => 0.5, 12 => 0.5]], $lines[2]['dimension_splits']);
    }

    public function testDocumentSplitGroupsLikeAValueInAssign(): void
    {
        // Dvě položky se stejným rozpadem (zástupná hodnota -1) a jedna s hodnotou 5:
        // nákladový řádek se rozdělí na dva díly, rozpad jde na díl položek s rozpadem.
        $lines = [
            ['account_id' => 1, 'side' => 'debit', 'amount' => 1000.00],
            ['account_id' => 3, 'side' => 'credit', 'amount' => 1000.00],
        ];
        $items = [
            ['dims' => [self::CENTER => -1], 'weight' => 300.0, 'account_id' => null],
            ['dims' => [self::CENTER => -1], 'weight' => 300.0, 'account_id' => null],
            ['dims' => [self::CENTER => 5], 'weight' => 400.0, 'account_id' => null],
        ];
        $assigned = DimensionStamper::assign($lines, [], $items, [1 => 'expense', 3 => 'liability'], true);
        $out = DimensionStamper::expandSplits($assigned['lines'], [[6 => 0.5, 7 => 0.5]]);
        self::assertCount(3, $out);
        self::assertEquals(600.0, $out[0]['amount']);
        self::assertSame([self::CENTER => [6 => 0.5, 7 => 0.5]], $out[0]['dimension_splits']);
        self::assertEquals(400.0, $out[1]['amount']);
        self::assertSame([self::CENTER => 5], $out[1]['dimensions']);
    }

    /** @return array{id:int, dimension_type_id:int, mask:DimensionAccountMask, enforcement:string, default_value_id:?int, default_from_card:bool, valid_from:?string, valid_to:?string} */
    private function rule(int $id, string $mask, string $enforcement, ?int $default = null, int $type = self::CENTER, bool $card = false): array
    {
        return [
            'id' => $id,
            'dimension_type_id' => $type,
            'mask' => DimensionAccountMask::parse($mask),
            'enforcement' => $enforcement,
            'default_value_id' => $default,
            'default_from_card' => $card,
            'valid_from' => null,
            'valid_to' => null,
        ];
    }
}
