<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Service\Tax\Return\TaxReturnException;

/**
 * Hlavička podaného přiznání k DPPO (EPO XML DPPDP9): kdo ho podal, za které období,
 * v jaké formě a s jakými údaji o účetní jednotce (NACE, kategorie, audit, sídlo).
 *
 * Z hlavičky staví identitu firmy převod z cizího programu, když firmu zakládá: podané
 * přiznání je doklad, který má účetní kancelář k dispozici i tehdy, když záloha programu
 * sídlo nebo kategorii účetní jednotky nenese. Řádky II. oddílu čte
 * {@see \MyInvoice\Service\Tax\Return\DppoEpoXmlParser} a přebírá {@see FiledDppoImporter}.
 *
 * Z více podání téže firmy za rok platí poslední: dodatečné před opravným před řádným,
 * u více dodatečných pozdější datum zjištění ({@see latestPerYear()}). Totéž pravidlo
 * používá CLI `tax-return-import.php` i dávkový převod.
 */
final class FiledDppoFiling
{
    /** Pořadí formy podání: vyšší číslo přebíjí nižší. */
    private const FORMA_RANK = ['B' => 1, 'O' => 2, 'D' => 3, 'E' => 3];

    /** Kategorie účetní jednotky z podání (kat_uj) → rozsah výkazů MyÚčta. */
    private const SCOPE = ['M' => 'micro', 'S' => 'small'];

    /**
     * @param array{street:string,city:string,zip:string} $address
     */
    public function __construct(
        public readonly string $ic,
        public readonly string $dic,
        public readonly string $name,
        public readonly array $address,
        public readonly ?string $nace,
        public readonly ?string $category,
        public readonly ?bool $audit,
        public readonly string $forma,
        public readonly string $discoveredOn,
        public readonly ?string $periodFrom,
        public readonly ?string $periodTo,
    ) {}

    public static function parse(string $xml): self
    {
        $xml = trim($xml);
        if ($xml === '') {
            throw new TaxReturnException('invalid_xml', 'Nahraný soubor je prázdný.', 400);
        }
        $dom = new \DOMDocument();
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = @$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }
        if (!$ok || $dom->getElementsByTagName('DPPDP9')->item(0) === null) {
            throw new TaxReturnException('wrong_form', 'Soubor není přiznání DPPDP9 (daň z příjmů právnických osob).', 400);
        }
        $d = $dom->getElementsByTagName('VetaD')->item(0);
        $p = $dom->getElementsByTagName('VetaP')->item(0);
        if (!$d instanceof \DOMElement) {
            throw new TaxReturnException('wrong_form', 'V XML chybí věta VetaD (hlavička přiznání).', 400);
        }
        $attr = static fn (?\DOMElement $el, string $name): string => $el !== null ? trim($el->getAttribute($name)) : '';

        $street = trim($attr($p, 'ulice') . ' ' . $attr($p, 'c_pop') . ($attr($p, 'c_orient') !== '' ? '/' . $attr($p, 'c_orient') : ''));
        $city = $attr($p, 'naz_obce');
        $kat = strtoupper($attr($d, 'kat_uj'));
        $audit = $attr($d, 'audit');
        $forma = $attr($d, 'dapdpp_forma') !== '' ? $attr($d, 'dapdpp_forma') : 'B';

        return new self(
            $attr($p, 'rod_c'),
            strtoupper(str_replace(' ', '', $attr($p, 'dic'))),
            $attr($p, 'zkrobchjm'),
            ['street' => $street !== '' ? $street : trim($city . ' ' . $attr($p, 'c_pop')), 'city' => $city, 'zip' => str_replace(' ', '', $attr($p, 'psc'))],
            $attr($d, 'c_nace') !== '' ? $attr($d, 'c_nace') : null,
            $kat === '' ? null : (self::SCOPE[$kat] ?? 'full'),
            $audit === '' ? null : $audit === 'A',
            $forma,
            // Datum zjištění důvodů nese jen dodatečné přiznání.
            in_array($forma, ['D', 'E'], true) ? (self::isoDate($attr($d, 'd_zjist')) ?? '') : '',
            self::isoDate($attr($d, 'zdobd_od')),
            self::isoDate($attr($d, 'zdobd_do')),
        );
    }

    /** Rok zdaňovacího období (konec, u chybějícího konce začátek). */
    public function year(): ?int
    {
        $date = $this->periodTo ?? $this->periodFrom;
        return $date !== null ? (int) substr($date, 0, 4) : null;
    }

    /** Patří podání firmě s tímto IČO? Podání bez IČO se nevylučuje. */
    public function belongsTo(string $ic): bool
    {
        $mine = ltrim(trim($this->ic), '0');
        $theirs = ltrim(trim($ic), '0');
        return $mine === '' || $theirs === '' || $mine === $theirs;
    }

    /** @return array{0:int,1:string} klíč řazení podání téhož roku (vyšší = pozdější) */
    public function rank(): array
    {
        return [self::FORMA_RANK[$this->forma] ?? 1, $this->discoveredOn];
    }

    /**
     * Za každý rok poslední podání firmy, roky vzestupně.
     *
     * @template T of array{filing:self}
     * @param list<T> $entries podání (s libovolnými dalšími klíči, např. cesta k souboru)
     * @param string|null $ic jen podání této firmy
     * @param (callable(T):string)|null $tieBreak poslední kritérium při shodě formy i data (typicky jméno souboru)
     * @return array<int,T> rok => podání
     */
    public static function latestPerYear(array $entries, ?string $ic = null, ?callable $tieBreak = null): array
    {
        $byYear = [];
        $keys = [];
        foreach ($entries as $entry) {
            $filing = $entry['filing'];
            $year = $filing->year();
            if ($year === null || ($ic !== null && !$filing->belongsTo($ic))) {
                continue;
            }
            $key = [...$filing->rank(), $tieBreak !== null ? $tieBreak($entry) : ''];
            if (!isset($byYear[$year]) || $key > $keys[$year]) {
                $byYear[$year] = $entry;
                $keys[$year] = $key;
            }
        }
        ksort($byYear);
        return $byYear;
    }

    /**
     * Začátek prvního účetního období podle nejstaršího podání: firma vzniklá v roce má
     * první zdaňovací období od data vzniku, ne od 1. 1. Null = začíná 1. 1. nebo nevíme.
     *
     * @param array<int,array{filing:self}> $perYear výsledek {@see latestPerYear()}
     */
    public static function firstPeriodStart(array $perYear): ?string
    {
        if ($perYear === []) {
            return null;
        }
        ksort($perYear);
        $from = reset($perYear)['filing']->periodFrom;
        return $from !== null && substr($from, 5) !== '01-01' ? $from : null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'ic' => $this->ic,
            'dic' => $this->dic,
            'name' => $this->name,
            'address' => $this->address,
            'nace' => $this->nace,
            'category' => $this->category,
            'audit' => $this->audit,
            'forma' => $this->forma,
            'discovered_on' => $this->discoveredOn,
            'period_from' => $this->periodFrom,
            'period_to' => $this->periodTo,
            'year' => $this->year(),
        ];
    }

    private static function isoDate(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $raw, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : null;
    }
}
