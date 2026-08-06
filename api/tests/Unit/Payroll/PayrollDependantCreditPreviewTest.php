<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\IncomeTax\ChildCreditRateKey;
use MyInvoice\Service\Payroll\PayrollDependantCreditPreview;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRuleValue;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetCapability;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use MyInvoice\Service\Payroll\Ruleset\RulesetApproval;
use MyInvoice\Service\Payroll\Ruleset\RulesetSource;
use MyInvoice\Service\Payroll\Ruleset\RulesetTechnicalReview;
use PHPUnit\Framework\TestCase;

final class PayrollDependantCreditPreviewTest extends TestCase
{
    public function testOrderMapsToTheSameRuleKeysAsTheCalculator(): void
    {
        self::assertSame('credit.child.first.monthly', ChildCreditRateKey::forOrder(1));
        self::assertSame('credit.child.second.monthly', ChildCreditRateKey::forOrder(2));
        self::assertSame('credit.child.third_and_next.monthly', ChildCreditRateKey::forOrder(3));
        self::assertSame('credit.child.third_and_next.monthly', ChildCreditRateKey::forOrder(9));
    }

    public function testPreviewUsesRulesetAmountForEachOrder(): void
    {
        $preview = new PayrollDependantCreditPreview($this->activeProvider());

        self::assertSame(126_700, $preview->monthly(1, false, '2026-03-01')['monthly_credit_minor_units']);
        self::assertSame(186_000, $preview->monthly(2, false, '2026-03-01')['monthly_credit_minor_units']);
        self::assertSame(232_000, $preview->monthly(3, false, '2026-03-01')['monthly_credit_minor_units']);
    }

    public function testZtpPChildDoublesTheCredit(): void
    {
        $preview = new PayrollDependantCreditPreview($this->activeProvider());

        $plain = $preview->monthly(1, false, '2026-03-01');
        $ztpP = $preview->monthly(1, true, '2026-03-01');

        self::assertSame(PayrollDependantCreditPreview::STATUS_CALCULATED, $ztpP['status']);
        self::assertSame(
            2 * (int) $plain['monthly_credit_minor_units'],
            $ztpP['monthly_credit_minor_units'],
        );
    }

    public function testMissingEffectiveRulesetFailsClosed(): void
    {
        $preview = new PayrollDependantCreditPreview($this->activeProvider());

        $result = $preview->monthly(1, false, '2019-03-01');

        self::assertSame(PayrollDependantCreditPreview::STATUS_MANUAL_REVIEW, $result['status']);
        self::assertNull($result['monthly_credit_minor_units']);
        self::assertIsString($result['manual_review_reason']);
    }

    public function testRulesetWithoutChildRateFailsClosed(): void
    {
        $preview = new PayrollDependantCreditPreview(
            new PayrollRulesetProvider([$this->rulesetWithoutChildRates()]),
        );

        $result = $preview->monthly(1, false, '2026-03-01');

        self::assertSame(PayrollDependantCreditPreview::STATUS_MANUAL_REVIEW, $result['status']);
        self::assertNull($result['monthly_credit_minor_units']);
        self::assertStringContainsString(
            'credit.child.first.monthly',
            (string) $result['manual_review_reason'],
        );
    }

    public function testManualReviewRateFailsClosed(): void
    {
        $preview = new PayrollDependantCreditPreview(
            new PayrollRulesetProvider([$this->rulesetWithManualReviewChildRate()]),
        );

        $result = $preview->monthly(1, false, '2026-03-01');

        self::assertSame(PayrollDependantCreditPreview::STATUS_MANUAL_REVIEW, $result['status']);
        self::assertNull($result['monthly_credit_minor_units']);
    }

    private function activeProvider(): PayrollRulesetProvider
    {
        $reviewed = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::IncomeTax, '2026-03-01');

        return new PayrollRulesetProvider([$this->activate($reviewed, 'baseline')]);
    }

    private function rulesetWithoutChildRates(): PayrollRulesetVersion
    {
        $parameters = [
            'credit.taxpayer.monthly' => PayrollRuleValue::moneyMinor(257_000),
            'withholding.rate' => PayrollRuleValue::rate('0.15'),
        ];

        return $this->activate($this->version($parameters, 'gap'), 'gap-active');
    }

    private function rulesetWithManualReviewChildRate(): PayrollRulesetVersion
    {
        $parameters = [
            'credit.child.first.monthly' => PayrollRuleValue::manualReview(
                'Synthetic gap used only by deterministic unit tests.',
            ),
            'credit.taxpayer.monthly' => PayrollRuleValue::moneyMinor(257_000),
        ];

        return $this->activate($this->version($parameters, 'manual'), 'manual-active');
    }

    /** @param array<string,PayrollRuleValue> $parameters */
    private function version(array $parameters, string $suffix): PayrollRulesetVersion
    {
        ksort($parameters, SORT_STRING);

        return new PayrollRulesetVersion(
            'test.cz-payroll-2026.income-tax.' . $suffix,
            '2026.9.0-' . $suffix,
            PayrollRulesetDomain::IncomeTax,
            '2026-01-01',
            '2026-12-31',
            PayrollRulesetLifecycle::Reviewed,
            PayrollRulesetCapability::Supported,
            [new RulesetSource(
                'synthetic-source-' . $suffix,
                'Synthetic source used only by deterministic unit tests.',
                'https://example.test/synthetic',
                '2026-08-03',
            )],
            $parameters,
            null,
            $this->technicalReview(),
        );
    }

    private function activate(PayrollRulesetVersion $reviewed, string $suffix): PayrollRulesetVersion
    {
        $approval = new RulesetApproval(
            'synthetic-independent-reviewer',
            '2026-08-02',
            'synthetic-independent-approver',
            '2026-08-03',
            'Synthetic approval used only by deterministic unit tests.',
        );

        return $reviewed
            ->transition(
                PayrollRulesetLifecycle::Approved,
                'test.income-tax.approved.' . $suffix,
                '2026.9.1-' . $suffix,
                $approval,
            )
            ->transition(
                PayrollRulesetLifecycle::Active,
                'test.income-tax.active.' . $suffix,
                '2026.9.2-' . $suffix,
                $approval,
            );
    }

    private function technicalReview(): RulesetTechnicalReview
    {
        return new RulesetTechnicalReview(
            'myucto/payroll-ruleset-source-check',
            '2026-08-03',
            'Synthetic technical review used only by deterministic unit tests.',
        );
    }
}
