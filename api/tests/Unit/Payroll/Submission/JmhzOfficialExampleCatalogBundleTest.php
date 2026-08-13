<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOfficialExampleClassification;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOfficialExampleSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOfficialExampleValidationResult;
use PHPUnit\Framework\TestCase;

final class JmhzOfficialExampleCatalogBundleTest extends TestCase
{
    public function testOfficialExampleEvidenceIsPinnedAndFailClosed(): void
    {
        $catalog = JmhzOfficialExampleSourceCatalog::load();
        $manifest = $catalog->manifest();
        $counts = $manifest['payload']['counts'];

        self::assertSame(JmhzOfficialExampleSourceCatalog::MANIFEST_SHA256, $manifest['manifest_sha256']);
        self::assertSame(JmhzOfficialExampleSourceCatalog::ARCHIVE_SHA256, $manifest['payload']['source_archive']['sha256']);
        self::assertSame(35, $counts['xml_examples']);
        self::assertSame(35, $counts['well_formed']);
        self::assertSame(17, $counts['xsd_pass']);
        self::assertSame(18, $counts['xsd_fail']);
        self::assertSame(17, $counts['valid_against_pinned_xsd']);
        self::assertSame(18, $counts['unresolved']);
        self::assertSame(0, $counts['different_version']);
        self::assertSame(0, $counts['fragment']);
        self::assertSame(0, $counts['intentionally_invalid']);
        self::assertCount(21, $catalog->examplesForAgenda('jmhz'));
        self::assertCount(11, $catalog->examplesForAgenda('regzec'));

        foreach ($catalog->examples() as $example) {
            self::assertFalse($example->isFixtureEligible());
            if ($example->classification === JmhzOfficialExampleClassification::ValidAgainstPinnedXsd) {
                self::assertSame(JmhzOfficialExampleValidationResult::Pass, $example->validationResult);
                self::assertSame([], $example->blockingReasons);
            } else {
                self::assertSame(JmhzOfficialExampleClassification::Unresolved, $example->classification);
                self::assertSame(JmhzOfficialExampleValidationResult::Fail, $example->validationResult);
                self::assertNotSame([], $example->blockingReasons);
            }
        }
    }

    public function testClassificationsCoverEveryXmlEntryExactlyOnce(): void
    {
        $root = dirname(__DIR__, 5);
        $directory = $root . '/api/resources/payroll/jmhz/examples-2026-04-13';
        $manifest = json_decode(
            (string) file_get_contents($directory . '/manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $decisions = json_decode(
            (string) file_get_contents($directory . '/classification-decisions.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        self::assertIsArray($decisions);

        $examples = $manifest['payload']['examples'];
        $xmlEntries = array_values(array_filter(
            $manifest['payload']['archive_entries'],
            static fn (array $entry): bool => $entry['entry_kind'] === 'xml',
        ));
        self::assertCount(35, $examples);
        self::assertCount(35, $xmlEntries);
        self::assertSame(
            array_column($xmlEntries, 'sha256'),
            array_column($examples, 'sha256'),
        );
        $decisionHashes = array_column($decisions['decisions'], 'sha256');
        sort($decisionHashes, SORT_STRING);
        $exampleHashes = array_column($examples, 'sha256');
        sort($exampleHashes, SORT_STRING);
        self::assertSame($exampleHashes, $decisionHashes);
    }

    public function testPublicBundleDoesNotRedistributeOfficialXmlBytes(): void
    {
        $directory = dirname(__DIR__, 5) . '/api/resources/payroll/jmhz/examples-2026-04-13';
        $files = array_map('basename', glob($directory . '/*') ?: []);
        sort($files, SORT_STRING);

        self::assertSame(['classification-decisions.json', 'manifest.json'], $files);
        foreach ($files as $file) {
            $content = (string) file_get_contents($directory . '/' . $file);
            self::assertStringNotContainsString('<?xml', $content);
            self::assertStringNotContainsString('<jmhz', $content);
            self::assertStringNotContainsString('<REGZEC', $content);
        }
    }

    public function testCatalogHasNoFixtureOrExecutableByteApi(): void
    {
        $reflection = new \ReflectionClass(JmhzOfficialExampleSourceCatalog::class);
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => strtolower($method->getName()),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        foreach (['bytes', 'openxml', 'fixture', 'build', 'resolve', 'validatesubmission'] as $forbidden) {
            self::assertNotContains($forbidden, $methods);
        }
    }
}
