<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\SocialInsurance;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class SocialInsuranceNoFloatArchitectureTest extends TestCase
{
    public function testSocialInsuranceSliceContainsNoFloatLiteralOrType(): void
    {
        $directory = dirname(__DIR__, 4) . '/src/Service/Payroll/SocialInsurance';
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
                    $violations[] = $file->getFilename() . ':' . $token[2] . ':' . $token[1];
                }
            }
            if (preg_match('/\bfloat\b/i', $source, $match, PREG_OFFSET_CAPTURE) === 1) {
                $violations[] = $file->getFilename() . ':type:' . $match[0][0];
            }
        }

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function testCalculatorDoesNotContainStatutoryRateOrThresholdLiterals(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4) . '/src/Service/Payroll/SocialInsurance/SocialInsuranceMonthCalculator.php',
        );
        self::assertIsString($source);

        foreach (['0.071', '0.248', '0.05', '1200000', '450000', '235041600'] as $literal) {
            self::assertStringNotContainsString($literal, str_replace('_', '', $source));
        }
    }
}
