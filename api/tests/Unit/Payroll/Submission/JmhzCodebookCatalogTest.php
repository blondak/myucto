<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookUnavailableException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookValueException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PHPUnit\Framework\TestCase;

final class JmhzCodebookCatalogTest extends TestCase
{
    private JmhzCodebookCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new JmhzCodebookCatalog(
            (new JmhzSpecPackageCatalog())->load(JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY),
        );
    }

    public function testKnownValueIsReturnedWithoutNormalizingItsCode(): void
    {
        $entry = $this->catalog->requireValue('kod_eldp', '1D+');

        self::assertSame('1D+', $entry['item_code']);
    }

    public function testUnknownValueFailsClosedAndComparisonIsCaseSensitive(): void
    {
        $this->expectException(JmhzCodebookValueException::class);
        $this->catalog->requireValue('kod_eldp', '1d+');
    }

    public function testExternalReferenceDoesNotBehaveAsAnEmptyValidCodebook(): void
    {
        $this->expectException(JmhzCodebookUnavailableException::class);
        $this->expectExceptionMessage('externí reference');
        $this->catalog->requireValue('stat', 'CZ');
    }

    public function testUnknownCodebookFailsClosed(): void
    {
        $this->expectException(JmhzCodebookUnavailableException::class);
        $this->catalog->requireValue('neexistuje', '1');
    }

    public function testDirectConstructionRejectsSelfHashedManifestWithInvalidRowHash(): void
    {
        $manifest = (new JmhzSpecPackageCatalog())->load(JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY);
        $manifest['payload']['codebooks'][0]['entries'][0]['label'] = 'Pozměněný popis';
        $manifest['manifest_sha256'] = hash('sha256', CanonicalJson::encode($manifest['payload']));

        $this->expectException(\UnexpectedValueException::class);
        new JmhzCodebookCatalog($manifest);
    }
}
