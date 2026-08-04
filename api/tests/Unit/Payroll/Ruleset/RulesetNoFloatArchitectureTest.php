<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Ruleset;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class RulesetNoFloatArchitectureTest extends TestCase
{
    public function testRulesetSourceContainsNoFloatingPointLiteralOrType(): void
    {
        $directory = dirname(__DIR__, 4) . '/src/Service/Payroll/Ruleset';
        $violations = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_DNUMBER) {
                    $violations[] = $file->getFilename() . ':' . $token[2] . ' literal ' . $token[1];
                }
            }
            if (preg_match('/\bfloat\b/i', $source, $match, PREG_OFFSET_CAPTURE) === 1) {
                $violations[] = $file->getFilename() . ' type ' . $match[0][0];
            }
        }

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function testRulesetRegistryDoesNotReadLegacyTaxConstants(): void
    {
        $directory = dirname(__DIR__, 4) . '/src/Service/Payroll/Ruleset';
        $violations = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            if (
                str_contains($source, 'Service\\Tax\\TaxConstants')
                || str_contains($source, 'TaxConstants::')
            ) {
                $violations[] = $file->getFilename();
            }
        }

        self::assertSame([], $violations, 'New payroll rulesets must not use legacy TaxConstants.');
    }
}
