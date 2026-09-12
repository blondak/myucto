<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Geo;

use MyInvoice\Service\Geo\CountryNameMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Párování státu z cizího systému na zemi nad číselníkem, jak ho staví migrace
 * (seed 0001 + úplný ISO číselník 1828). Bez migrace 1828 v číselníku chybí většina
 * zemí z příkladů a test padá.
 */
final class CountryNameMatcherTest extends TestCase
{
    private static ?CountryNameMatcher $matcher = null;

    /** @return iterable<string,array{string,?string}> */
    public static function names(): iterable
    {
        yield 'anglicky' => ['Russia', 'RU'];
        yield 'přídavné jméno' => ['Russian', 'RU'];
        yield 'česky' => ['Rusko', 'RU'];
        yield 'dlouhý tvar federace' => ['Ruská federace', 'RU'];
        yield 'verzálky' => ['MALAYSIA', 'MY'];
        yield 'Tchaj-wan anglicky' => ['TAIWAN', 'TW'];
        yield 'Tchaj-wan česky' => ['Tchaj-wan', 'TW'];
        yield 'Jižní Afrika' => ['South Africa', 'ZA'];
        yield 'ořezaný název' => ['Jihoafrická republik', 'ZA'];
        yield 'Thajsko' => ['THAILAND', 'TH'];
        yield 'Vietnam' => ['Vietnam', 'VN'];
        yield 'Slovenská republika' => ['Slovenská republika', 'SK'];
        yield 'Slovak Republic' => ['Slovak Republic', 'SK'];
        yield 'Polská republika' => ['Polská republika', 'PL'];
        yield 'Hong Kong' => ['Hong Kong', 'HK'];
        yield 'China' => ['China', 'CN'];
        yield 'Čína' => ['Čína', 'CN'];
        yield 'Cina bez diakritiky' => ['Cina', 'CN'];
        yield 'PRC' => ['PRC', 'CN'];
        yield 'Srbsko verzálky' => ['SRBSKO', 'RS'];
        yield 'Srbsko' => ['Srbsko', 'RS'];
        yield 'Republic of X' => ['Republic of Serbia', 'RS'];
        yield 'slepený dvojjazyčný' => ['NetherlandNizozemsko', 'NL'];
        yield 'dvojjazyčný s lomítkem' => ['Germany / Německo', 'DE'];
        yield 'Holland' => ['Holland', 'NL'];
        yield 'Italy' => ['Italy', 'IT'];
        yield 'Austria' => ['Austria', 'AT'];
        yield 'iso2 malými' => ['De', 'DE'];
        yield 'iso3' => ['DEU', 'DE'];
        yield 'USA' => ['USA', 'US'];
        yield 'UK' => ['UK', 'GB'];
        yield 'England' => ['England', 'GB'];
        yield 'Great Britain' => ['Great Britain', 'GB'];
        yield 'Česká republika' => ['Česká republika', 'CZ'];
        yield 'Czech Republic' => ['Czech Republic', 'CZ'];
        yield 'Czechia' => ['Czechia', 'CZ'];
        yield 'Česko' => ['Česko', 'CZ'];
        yield 'ČR' => ['ČR', 'CZ'];
        yield 'Spolková republika Německo' => ['Spolková republika Německo', 'DE'];
        yield 'Federal Republic of Germany' => ['Federal Republic of Germany', 'DE'];
        yield 'The Netherlands' => ['The Netherlands', 'NL'];
        yield 'ořezaný anglický' => ['Netherland', 'NL'];
        yield 'Türkiye' => ['Turkey', 'TR'];
        yield 'mezery a tečky' => ['  U.S.A.  ', 'US'];
        yield 'Rumunsko neplete Omán' => ['Romania', 'RO'];

        yield 'CR = ČR i Kostarika' => ['CR', null];
        yield 'SR = Slovensko i Surinam' => ['SR', null];
        yield 'krátký nejednoznačný prefix' => ['Guin', null];
        yield 'nejednoznačný prefix' => ['Kongo', null];
        yield 'neznámé' => ['Atlantida', null];
        yield 'číslice' => ['850101/1234', null];
        yield 'prázdné' => ['', null];
        yield 'jen interpunkce' => [' - ', null];
    }

    #[DataProvider('names')]
    public function testMatchesCountryName(string $input, ?string $expected): void
    {
        self::assertSame($expected, self::matcher()->match($input), "Vstup „{$input}“");
    }

    public function testEveryCodebookRowMatchesItsOwnNamesAndCodes(): void
    {
        $matcher = self::matcher();
        foreach (self::codebook() as $row) {
            self::assertSame($row['iso2'], $matcher->match($row['iso3']), $row['iso3']);
            self::assertSame($row['iso2'], $matcher->match($row['name_cs']), $row['name_cs']);
            self::assertSame($row['iso2'], $matcher->match($row['name_en']), $row['name_en']);
        }
    }

    public function testWorksWithCustomNamesFromTheTable(): void
    {
        $matcher = new CountryNameMatcher([
            ['id' => 7, 'iso2' => 'cz', 'iso3' => 'CZE', 'name_cs' => 'Česká republika', 'name_en' => 'Czech Republic'],
            ['id' => 41, 'iso2' => 'TW', 'iso3' => '', 'name_cs' => 'Tchajwan (vlastní)', 'name_en' => ''],
        ]);

        self::assertSame('TW', $matcher->match('Tchajwan (vlastní)'));
        self::assertSame('TW', $matcher->match('Taiwan'), 'Alias platí i nad vlastním řádkem.');
        self::assertSame(41, $matcher->idOf('tw'));
        self::assertSame(7, $matcher->idOf('CZ'));
        self::assertNull($matcher->match('Russia'), 'Země, která v tabulce není, se nepřiřadí ani přes alias.');
        self::assertNull($matcher->idOf('RU'));
    }

    public function testFoldRemovesDiacriticsCaseAndPunctuation(): void
    {
        self::assertSame('pobrezi slonoviny', CountryNameMatcher::fold('Pobřeží  slonoviny'));
        self::assertSame('cote divoire', CountryNameMatcher::fold('Côte d’Ivoire'));
        self::assertSame('bosnia and herzegovina', CountryNameMatcher::fold('Bosnia & Herzegovina'));
    }

    private static function matcher(): CountryNameMatcher
    {
        return self::$matcher ??= new CountryNameMatcher(self::codebook());
    }

    /**
     * Číselník tak, jak ho zakládají migrace: seed 0001, pak 1828 (co už je, se přeskakuje).
     *
     * @return list<array{iso2:string,iso3:string,name_cs:string,name_en:string}>
     */
    private static function codebook(): array
    {
        $dir = dirname(__DIR__, 4) . '/db/migrations/';
        $rows = [];
        foreach (['0001_init.sql', '1828_countries_iso3166_full.sql'] as $file) {
            $sql = is_file($dir . $file) ? (string) file_get_contents($dir . $file) : '';
            preg_match_all("/\\('([A-Z]{2})','([A-Z]{3})','((?:[^']|'')*)','((?:[^']|'')*)',[01]\\)/u", $sql, $m, PREG_SET_ORDER);
            foreach ($m as $t) {
                $rows[$t[1]] ??= [
                    'iso2' => $t[1],
                    'iso3' => $t[2],
                    'name_cs' => str_replace("''", "'", $t[3]),
                    'name_en' => str_replace("''", "'", $t[4]),
                ];
            }
        }
        return array_values($rows);
    }
}
