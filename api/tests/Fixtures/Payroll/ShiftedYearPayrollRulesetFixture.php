<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;

/**
 * „Posun kalendáře o rok" — registry, ve které vedle ověřeného roku 2026 existují
 * i rulesety pro další rok. Slouží k důkazu, že mzdový modul jede pro rok, pro
 * který ruleset EXISTUJE, a ne pro rok zadrátovaný v kódu. Hodnoty se přebírají
 * beze změny, mění se jen účinnost — je to fixture pro test brány, ne právní data.
 */
final class ShiftedYearPayrollRulesetFixture
{
    public static function provider(int ...$additionalYears): PayrollRulesetProvider
    {
        $base = CzechPayrollRulesets2026::provider()->versions();
        $versions = $base;
        foreach ($additionalYears as $year) {
            foreach ($base as $version) {
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
                    $version->approval,
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
