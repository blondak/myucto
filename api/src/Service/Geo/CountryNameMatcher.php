<?php

declare(strict_types=1);

namespace MyInvoice\Service\Geo;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Stát zapsaný volným textem v cizím systému (adresář Money S3 a další převody) → ISO
 * kód země z číselníku `countries`.
 *
 * Jediné místo, kde se název státu z cizích dat páruje na zemi. Vychází z toho, co je
 * v tabulce skutečně (i ze zemí přidaných ručně a z vlastních názvů), a přidává běžné
 * varianty, zkratky, přídavná jména a oficiální dlouhé tvary. Nejednoznačný vstup vrací
 * null: špatně přiřazená země je horší než upozornění v protokolu, protože u partnera
 * rozhoduje o režimu DPH a o souhrnném hlášení.
 *
 * Postup (první krok, který něco najde, rozhoduje; víc zemí najednou = null):
 *   1. přesná shoda bez diakritiky a velikosti písmen s názvem, aliasem, iso2 nebo iso3,
 *   2. dlouhý tvar bez obecných slov („Slovenská republika", „Republic of Serbia"),
 *      u českého přídavného jména i tvar země („slovenská" → „Slovensko"),
 *   3. ořezaný název: vstup od 6 znaků je začátkem právě jednoho názvu,
 *   4. slepený text: obsahuje názvy právě jedné země („NetherlandNizozemsko").
 */
final class CountryNameMatcher
{
    private const MIN_PREFIX = 6;
    private const MIN_EMBEDDED = 5;

    /** Slova oficiálních dlouhých tvarů, která sama zemi neurčují. */
    private const GENERIC_WORDS = [
        'republika', 'republic', 'rep', 'the', 'of', 'federace', 'federation', 'federal',
        'federalni', 'spolkova', 'kralovstvi', 'kingdom', 'lidova', 'peoples', 'democratic',
        'demokraticka', 'socialist', 'socialisticka', 'islamic', 'islamska', 'konfederace',
        'confederation', 'knizectvi', 'principality', 'velkovevodstvi', 'grand', 'duchy',
        'commonwealth', 'state', 'stat',
    ];

    /**
     * Běžné varianty, zkratky a přídavná jména (bez diakritiky, malými písmeny) => ISO.
     * „cr" a „sr" jsou zároveň kódy Kostariky a Surinamu, takže jako holé zkratky vyjdou
     * nejednoznačně; s diakritikou („ČR") je Česko jisté, viz {@see self::RAW_ALIASES}.
     */
    private const ALIASES = [
        'cesko' => 'CZ', 'ceska' => 'CZ', 'czech' => 'CZ', 'czechia' => 'CZ', 'czech republic' => 'CZ', 'cr' => 'CZ',
        'slovensko' => 'SK', 'slovak' => 'SK', 'slovakia' => 'SK', 'sr' => 'SK',
        'uk' => 'GB', 'england' => 'GB', 'anglie' => 'GB', 'great britain' => 'GB', 'britain' => 'GB',
        'velka britanie' => 'GB', 'united kingdom' => 'GB', 'scotland' => 'GB', 'skotsko' => 'GB',
        'wales' => 'GB', 'british' => 'GB',
        'usa' => 'US', 'u s a' => 'US', 'united states' => 'US', 'united states of america' => 'US',
        'america' => 'US', 'amerika' => 'US', 'spojene staty' => 'US', 'spojene staty americke' => 'US',
        'holland' => 'NL', 'holandsko' => 'NL', 'dutch' => 'NL', 'nederland' => 'NL', 'netherlands' => 'NL',
        'german' => 'DE', 'deutschland' => 'DE', 'brd' => 'DE', 'nemecko' => 'DE',
        'austrian' => 'AT', 'osterreich' => 'AT',
        'swiss' => 'CH', 'schweiz' => 'CH', 'suisse' => 'CH', 'svizzera' => 'CH',
        'russian' => 'RU', 'russia' => 'RU', 'rusko' => 'RU', 'ruska' => 'RU', 'rossija' => 'RU', 'rossiya' => 'RU',
        'china' => 'CN', 'chinese' => 'CN', 'prc' => 'CN', 'cina' => 'CN', 'cinska' => 'CN',
        'hong kong' => 'HK', 'hongkong' => 'HK',
        'taiwan' => 'TW', 'tchaj wan' => 'TW', 'tchajwan' => 'TW',
        'macau' => 'MO', 'macao' => 'MO',
        'south korea' => 'KR', 'korea south' => 'KR', 'korejska' => 'KR', 'jizni korea' => 'KR',
        'north korea' => 'KP', 'korea north' => 'KP', 'kldr' => 'KP', 'severni korea' => 'KP',
        'viet nam' => 'VN', 'vietnam' => 'VN',
        'polish' => 'PL', 'polska' => 'PL',
        'hungarian' => 'HU', 'magyarorszag' => 'HU',
        'french' => 'FR', 'francouzska' => 'FR',
        'italian' => 'IT', 'italia' => 'IT', 'italska' => 'IT',
        'spanish' => 'ES', 'espana' => 'ES',
        'irish' => 'IE', 'eire' => 'IE',
        'belgian' => 'BE', 'belgique' => 'BE', 'belgicka' => 'BE',
        'luxembourgish' => 'LU',
        'slovenian' => 'SI', 'slovenija' => 'SI',
        'croatian' => 'HR', 'hrvatska' => 'HR',
        'serbian' => 'RS', 'srbija' => 'RS',
        'ukrainian' => 'UA', 'ukrajina' => 'UA',
        'belarus' => 'BY', 'belorusko' => 'BY', 'bielorusko' => 'BY',
        'macedonia' => 'MK', 'makedonie' => 'MK',
        'bosnia' => 'BA',
        'moldova' => 'MD', 'moldavsko' => 'MD',
        'kyperska' => 'CY', 'litevska' => 'LT', 'maltska' => 'MT',
        'swedish' => 'SE', 'sverige' => 'SE',
        'danish' => 'DK', 'danmark' => 'DK',
        'norwegian' => 'NO', 'norge' => 'NO',
        'finnish' => 'FI', 'suomi' => 'FI',
        'greek' => 'GR', 'hellas' => 'GR', 'el' => 'GR',
        'romanian' => 'RO', 'bulgarian' => 'BG', 'estonian' => 'EE', 'latvian' => 'LV',
        'lithuanian' => 'LT', 'portuguese' => 'PT',
        'turkey' => 'TR', 'turkiye' => 'TR', 'turkish' => 'TR',
        'uae' => 'AE', 'emirates' => 'AE', 'emiraty' => 'AE',
        'jar' => 'ZA', 'south africa' => 'ZA',
        'japanese' => 'JP', 'indian' => 'IN', 'israeli' => 'IL', 'canadian' => 'CA',
        'australian' => 'AU', 'mexican' => 'MX', 'brazilian' => 'BR', 'malaysian' => 'MY',
        'thai' => 'TH', 'vietnamese' => 'VN', 'taiwanese' => 'TW',
        'ivory coast' => 'CI', 'cote divoire' => 'CI',
        'burma' => 'MM', 'barma' => 'MM',
        'vatican' => 'VA',
        'swaziland' => 'SZ', 'svazijsko' => 'SZ',
        'czech rep' => 'CZ', 'slovak rep' => 'SK',
    ];

    /** Zkratky, u kterých o zemi rozhoduje diakritika (ČR ≠ CR Kostarika). */
    private const RAW_ALIASES = ['čr' => 'CZ'];

    /** Malá písmena s diakritikou => ASCII. Záměrně bez intl, ať párování vyjde všude stejně. */
    private const DIACRITICS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ą' => 'a', 'ă' => 'a',
        'č' => 'c', 'ć' => 'c', 'ç' => 'c', 'ď' => 'd', 'đ' => 'd',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ě' => 'e', 'ę' => 'e', 'ē' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'ı' => 'i',
        'ľ' => 'l', 'ĺ' => 'l', 'ł' => 'l', 'ň' => 'n', 'ń' => 'n', 'ñ' => 'n',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ő' => 'o', 'ø' => 'o', 'ō' => 'o',
        'ř' => 'r', 'ŕ' => 'r', 'š' => 's', 'ś' => 's', 'ş' => 's', 'ș' => 's',
        'ť' => 't', 'ţ' => 't', 'ț' => 't',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ů' => 'u', 'ű' => 'u', 'ū' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
        'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'þ' => 'th', 'ð' => 'd',
        '&' => ' and ', '’' => '', "'" => '', '`' => '',
    ];

    /** @var array<string,array<string,true>> přesný klíč => ISO kódy */
    private array $exact = [];

    /** @var array<string,string> iso2 malými => ISO */
    private array $codes2 = [];

    /** @var array<string,string> iso3 malými => ISO */
    private array $codes3 = [];

    /** @var list<array{string,string}> [klíč názvu, ISO] pro ořezané a slepené tvary */
    private array $names = [];

    /** @var array<string,int> ISO => countries.id */
    private array $ids = [];

    /** @var array<string,?string> */
    private array $memo = [];

    /**
     * @param iterable<array{id?:int|string|null,iso2:string,iso3?:string|null,name_cs?:string|null,name_en?:string|null}> $countries
     *        řádky číselníku `countries`
     */
    public function __construct(iterable $countries)
    {
        foreach ($countries as $row) {
            $iso = strtoupper(trim((string) $row['iso2']));
            if (preg_match('/^[A-Z]{2}$/', $iso) !== 1) {
                continue;
            }
            if (isset($row['id']) && $row['id'] !== null && $row['id'] !== '') {
                $this->ids[$iso] ??= (int) $row['id'];
            }
            $this->codes2[strtolower($iso)] = $iso;
            $iso3 = strtolower(trim((string) ($row['iso3'] ?? '')));
            if (preg_match('/^[a-z]{3}$/', $iso3) === 1) {
                $this->codes3[$iso3] = $iso;
            }
            foreach ([$row['name_cs'] ?? '', $row['name_en'] ?? ''] as $name) {
                $this->addName(self::fold((string) $name), $iso);
            }
        }
        foreach (self::ALIASES as $alias => $iso) {
            if (isset($this->codes2[strtolower($iso)])) {
                $this->addName($alias, $iso);
            }
        }
    }

    public static function fromDatabase(Connection $db): self
    {
        return new self($db->pdo()->query('SELECT id, iso2, iso3, name_cs, name_en FROM countries ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC));
    }

    /** ISO kód (iso2) země podle volného textu; neznámý, nejednoznačný nebo nezemský údaj → null. */
    public function match(string $text): ?string
    {
        $text = trim($text);
        if (array_key_exists($text, $this->memo)) {
            return $this->memo[$text];
        }
        return $this->memo[$text] = $this->resolve($text);
    }

    /** countries.id pro ISO kód; jen u matcheru z řádků s id (typicky {@see self::fromDatabase()}). */
    public function idOf(string $iso2): ?int
    {
        return $this->ids[strtoupper(trim($iso2))] ?? null;
    }

    /** Text bez diakritiky, malými písmeny, jen písmena a číslice oddělené jednou mezerou. */
    public static function fold(string $text): string
    {
        $s = strtr(mb_strtolower(trim($text), 'UTF-8'), self::DIACRITICS);
        $s = (string) preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim($s);
    }

    private function resolve(string $text): ?string
    {
        // Číslice v poli státu znamenají jiný údaj (rodné číslo, PSČ), ne zemi.
        if ($text === '' || preg_match('/\d/', $text) === 1) {
            return null;
        }
        $raw = mb_strtolower($text, 'UTF-8');
        if (isset(self::RAW_ALIASES[$raw], $this->codes2[strtolower(self::RAW_ALIASES[$raw])])) {
            return self::RAW_ALIASES[$raw];
        }
        $key = self::fold($text);
        if ($key === '') {
            return null;
        }

        $found = $this->exactMatches($key);
        if ($found !== []) {
            return self::single($found);
        }

        $core = self::stripGeneric($key);
        if ($core !== '' && $core !== $key) {
            $found = $this->exactMatches($core);
            if ($found === [] && !str_contains($core, ' ')) {
                $noun = (string) preg_replace('/(sk|ck|zk)a$/', '$1o', $core);
                $found = $noun !== $core ? $this->exactMatches($noun) : [];
            }
            if ($found !== []) {
                return self::single($found);
            }
        }

        if (strlen($key) >= self::MIN_PREFIX) {
            $found = [];
            foreach ($this->names as [$name, $iso]) {
                if (str_starts_with($name, $key)) {
                    $found[$iso] = true;
                }
            }
            if ($found !== []) {
                return self::single($found);
            }
        }

        return self::single($this->embeddedMatches(str_replace(' ', '', $key)));
    }

    /** @return array<string,true> */
    private function exactMatches(string $key): array
    {
        $found = $this->exact[$key] ?? [];
        if (strlen($key) === 2 && isset($this->codes2[$key])) {
            $found[$this->codes2[$key]] = true;
        }
        if (strlen($key) === 3 && isset($this->codes3[$key])) {
            $found[$this->codes3[$key]] = true;
        }
        return $found;
    }

    /**
     * Názvy zemí uvnitř slepeného textu. Výskyt schovaný v delším nalezeném názvu se
     * nepočítá („oman" v „romania", „niger" v „nigeria").
     *
     * @return array<string,true>
     */
    private function embeddedMatches(string $compact): array
    {
        $hits = [];
        foreach ($this->names as [$name, $iso]) {
            $needle = str_replace(' ', '', $name);
            if (strlen($needle) < self::MIN_EMBEDDED) {
                continue;
            }
            $offset = 0;
            while (($pos = strpos($compact, $needle, $offset)) !== false) {
                $hits[] = [$pos, $pos + strlen($needle), $iso];
                $offset = $pos + 1;
            }
        }
        $found = [];
        foreach ($hits as $i => [$start, $end, $iso]) {
            foreach ($hits as $j => [$otherStart, $otherEnd]) {
                if ($i !== $j && $otherStart <= $start && $otherEnd >= $end && $otherEnd - $otherStart > $end - $start) {
                    continue 2;
                }
            }
            $found[$iso] = true;
        }
        return $found;
    }

    private static function stripGeneric(string $key): string
    {
        $words = array_filter(explode(' ', $key), static fn (string $w): bool => !in_array($w, self::GENERIC_WORDS, true));
        return implode(' ', $words);
    }

    /** @param array<string,true> $found */
    private static function single(array $found): ?string
    {
        return count($found) === 1 ? (string) array_key_first($found) : null;
    }

    private function addName(string $key, string $iso): void
    {
        if ($key === '') {
            return;
        }
        if (!isset($this->exact[$key][$iso])) {
            $this->exact[$key][$iso] = true;
            $this->names[] = [$key, $iso];
        }
    }
}
