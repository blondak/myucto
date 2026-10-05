<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPackageSplitter;
use PHPUnit\Framework\TestCase;

final class JmhzPackageSplitterTest extends TestCase
{
    public function testRealBoundaryKeeps1500FormsTogetherAndSplits1501(): void
    {
        $splitter = new JmhzPackageSplitter();
        $atLimit = range(1, 1_500);
        $overLimit = range(1, 1_501);

        self::assertFalse($splitter->requiresSplit(count($atLimit)));
        self::assertSame([$atLimit], $splitter->split($atLimit));
        self::assertTrue($splitter->requiresSplit(count($overLimit)));

        $packages = $splitter->split($overLimit);
        self::assertCount(2, $packages);
        self::assertCount(1_500, $packages[0]);
        self::assertSame(1_500, $packages[0][1_499]);
        self::assertSame([1_501], $packages[1]);
    }
}
