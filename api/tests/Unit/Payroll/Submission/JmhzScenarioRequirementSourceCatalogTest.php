<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFieldRequirementKind;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioRequirementSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PHPUnit\Framework\TestCase;

final class JmhzScenarioRequirementSourceCatalogTest extends TestCase
{
    public function testTypedCatalogPreservesIn14AndIn37SourceBoundary(): void
    {
        $catalog = JmhzScenarioRequirementSourceCatalog::load();

        self::assertSame('IN14', $catalog->interaction('IN14')->key);
        self::assertSame([], $catalog->requirementsForMatrix('in37'));
        self::assertSame(83, $catalog->evidenceAxis('cy')->oneCount);
        self::assertSame(
            JmhzFieldRequirementKind::Required,
            $catalog->requirementsForMatrix('scenario_1')[0]->requirement,
        );
    }

    public function testMissingDefinitionFailsClosed(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        JmhzScenarioRequirementSourceCatalog::load()->scenario('unknown');
    }

    public function testFullyRehashedRequirementCannotReplacePinnedCatalog(): void
    {
        $manifest = JmhzScenarioRequirementSourceCatalog::load()->manifest();
        $row = &$manifest['payload']['matrices'][0]['requirements'][0];
        $row['translation_raw'] = 'Pozměněný zdroj';
        unset($row['row_hash']);
        $row['row_hash'] = hash('sha256', CanonicalJson::encode($row));
        $matrix = &$manifest['payload']['matrices'][0];
        $matrix['matrix_hash'] = hash('sha256', CanonicalJson::encode([
            'requirements' => $matrix['requirements'],
        ]));
        unset($matrix['row_hash']);
        $matrix['row_hash'] = hash('sha256', CanonicalJson::encode($matrix));
        $manifest['manifest_sha256'] = hash('sha256', CanonicalJson::encode($manifest['payload']));

        $this->expectException(\UnexpectedValueException::class);
        new JmhzScenarioRequirementSourceCatalog($manifest, $this->specManifest());
    }

    public function testSelfConsistentDuplicateInteractionAttributeReferenceIsRejected(): void
    {
        $manifest = JmhzScenarioRequirementSourceCatalog::load()->manifest();
        $duplicate = $manifest['payload']['interaction_attribute_refs'][0];
        $duplicate['ordinal'] = 2;
        unset($duplicate['row_hash']);
        $duplicate['row_hash'] = hash('sha256', CanonicalJson::encode($duplicate));
        array_splice($manifest['payload']['interaction_attribute_refs'], 1, 0, [$duplicate]);
        ++$manifest['payload']['counts']['interaction_attribute_refs'];
        $manifest['manifest_sha256'] = hash('sha256', CanonicalJson::encode($manifest['payload']));

        $this->expectException(\UnexpectedValueException::class);
        new JmhzScenarioRequirementSourceCatalog($manifest, $this->specManifest());
    }

    /** @return array{manifest_sha256:string,payload:array<string, mixed>} */
    private function specManifest(): array
    {
        return (new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
        );
    }
}
