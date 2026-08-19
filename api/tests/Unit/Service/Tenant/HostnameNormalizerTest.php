<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Tenant;

use MyInvoice\Service\Tenant\HostnameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostnameNormalizerTest extends TestCase
{
    #[DataProvider('validDomains')]
    public function testDomainIsStoredAsCanonicalAscii(string $input, string $expected): void
    {
        self::assertSame($expected, (new HostnameNormalizer())->normalizeDomain($input));
    }

    /** @return iterable<string,array{string,string}> */
    public static function validDomains(): iterable
    {
        yield 'case and trailing dot' => [' Portal.Example.CZ. ', 'portal.example.cz'];
        yield 'punycode' => ['xn--faktura-2za.example', 'xn--faktura-2za.example'];
        if (function_exists('idn_to_ascii')) {
            yield 'unicode idn' => ['Faktura.Česko', 'faktura.xn--esko-fua'];
        }
    }

    #[DataProvider('invalidDomains')]
    public function testUnsafeOrNonDnsDomainIsRejected(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new HostnameNormalizer())->normalizeDomain($input);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidDomains(): iterable
    {
        yield 'scheme' => ['https://portal.example.cz'];
        yield 'port' => ['portal.example.cz:8443'];
        yield 'path' => ['portal.example.cz/login'];
        yield 'wildcard' => ['*.example.cz'];
        yield 'userinfo' => ['user@portal.example.cz'];
        yield 'ipv4' => ['192.0.2.1'];
        yield 'ipv6' => ['2001:db8::1'];
        yield 'localhost' => ['localhost'];
        yield 'invalid label' => ['bad_host.example.cz'];
        yield 'empty label' => ['portal..example.cz'];
    }

    public function testRequestHostAllowsLocalDevelopmentButStillNormalizesIt(): void
    {
        $normalizer = new HostnameNormalizer();
        self::assertSame('localhost', $normalizer->normalizeRequestHost('LOCALHOST.'));
        self::assertSame('127.0.0.1', $normalizer->normalizeRequestHost('127.0.0.1'));
        self::assertSame('2001:db8::1', $normalizer->normalizeRequestHost('[2001:DB8::1]'));
    }
}
