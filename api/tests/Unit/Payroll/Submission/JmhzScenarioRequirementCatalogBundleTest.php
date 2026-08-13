<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioRequirementSourceCatalog;
use PHPUnit\Framework\TestCase;

final class JmhzScenarioRequirementCatalogBundleTest extends TestCase
{
    public function testOfficialScenarioCatalogIsPinnedAndSelfConsistent(): void
    {
        $manifest = JmhzScenarioRequirementSourceCatalog::load()->manifest();
        $counts = $manifest['payload']['counts'];

        self::assertSame(JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256, $manifest['manifest_sha256']);
        self::assertSame(8, $counts['scenarios']);
        self::assertSame(37, $counts['interactions']);
        self::assertSame(48, $counts['matrices']);
        self::assertSame(1181, $counts['requirements']);
        self::assertSame(595, $counts['required_requirements']);
        self::assertSame(350, $counts['optional_requirements']);
        self::assertSame(236, $counts['conditional_requirements']);
        self::assertSame(43, $counts['reconciliation_axes']);
        self::assertSame(41, $counts['derived_axes']);
        self::assertSame(159, $counts['derived_one_cells']);
        self::assertSame(17963, $counts['derived_zero_cells']);
        self::assertSame(0, $counts['derived_blank_cells']);
        self::assertSame(15, $counts['derived_zero_axes']);
    }

    public function testKnownSourceAnomaliesRemainExplicitAndRaw(): void
    {
        $manifest = JmhzScenarioRequirementSourceCatalog::load()->manifest();
        $scenarios = array_column($manifest['payload']['scenarios'], null, 'scenario_key');
        $anomalies = array_column($manifest['payload']['anomalies'], null, 'kind');

        self::assertStringContainsString('15; 16; 15; 16', $scenarios['scenario_1']['selector_raw']);
        self::assertSame('rich_text', $scenarios['scenario_5']['business_description_cell_kind']);
        self::assertSame('Column1', $anomalies['generated_empty_column_header']['raw_details']['raw_value']);
        self::assertSame(
            ' IN37(-)',
            $anomalies['leading_whitespace_header']['raw_details']['master'],
        );
        self::assertSame(
            ['SLOVNÍK!BI49', 'SLOVNÍK!BI50', 'SLOVNÍK!BI51'],
            $anomalies['formula_holes']['source_cells'],
        );
    }

    public function testFormulaEvidenceIsAggregatedWithoutExecutableExpressions(): void
    {
        $manifest = JmhzScenarioRequirementSourceCatalog::load()->manifest();
        $axes = array_column($manifest['payload']['evidence_axes'], null, 'axis_key');

        self::assertSame(442, $axes['t']['dictionary_formula_count']);
        self::assertSame(442, $axes['t']['master_match_count']);
        self::assertSame(0, $axes['t']['master_mismatch_count']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $axes['t']['dictionary_formula_vector_sha256']);
        self::assertArrayNotHasKey('formula_raw', $axes['t']);
        self::assertSame(83, $axes['cy']['one_count']);
        self::assertSame(359, $axes['cy']['zero_count']);
    }

    public function testRequirementSourceCellPointsToTheRequirementMarker(): void
    {
        $manifest = JmhzScenarioRequirementSourceCatalog::load()->manifest();
        $matrices = array_column($manifest['payload']['matrices'], null, 'matrix_key');
        $requirement = $matrices['in03']['requirements'][0];

        self::assertSame('IN03!J2', $requirement['source_cell']);
        self::assertSame('IN03!A2', $requirement['source_cells']['attribute_id']);
    }

    public function testBuilderRegeneratesThePinnedManifestByteForByte(): void
    {
        $root = dirname(__DIR__, 5);
        $resource = $root . '/api/resources/payroll/jmhz/dictionary-1.4.1.6';
        $temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'myucto-jmhz-scenarios-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporary));
        try {
            $command = [
                PHP_BINARY,
                $root . '/tools/JmhzScenarioRequirementPackageBuilder.php',
                $resource . '/datove_scenare_interakce_povinnosti_MH_1.4.0.2.xlsx',
                $temporary,
            ];
            $pipes = [];
            $process = proc_open(
                $command,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
                null,
                ['bypass_shell' => true],
            );
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), "Builder selhal.\n{$stdout}\n{$stderr}");
            self::assertFileEquals(
                $resource . '/scenario-requirement-manifest.json',
                $temporary . '/scenario-requirement-manifest.json',
            );
        } finally {
            foreach (glob($temporary . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($temporary);
        }
    }
}
