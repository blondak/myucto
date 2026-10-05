<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\PhpSourceRegions;
use PHPUnit\Framework\TestCase;

final class PhpSourceRegionsTest extends TestCase
{
    public function testMissingSymbolsKeepsEmptyAndNamedSelections(): void
    {
        $source = <<<'PHP'
            <?php
            final class Example
            {
                private const LIMIT = 8;
                public function existing(): void {}
            }
            PHP;

        self::assertSame([], PhpSourceRegions::missingSymbols($source, []));
        self::assertSame([], PhpSourceRegions::missingSymbols($source, ['LIMIT', 'existing']));
        self::assertSame(['missing'], PhpSourceRegions::missingSymbols($source, ['existing', 'missing']));
    }
}
