<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validace a kanonizace pravidel IP allowlistu API tokenů.
 *
 * Podstata testu není „zavolá se preg_match": pravidlo, které projde do DB, ale
 * `IpMatcher` ho nikdy nenamatchuje, je horší než odmítnuté — uživatel si myslí,
 * že si přístup povolil, a token přitom nefunguje (nebo naopak). Proto se každý
 * přijatý zápis rovnou ověřuje i proti `IpMatcher`.
 */
final class ApiTokenIpRuleTest extends TestCase
{
    #[DataProvider('validRules')]
    public function testValidRuleIsNormalized(string $input, string $expected): void
    {
        self::assertSame($expected, ApiTokenService::normalizeRule($input));
    }

    public static function validRules(): array
    {
        return [
            'IPv4 adresa'            => ['192.168.1.10', '192.168.1.10'],
            'IPv4 s mezerami'        => ['  10.0.0.1  ', '10.0.0.1'],
            'IPv4 rozsah'            => ['192.168.1.0/24', '192.168.1.0/24'],
            'IPv4 /32'               => ['203.0.113.7/32', '203.0.113.7/32'],
            'IPv4 /0'                => ['0.0.0.0/0', '0.0.0.0/0'],
            'IPv6 adresa'            => ['2001:db8::1', '2001:db8::1'],
            'IPv6 velkými písmeny'   => ['2001:DB8::1', '2001:db8::1'],
            'IPv6 rozsah'            => ['2001:db8::/32', '2001:db8::/32'],
            'IPv6 /128'              => ['2001:db8::1/128', '2001:db8::1/128'],
            'IPv4-mapped IPv6'       => ['::ffff:1.2.3.4', '1.2.3.4'],
            'localhost IPv6'         => ['::1', '::1'],
        ];
    }

    #[DataProvider('invalidRules')]
    public function testInvalidRuleIsRejected(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApiTokenService::normalizeRule($input);
    }

    public static function invalidRules(): array
    {
        return [
            'prázdné'                  => [''],
            'jen mezery'               => ['   '],
            'není IP'                  => ['nesmysl'],
            'hostname'                 => ['example.com'],
            'oktet mimo rozsah'        => ['300.1.1.1'],
            'neúplná IPv4'             => ['192.168.1'],
            // Nejzrádnější případ: syntakticky to vypadá jako platný CIDR, ale
            // /64 na IPv4 nedává smysl a IpMatcher by pravidlo nikdy nenamatchoval.
            'IPv4 s IPv6 prefixem'     => ['192.168.1.0/64'],
            'IPv4 prefix nad 32'       => ['10.0.0.0/33'],
            'IPv6 prefix nad 128'      => ['2001:db8::/129'],
            'prefix není číslo'        => ['192.168.1.0/abc'],
            'záporný prefix'           => ['192.168.1.0/-1'],
            'prázdný prefix'           => ['192.168.1.0/'],
            'wildcard notace'          => ['192.168.1.*'],
        ];
    }

    /**
     * Kanonický tvar musí zůstat použitelný pro matchování. Kdyby normalizace
     * pravidlo rozbila, allowlist by tiše zamkl i povolenou adresu.
     */
    #[DataProvider('matchCases')]
    public function testNormalizedRuleStillMatchesExpectedAddress(string $rule, string $ip, bool $expected): void
    {
        $normalized = ApiTokenService::normalizeRule($rule);
        self::assertSame($expected, (new IpMatcher())->matches($ip, [$normalized]));
    }

    public static function matchCases(): array
    {
        return [
            'přesná IPv4 sedí'          => ['192.168.1.10', '192.168.1.10', true],
            'přesná IPv4 nesedí'        => ['192.168.1.10', '192.168.1.11', false],
            'IPv4 rozsah uvnitř'        => ['192.168.1.0/24', '192.168.1.200', true],
            'IPv4 rozsah mimo'          => ['192.168.1.0/24', '192.168.2.1', false],
            'IPv4 /8 uvnitř'            => ['10.0.0.0/8', '10.44.1.9', true],
            'IPv4 /8 mimo'              => ['10.0.0.0/8', '127.0.0.1', false],
            'IPv6 rozsah uvnitř'        => ['2001:db8::/32', '2001:db8:1234::5', true],
            'IPv6 rozsah mimo'          => ['2001:db8::/32', '2001:dead::1', false],
            'IPv6 psaný verzálkami'     => ['2001:DB8::/32', '2001:db8::99', true],
            'IPv4-mapped pravidlo'      => ['::ffff:1.2.3.4', '1.2.3.4', true],
            'rodiny se nemíchají'       => ['192.168.1.0/24', '2001:db8::1', false],
        ];
    }
}
