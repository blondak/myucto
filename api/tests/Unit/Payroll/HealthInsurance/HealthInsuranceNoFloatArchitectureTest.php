<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\HealthInsurance;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class HealthInsuranceNoFloatArchitectureTest extends TestCase
{
    public function testHealthInsuranceDomainDoesNotUseFloatingPointArithmetic(): void
    {
        $directory = dirname(__DIR__, 4) . '/src/Service/Payroll/HealthInsurance';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $tokens = token_get_all((string) file_get_contents($file->getPathname()));
            foreach ($tokens as $token) {
                if (is_array($token) && $token[0] === T_DNUMBER) {
                    self::fail("Floating-point literal found in {$file->getFilename()}:{$token[2]}");
                }
            }
        }

        self::addToAssertionCount(1);
    }
}
