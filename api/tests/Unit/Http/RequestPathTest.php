<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Http;

use MyInvoice\Http\RequestPath;
use PHPUnit\Framework\TestCase;

/**
 * Sdílená normalizace cesty pro middleware (R1). Klíčová vlastnost: dekóduje se
 * PRÁVĚ JEDNOU, stejně jako to dělá Slim RouteResolver před dispatchem.
 */
final class RequestPathTest extends TestCase
{
    public function testDecodesPercentEncodingExactlyOnce(): void
    {
        self::assertSame('/api/auth/forgot', RequestPath::normalize('/api/auth/%66orgot'));
        self::assertSame('/api/admin/users', RequestPath::normalize('/api/%61dmin/users'));

        // Druhé dekódování je samo o sobě zranitelnost: z `%252f` by se stalo `/`
        // a cesta by se rozsekala na segmenty, které router nikdy neviděl.
        self::assertSame('/api/foo%2fbar', RequestPath::normalize('/api/foo%252fbar'));
    }

    public function testCollapsesSlashesAndDotSegments(): void
    {
        self::assertSame('/api/invoices', RequestPath::normalize('/api//invoices'));
        self::assertSame('/api/invoices', RequestPath::normalize('/api/./invoices'));
        self::assertSame('/api/invoices', RequestPath::normalize('/api/accounting/../invoices'));
        self::assertSame('/', RequestPath::normalize(''));
        self::assertSame('/', RequestPath::normalize('/'));
    }

    public function testKeepsTrailingSlash(): void
    {
        self::assertSame('/api/invoices/', RequestPath::normalize('/api/invoices/'));
        self::assertSame('/api/invoices/', RequestPath::normalize('/api//invoices//'));
        self::assertSame('/api/invoices', RequestPath::normalize('/api/invoices'));
    }

    public function testStripsNullByte(): void
    {
        self::assertSame('/api/invoices', RequestPath::normalize("/api/invoices%00"));
        self::assertSame('/api/invoices', RequestPath::normalize("/api/invo%00ices"));
    }
}
