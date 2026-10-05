<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ExecutionTimeIsolationTest extends TestCase
{
    public function testApplicationTimeBudgetDoesNotLeakIntoFollowingTest(): void
    {
        $directory = sys_get_temp_dir() . '/myucto-time-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $api = dirname(__DIR__, 2);
        $fixture = <<<'PHP'
<?php
final class TimeBudgetFixtureTest extends \PHPUnit\Framework\TestCase
{
    public function testImportSetsItsRequestBudget(): void
    {
        set_time_limit(140);
        self::assertSame('140', ini_get('max_execution_time'));
    }

    public function testFollowingTestKeepsTheRunnerBudget(): void
    {
        self::assertSame('0', ini_get('max_execution_time'));
    }
}
PHP;
        $bootstrap = htmlspecialchars($api . '/tests/bootstrap.php', ENT_XML1);
        try {
            file_put_contents($directory . '/TimeBudgetFixtureTest.php', $fixture);
            file_put_contents($directory . '/phpunit.xml', <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="{$bootstrap}" recordTestRunHistory="false">
    <testsuites><testsuite name="TimeBudget"><file>TimeBudgetFixtureTest.php</file></testsuite></testsuites>
    <extensions><bootstrap class="MyInvoice\Tests\Support\SharedTestConnectionGuard"/></extensions>
    <php><ini name="max_execution_time" value="0"/></php>
</phpunit>
XML);
            $process = new Process([
                PHP_BINARY, $api . '/vendor/phpunit/phpunit/phpunit',
                '--configuration=' . $directory . '/phpunit.xml', '--no-progress',
            ], $api);
            $process->setTimeout(30);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            self::assertStringContainsString('OK (2 tests, 2 assertions)', $process->getOutput());
        } finally {
            foreach (['TimeBudgetFixtureTest.php', 'phpunit.xml'] as $file) {
                if (is_file($directory . '/' . $file)) {
                    unlink($directory . '/' . $file);
                }
            }
            rmdir($directory);
        }
    }
}
