<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlPassability;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlScope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSystem;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PHPUnit\Framework\TestCase;

final class JmhzControlSourceCatalogTest extends TestCase
{
    public function testDefinitionPreservesSparseIdAndIndependentPassability(): void
    {
        $definition = JmhzControlSourceCatalog::load()->definition(74);

        self::assertSame(74, $definition->id->value);
        self::assertSame(20074, $definition->id->disErrorCode());
        self::assertSame(40074, $definition->id->cjmhzErrorCode());
        self::assertSame(JmhzControlScope::EmployeeForm, $definition->scope);
        self::assertSame(JmhzControlSystem::Eportal, $definition->portalSystem);
        self::assertSame(JmhzControlPassability::Blocking, $definition->portalPassability);
        self::assertSame(JmhzControlPassability::Passable, $definition->remotePassability);
    }

    public function testMissingControlFailsClosed(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        JmhzControlSourceCatalog::load()->definition(2);
    }

    public function testSelfHashedTamperingIsRejectedByRowHash(): void
    {
        $catalog = JmhzControlSourceCatalog::load()->manifest();
        $catalog['payload']['controls'][0]['error_message'] = 'Pozměněná zpráva';
        $catalog['manifest_sha256'] = hash('sha256', CanonicalJson::encode($catalog['payload']));
        $spec = (new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        );

        $this->expectException(\UnexpectedValueException::class);
        new JmhzControlSourceCatalog($catalog, $spec);
    }

    public function testSelfConsistentDuplicateParameterControlReferenceIsRejected(): void
    {
        $catalog = JmhzControlSourceCatalog::load()->manifest();
        $parameter = &$catalog['payload']['parameters'][3];
        $duplicate = $parameter['control_refs'][0];
        $duplicate['ordinal'] = count($parameter['control_refs']) + 1;
        unset($duplicate['row_hash']);
        $duplicate['row_hash'] = hash('sha256', CanonicalJson::encode($duplicate));
        $parameter['control_refs'][] = $duplicate;
        unset($parameter['row_hash']);
        $parameter['row_hash'] = hash('sha256', CanonicalJson::encode($parameter));
        ++$catalog['payload']['counts']['parameter_control_refs'];
        $catalog['manifest_sha256'] = hash('sha256', CanonicalJson::encode($catalog['payload']));
        $spec = (new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        );

        $this->expectException(\UnexpectedValueException::class);
        new JmhzControlSourceCatalog($catalog, $spec);
    }

    public function testSelfHashedForeignSourceIsRejectedAgainstParentSpecPackage(): void
    {
        $catalog = JmhzControlSourceCatalog::load()->manifest();
        $catalog['payload']['version'] = '9.9';
        $catalog['payload']['source']['filename'] = 'foreign.xlsx';
        $catalog['payload']['source']['sha256'] = str_repeat('a', 64);
        $catalog['manifest_sha256'] = hash('sha256', CanonicalJson::encode($catalog['payload']));
        $spec = (new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        );

        $this->expectException(\UnexpectedValueException::class);
        new JmhzControlSourceCatalog($catalog, $spec);
    }

    public function testFullyRehashedForeignDefinitionCannotReplacePinnedCatalog(): void
    {
        $catalog = JmhzControlSourceCatalog::load()->manifest();
        $control = &$catalog['payload']['controls'][0];
        $control['error_message'] = 'Pozměněná zpráva s platným řádkovým hashem';
        unset($control['row_hash']);
        $control['row_hash'] = hash('sha256', CanonicalJson::encode($control));
        $catalog['manifest_sha256'] = hash('sha256', CanonicalJson::encode($catalog['payload']));
        $spec = (new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        );

        $this->expectException(\UnexpectedValueException::class);
        new JmhzControlSourceCatalog($catalog, $spec);
    }
}
