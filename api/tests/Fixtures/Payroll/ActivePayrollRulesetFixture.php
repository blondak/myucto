<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\RulesetApproval;

final class ActivePayrollRulesetFixture
{
    public static function provider(PayrollRulesetDomain $domain): PayrollRulesetProvider
    {
        $reviewed = CzechPayrollRulesets2026::provider()->forDate($domain, '2026-08-03');
        $approval = new RulesetApproval(
            'synthetic-independent-reviewer',
            '2026-08-02',
            'synthetic-independent-approver',
            '2026-08-03',
            'Synthetic approval used only by deterministic unit tests.',
        );
        $approved = $reviewed->transition(
            PayrollRulesetLifecycle::Approved,
            'test.' . $reviewed->id . '.approved',
            $reviewed->version . '-approved-test',
            $approval,
        );
        $active = $approved->transition(
            PayrollRulesetLifecycle::Active,
            'test.' . $reviewed->id . '.active',
            $reviewed->version . '-active-test',
            $approval,
        );

        return new PayrollRulesetProvider([$active]);
    }
}
