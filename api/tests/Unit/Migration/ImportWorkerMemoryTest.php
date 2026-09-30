<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration;

use MyInvoice\Service\Migration\Shared\ImportWorkerMemory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportWorkerMemoryTest extends TestCase
{
    #[DataProvider('limits')]
    public function testTargetRaisesOnlyLowerFiniteLimits(string $current, ?string $expected): void
    {
        self::assertSame($expected, ImportWorkerMemory::target($current));
    }

    /** @return iterable<string,array{string,?string}> */
    public static function limits(): iterable
    {
        yield 'limit webu na sdíleném hostingu' => ['256M', ImportWorkerMemory::LIMIT];
        yield 'limit příkazové řádky' => ['512M', ImportWorkerMemory::LIMIT];
        yield 'malá písmena' => ['512m', ImportWorkerMemory::LIMIT];
        yield 'v bajtech' => ['134217728', ImportWorkerMemory::LIMIT];
        yield 'už stejný' => ['1024M', null];
        yield 'stejný v jiné jednotce' => ['1G', null];
        yield 'vyšší se nesnižuje' => ['8G', null];
        yield 'neomezený zůstává' => ['-1', null];
        yield 'nečitelný se nechává být' => ['', null];
    }
}
