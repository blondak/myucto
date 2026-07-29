<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Support\SafeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SEC-10 — `eshop_manufacturers.website` se renderuje do href, takže helper musí
 * propustit jen absolutní http(s) URL.
 *
 * TS zrcadlo: web/src/utils/__tests__/safeUrl.spec.ts — stejné případy.
 */
#[Group('unit')]
final class SafeUrlTest extends TestCase
{
    /** @return array<string,array{0:mixed,1:?string}> */
    public static function validProvider(): array
    {
        return [
            'https projde'                => ['https://example.com', 'https://example.com'],
            'http projde vcetne query'    => ['http://example.com/a?b=C#d', 'http://example.com/a?b=C#d'],
            'schema se snizi, cesta ne'   => ['HTTPS://Example.com/Path', 'https://Example.com/Path'],
            'bez schematu doplni https'   => ['example.com', 'https://example.com'],
            'okrajove mezery se orizmou'  => ['  https://example.com  ', 'https://example.com'],
            'punycode IDN'                => ['https://xn--mjdomna-l1ab.cz', 'https://xn--mjdomna-l1ab.cz'],
            'IDN v UTF-8 zustava'         => ['https://mojedoména.cz', 'https://mojedoména.cz'],
            'port projde'                 => ['https://example.com:8443/x', 'https://example.com:8443/x'],
            'localhost je vyjimka'        => ['http://localhost:5173', 'http://localhost:5173'],
        ];
    }

    #[DataProvider('validProvider')]
    public function testValidUrlsPass(mixed $input, ?string $expected): void
    {
        self::assertSame($expected, SafeUrl::normalizeWebUrl($input));
    }

    /** @return array<string,array{0:mixed}> */
    public static function rejectedProvider(): array
    {
        return [
            // aktivní obsah — jádro nálezu
            'javascript'                  => ['javascript:alert(1)'],
            'javascript mixed-case'       => ['JaVaScRiPt:alert(1)'],
            'javascript s tabulatorem'    => ["java\tscript:alert(1)"],
            'javascript s newline'        => ["java\nscript:alert(1)"],
            'javascript s CR'             => ["java\rscript:alert(1)"],
            'javascript s NUL uprostred'  => ["java\x00script:alert(1)"],
            'vedouci NUL'                 => ["\x00javascript:alert(1)"],
            'data URI'                    => ['data:text/html,<script>alert(1)</script>'],
            'data URI base64'             => ['data:text/html;base64,PHNjcmlwdD4='],
            'vbscript'                    => ['vbscript:msgbox(1)'],
            'file'                        => ['file:///c:/windows/win.ini'],
            // URL-encoded / entity varianty schématu se nesmí "uzdravit"
            'procentem kodovane schema'   => ['%6aavascript:alert(1)'],
            'HTML entita ve schematu'     => ['&#106;avascript:alert(1)'],
            // relativní a protokolově-relativní
            'protokolove relativni'       => ['//evil.com'],
            'relativni cesta'             => ['/relative'],
            'relativni bez lomitka'       => ['javascript'],
            // userinfo mate o cílové doméně
            'userinfo s heslem'           => ['https://user:pass@evil.com'],
            'userinfo bez hesla'          => ['https://duveryhodna.cz@evil.com'],
            'zpetne lomitko v autorite'   => ["https://evil.com\\@duveryhodna.cz"],
            // degenerované vstupy
            'prazdny host'                => ['https://'],
            'prazdny retezec'             => [''],
            'jen mezery'                  => ['   '],
            'null'                        => [null],
            'pole misto retezce'          => [['https://example.com']],
            'mezera uvnitr'               => ['https://example.com/a b'],
            'delsi nez sloupec'           => ['https://aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.com'],
        ];
    }

    #[DataProvider('rejectedProvider')]
    public function testDangerousUrlsAreRejected(mixed $input): void
    {
        self::assertNull(SafeUrl::normalizeWebUrl($input));
    }

    public function testNormalizedValueFitsColumn(): void
    {
        // Doplnění "https://" nesmí přetéct VARCHAR(255) (db/migrations/1028_eshop.sql:60)
        $host = str_repeat('a', 248) . '.com'; // 252 znaků; + "https://" = 260
        self::assertNull(SafeUrl::normalizeWebUrl($host));
        self::assertLessThanOrEqual(SafeUrl::MAX_LENGTH, strlen((string) SafeUrl::normalizeWebUrl('example.com')));
    }
}
