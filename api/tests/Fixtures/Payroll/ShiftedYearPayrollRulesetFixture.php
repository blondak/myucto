<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;
use MyInvoice\Service\Payroll\Ruleset\RulesetApproval;

/**
 * „Posun kalendáře o rok" — registry, ve které vedle ověřeného roku 2026 existují
 * i rulesety pro další rok. Slouží k důkazu, že mzdový modul jede pro rok, pro
 * který ruleset EXISTUJE, a ne pro rok zadrátovaný v kódu. Hodnoty se přebírají
 * beze změny, mění se jen účinnost — je to fixture pro test brány, ne právní data.
 *
 * Posunutá kopie NENÍ dodaná sada (jiné ID i účinnost, tedy jiný otisk obsahu),
 * takže si účinnost musí zaplatit schválením jako každý zákaznický přepis.
 * Právě proto je schválení výslovně syntetické.
 */
final class ShiftedYearPayrollRulesetFixture
{
    public static function provider(int ...$additionalYears): PayrollRulesetProvider
    {
        $base = CzechPayrollRulesets2026::provider()->versions();
        $versions = $base;
        $approval = new RulesetApproval(
            'synthetic-independent-reviewer',
            '2026-08-02',
            'synthetic-independent-approver',
            '2026-08-03',
            'Synthetic approval used only by deterministic tests of the year gate.',
        );
        foreach ($additionalYears as $year) {
            foreach ($base as $version) {
                $needsApproval = in_array($version->lifecycle, [
                    PayrollRulesetLifecycle::Approved,
                    PayrollRulesetLifecycle::Active,
                    PayrollRulesetLifecycle::Superseded,
                ], true);
                $versions[] = new PayrollRulesetVersion(
                    $version->id . '.shifted-' . $year,
                    $year . '.' . substr($version->version, 5),
                    $version->domain,
                    self::shift($version->effectiveFrom, $year),
                    self::shift($version->effectiveTo, $year),
                    $version->lifecycle,
                    $version->capability,
                    $version->sources,
                    $version->parameters,
                    $needsApproval ? ($version->approval ?? $approval) : $version->approval,
                    $version->technicalReview,
                );
            }
        }

        return new PayrollRulesetProvider(array_values($versions));
    }

    private static function shift(string $date, int $year): string
    {
        return sprintf('%04d%s', $year, substr($date, 4));
    }
}
