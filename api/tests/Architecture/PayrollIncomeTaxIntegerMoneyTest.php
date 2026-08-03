<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PayrollIncomeTaxIntegerMoneyTest extends TestCase
{
    public function testIncomeTaxDomainContainsNoFloatingPointLiterals(): void
    {
        $directory = dirname(__DIR__, 2) . '/src/Service/Payroll/IncomeTax';
        $violations = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_DNUMBER) {
                    $violations[] = $file->getFilename() . ':' . $token[2];
                }
            }
        }

        self::assertSame([], $violations, 'Floating point literals found: ' . implode(', ', $violations));
    }
}
