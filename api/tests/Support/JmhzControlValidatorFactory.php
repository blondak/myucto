<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlEvaluator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;

final class JmhzControlValidatorFactory
{
    private static ?JmhzControlSourceCatalog $controlCatalog = null;
    private static ?JmhzExternalCodebookCatalog $externalCodebooks = null;
    private static ?JmhzCodebookCatalog $codebooks = null;

    public static function create(): JmhzScenario1ControlValidator
    {
        $catalog = self::$controlCatalog ??= JmhzControlSourceCatalog::load();
        $specPackages = new JmhzSpecPackageCatalog();

        self::$externalCodebooks ??= new JmhzExternalCodebookCatalog($specPackages);
        self::$codebooks ??= new JmhzCodebookCatalog($specPackages->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        ));

        return new JmhzScenario1ControlValidator(
            $catalog,
            new JmhzScenario1ControlEvaluator(
                $catalog->parameters(),
                new JmhzDeadlinePolicy(CzechPayrollRulesets2026::provider()),
                self::$externalCodebooks,
                self::$codebooks,
            ),
        );
    }
}
