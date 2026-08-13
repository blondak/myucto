<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use PHPUnit\Framework\TestCase;

final class JmhzControlCatalogBundleTest extends TestCase
{
    public function testOfficialControlCatalogIsPinnedAndSelfConsistent(): void
    {
        $catalog = JmhzControlSourceCatalog::load();
        $manifest = $catalog->manifest();
        $counts = $manifest['payload']['counts'];

        self::assertSame(JmhzControlSourceCatalog::MANIFEST_SHA256, $manifest['manifest_sha256']);
        self::assertSame(199, $counts['controls']);
        self::assertSame(826, $counts['attribute_refs']);
        self::assertSame(219, $counts['unique_attributes']);
        self::assertSame(1, $counts['symbolic_attribute_refs']);
        self::assertSame(22, $counts['parameters']);
        self::assertSame(30, $counts['parameter_control_refs']);
        self::assertSame(10, $counts['missing_parameter_control_refs']);
        self::assertSame(50, $counts['parameter_values']);
        self::assertSame(177, $counts['blocking_remote_controls']);
        self::assertSame(18, $counts['passable_remote_controls']);
        self::assertSame(4, $counts['unavailable_remote_controls']);
    }

    public function testExcelFormulasAreArchivedButNeverUsedAsDefinitionText(): void
    {
        $controls = JmhzControlSourceCatalog::load()->manifest()['payload']['controls'];
        $byId = array_column($controls, null, 'control_id');

        self::assertStringStartsWith('=', $byId[3]['detail_formula']);
        self::assertStringStartsWith('Sleva na pojistném', $byId[3]['detail_text']);
        self::assertStringNotContainsString('=', substr($byId[3]['detail_text'], 0, 1));
        self::assertNull($byId[1]['detail_formula']);
    }

    public function testParameterSourceAnomaliesRemainExplicit(): void
    {
        $parameters = JmhzControlSourceCatalog::load()->manifest()['payload']['parameters'];
        $byRow = array_column($parameters, null, 'source_row');

        self::assertSame([118, 270], array_column($byRow[7]['control_refs'], 'control_id'));
        self::assertSame([168, 270], array_column($byRow[8]['control_refs'], 'control_id'));
        self::assertSame('118270', $byRow[7]['control_refs_raw']);
        self::assertSame('118,270', $byRow[7]['control_refs_formatted']);
        self::assertSame(
            'known_excel_number_format_split_118_270',
            $byRow[7]['control_refs_anomaly'],
        );
        self::assertSame('missing', $byRow[11]['control_refs'][0]['resolution']);
        self::assertSame('15', (string) $byRow[20]['values'][0]['canonical_value']);
        self::assertSame('D4', $byRow[4]['values'][1]['source_cell']);
        self::assertSame('s', $byRow[4]['values'][1]['raw_type']);
        self::assertSame("0.298\u{00A0}", $byRow[4]['values'][1]['raw_value']);
        self::assertSame('0.298', $byRow[4]['values'][1]['normalized_value']);
        self::assertSame('n', $byRow[4]['values'][2]['raw_type']);
    }

    public function testExternalSymbolicDependencyIsPreservedSeparately(): void
    {
        $definition = JmhzControlSourceCatalog::load()->definition(291);

        self::assertSame(['OZUSPOJ'], $definition->symbolicAttributeRefs);
        self::assertCount(6, $definition->attributeIds);
    }
}
