<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PayrollBoundedContextTest extends TestCase
{
    public function testPayrollServicesDoNotDependOnHttpActions(): void
    {
        foreach ($this->serviceFiles() as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString(
                'MyInvoice\\Action\\',
                $source,
                $file . ' nesmí záviset na HTTP Action vrstvě.',
            );
        }
    }

    public function testNewPayrollServicesDoNotDeclareFloatInputs(): void
    {
        foreach ($this->serviceFiles() as $file) {
            $source = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression(
                '/(?:^|[\s,(])(?:\?|)float\s+\$/m',
                $source,
                $file . ' nesmí přijímat zákonné peněžní vstupy jako float.',
            );
        }
    }

    /** @return list<string> */
    private function serviceFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/src/Service/Payroll';
        $files = glob($root . '/*.php') ?: [];
        self::assertNotEmpty($files);
        sort($files);
        return $files;
    }
}
