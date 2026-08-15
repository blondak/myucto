<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Ruleset;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRuleValue;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetCapability;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetOrigin;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use MyInvoice\Service\Payroll\Ruleset\RulesetApproval;
use MyInvoice\Service\Payroll\Ruleset\RulesetSource;
use MyInvoice\Service\Payroll\Ruleset\RulesetTechnicalReview;
use PHPUnit\Framework\TestCase;

final class PayrollRulesetLifecycleTest extends TestCase
{
    public function testLifecycleAllowsOnlyTheDeclaredSequence(): void
    {
        self::assertTrue(PayrollRulesetLifecycle::Draft->canTransitionTo(PayrollRulesetLifecycle::Reviewed));
        self::assertTrue(PayrollRulesetLifecycle::Reviewed->canTransitionTo(PayrollRulesetLifecycle::Approved));
        self::assertTrue(PayrollRulesetLifecycle::Approved->canTransitionTo(PayrollRulesetLifecycle::Active));
        self::assertTrue(PayrollRulesetLifecycle::Active->canTransitionTo(PayrollRulesetLifecycle::Superseded));
        self::assertFalse(PayrollRulesetLifecycle::Active->canTransitionTo(PayrollRulesetLifecycle::Draft));
        self::assertFalse(PayrollRulesetLifecycle::Superseded->canTransitionTo(PayrollRulesetLifecycle::Active));
    }

    /**
     * Výjimka pro dodanou sadu je vázaná na OBSAH, ne na cestu ke konstruktoru.
     * Stačí jediná změněná hodnota — a účinnost si znovu žádá schválení, přestože
     * všechno ostatní (ID, verze, účinnost, zdroje) zůstalo stejné.
     */
    public function testChangingASingleDeliveredValueRevokesTheVendorExemption(): void
    {
        $delivered = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::IncomeTax, '2026-08-03');
        self::assertSame(PayrollRulesetOrigin::Vendor, $delivered->origin);
        self::assertSame(PayrollRulesetLifecycle::Active, $delivered->lifecycle);
        self::assertNull($delivered->approval);

        $parameters = $delivered->parameters;
        $parameters['advance.low_rate'] = PayrollRuleValue::rate('0.16');
        ksort($parameters, SORT_STRING);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('require approval evidence');
        new PayrollRulesetVersion(
            $delivered->id,
            $delivered->version,
            $delivered->domain,
            $delivered->effectiveFrom,
            $delivered->effectiveTo,
            PayrollRulesetLifecycle::Active,
            $delivered->capability,
            $delivered->sources,
            $parameters,
            null,
            $delivered->technicalReview,
        );
    }

    /**
     * Původ je ODVOZENÝ, ne předaný. Přejmenovaná kopie dodané sady — se stejnými
     * hodnotami i zdroji — už dodaná sada není a bez schválení účinná nebude.
     */
    public function testRenamedCopyOfTheDeliveredSetIsNoLongerTheDeliveredSet(): void
    {
        $delivered = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::IncomeTax, '2026-08-03');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('require approval evidence');
        new PayrollRulesetVersion(
            $delivered->id . '.copy',
            $delivered->version,
            $delivered->domain,
            $delivered->effectiveFrom,
            $delivered->effectiveTo,
            PayrollRulesetLifecycle::Active,
            $delivered->capability,
            $delivered->sources,
            $delivered->parameters,
            null,
            $delivered->technicalReview,
        );
    }

    public function testActiveRulesetRequiresApprovalEvidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('require approval evidence');
        new PayrollRulesetVersion(
            'test.tax.active',
            '1.0.0',
            PayrollRulesetDomain::IncomeTax,
            '2026-01-01',
            '2026-12-31',
            PayrollRulesetLifecycle::Active,
            PayrollRulesetCapability::Supported,
            [$this->source()],
            ['rate' => PayrollRuleValue::rate('0.15')],
            null,
            $this->technicalReview(),
        );
    }

    public function testTransitionCreatesANewImmutableVersion(): void
    {
        $draft = $this->version(PayrollRulesetLifecycle::Draft, null);
        $reviewed = $draft->transition(
            PayrollRulesetLifecycle::Reviewed,
            'test.tax.reviewed',
            '1.0.1',
            null,
            $this->technicalReview(),
        );

        self::assertSame(PayrollRulesetLifecycle::Draft, $draft->lifecycle);
        self::assertSame(PayrollRulesetLifecycle::Reviewed, $reviewed->lifecycle);
        self::assertNotSame($draft->id, $reviewed->id);
        self::assertNotSame($draft->canonicalHash, $reviewed->canonicalHash);
    }

    public function testLifecycleCannotBeChangedUnderTheSameIdentity(): void
    {
        $draft = $this->version(PayrollRulesetLifecycle::Draft, null);

        $this->expectException(PayrollRulesetException::class);
        $this->expectExceptionMessage('new ID and version');
        $draft->transition(PayrollRulesetLifecycle::Reviewed, $draft->id, $draft->version, null);
    }

    public function testInvalidChecksumIsRejected(): void
    {
        $this->expectException(PayrollRulesetException::class);
        $this->expectExceptionMessage('checksum mismatch');

        new PayrollRulesetVersion(
            'test.tax.checksum',
            '1.0.0',
            PayrollRulesetDomain::IncomeTax,
            '2026-01-01',
            '2026-12-31',
            PayrollRulesetLifecycle::Draft,
            PayrollRulesetCapability::Supported,
            [$this->source()],
            ['rate' => PayrollRuleValue::rate('0.15')],
            null,
            null,
            str_repeat('0', 64),
        );
    }

    public function testReviewAndApprovalRequireDifferentIdentities(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('different identities');
        new RulesetApproval(
            'same-person',
            '2026-08-02',
            'same-person',
            '2026-08-03',
            'Synthetic test approval.',
        );
    }

    public function testReviewedRulesetHasTechnicalCheckButCannotClaimProfessionalApproval(): void
    {
        $approval = new RulesetApproval(
            'reviewer',
            '2026-08-02',
            'approver',
            '2026-08-03',
            'External approval reference.',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot claim professional approval');
        new PayrollRulesetVersion(
            'test.tax.reviewed',
            '1.0.0',
            PayrollRulesetDomain::IncomeTax,
            '2026-01-01',
            '2026-12-31',
            PayrollRulesetLifecycle::Reviewed,
            PayrollRulesetCapability::Supported,
            [$this->source()],
            ['rate' => PayrollRuleValue::rate('0.15')],
            $approval,
            $this->technicalReview(),
        );
    }

    private function version(
        PayrollRulesetLifecycle $lifecycle,
        ?RulesetApproval $approval,
    ): PayrollRulesetVersion {
        return new PayrollRulesetVersion(
            'test.tax.draft',
            '1.0.0',
            PayrollRulesetDomain::IncomeTax,
            '2026-01-01',
            '2026-12-31',
            $lifecycle,
            PayrollRulesetCapability::Supported,
            [$this->source()],
            ['rate' => PayrollRuleValue::rate('0.15')],
            $approval,
        );
    }

    private function source(): RulesetSource
    {
        return new RulesetSource(
            'test-source',
            'Synthetic primary source',
            'https://example.invalid/source',
            '2026-08-03',
        );
    }

    private function technicalReview(): RulesetTechnicalReview
    {
        return new RulesetTechnicalReview(
            'technical-check',
            '2026-08-01',
            'Synthetic technical check.',
        );
    }
}
