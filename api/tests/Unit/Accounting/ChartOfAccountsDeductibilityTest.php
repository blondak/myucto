<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\ChartOfAccountsTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Epic DP (issue #18) — daňová uznatelnost nákladů dle §25 ZDP. Ověřuje, že seed
 * flagů (šablona i migrace 1030) klasifikuje syntetiky i analytiky konzistentně.
 */
final class ChartOfAccountsDeductibilityTest extends TestCase
{
    public function testNonDeductibleSynthetics(): void
    {
        foreach (['513', '528', '543', '545', '549', '554', '559'] as $code) {
            self::assertSame('non_deductible', ChartOfAccountsTemplate::taxDeductibility($code), $code);
        }
    }

    public function testAnalyticsInheritFromSynthetic(): void
    {
        // Analytika dědí ze syntetiky (LEFT 3 znaky).
        self::assertSame('non_deductible', ChartOfAccountsTemplate::taxDeductibility('543001'));
        self::assertSame('non_deductible', ChartOfAccountsTemplate::taxDeductibility('513900'));
        self::assertSame('deductible', ChartOfAccountsTemplate::taxDeductibility('518001'));
    }

    public function testExplicitNonDeductibleAnalyticsOverrideSyntheticClassification(): void
    {
        foreach (['501.990', '511.990', '518.990', '548.990'] as $code) {
            self::assertSame('non_deductible', ChartOfAccountsTemplate::taxDeductibility($code), $code);
        }
        self::assertSame('518.990', ChartOfAccountsTemplate::nonDeductibleAnalyticFor('518'));
        self::assertSame('518.990', ChartOfAccountsTemplate::nonDeductibleAnalyticFor('518.100'));
        self::assertNull(ChartOfAccountsTemplate::nonDeductibleAnalyticFor('042'));
    }

    public function testDeductibleAndSpecialAccounts(): void
    {
        // Běžné náklady jsou daňové.
        self::assertSame('deductible', ChartOfAccountsTemplate::taxDeductibility('501'));
        self::assertSame('deductible', ChartOfAccountsTemplate::taxDeductibility('518'));
        // Odpisy 551 a daň 59x se NEflagují jako nedaňové — mají vlastní mechaniku.
        self::assertSame('deductible', ChartOfAccountsTemplate::taxDeductibility('551'));
        self::assertSame('deductible', ChartOfAccountsTemplate::taxDeductibility('591'));
        self::assertSame('deductible', ChartOfAccountsTemplate::taxDeductibility('599'));
    }

    public function testEveryTemplateExpenseAccountResolves(): void
    {
        foreach (ChartOfAccountsTemplate::ACCOUNTS as $acc) {
            $d = ChartOfAccountsTemplate::taxDeductibility($acc['code']);
            self::assertContains($d, ['deductible', 'non_deductible'], $acc['code']);
        }
    }
}
