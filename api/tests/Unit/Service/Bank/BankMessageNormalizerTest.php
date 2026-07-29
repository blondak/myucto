<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank;

use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;
use PHPUnit\Framework\TestCase;

final class BankMessageNormalizerTest extends TestCase
{
    public function testNormalizeKeepDigitsPreservesReferenceNumbers(): void
    {
        self::assertSame('faktura c 2026 0012 acme', BankMessageNormalizer::normalizeKeepDigits('Faktura č. 2026-0012, ACME'));
    }

    public function testNormalizeDoesNotSplitCzechCharactersWithoutIntl(): void
    {
        self::assertSame('bankovni poplatek', BankMessageNormalizer::normalize('Bankovní poplatek'));
        self::assertSame('prijate uroky', BankMessageNormalizer::normalize('Přijaté úroky'));
    }
}
