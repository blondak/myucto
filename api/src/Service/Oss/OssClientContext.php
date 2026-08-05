<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

/**
 * To málo o odběrateli, co rozhoduje o režimu OSS: země, členství v EU, DIČ.
 *
 * Vlastní DTO existuje proto, že tutéž otázku klade víc vstupních cest s odlišným tvarem
 * dat — import z Pohody/ISDOCu má klienta rozparsovaného v poli, editor a backfill ho mají
 * z `clients JOIN countries`. Kdyby {@see OssItemDeriver} bral rovnou databázový řádek,
 * musely by se ostatní cesty tvářit jako databáze, nebo by si každá vyrobila vlastní
 * (a nevyhnutelně jinou) interpretaci „prázdného DIČ" a „neznámé země".
 *
 * ── Proč se rozlišuje, odkud země je ────────────────────────────────────────────────
 * `ClientResolver` ukládá `country_iso2` s fallbackem `'CZ'` a `countryIdFromIso2()` na
 * neznámé ISO odpovídá rovněž českým státem. Uložený klient tedy může tvrdit „CZ" i tam,
 * kde doklad žádnou zemi nenesl — a derivace by z toho udělala tuzemské plnění a poslala
 * cizí daň na ř. 1. Země z IMPORTOVANÉHO DOKLADU proto uloženou přebíjí a
 * `$countryFromDocument` to nese do reportu, aby bylo vidět, který údaj rozhodl.
 */
final readonly class OssClientContext
{
    public function __construct(
        public ?string $countryIso2,
        public bool $isEu,
        public ?string $dic,
        public bool $countryFromDocument = false,
    ) {}

    /**
     * @param array<string,mixed> $row akceptuje `country_iso2` i `iso2` (tvar z JOINu
     *                                 i z parseru importu), `is_eu`, `dic`
     */
    public static function fromArray(array $row, bool $countryFromDocument = false): self
    {
        // Neznámá země se NIKDY nedomýšlí na 'CZ' — u importu ze zahraničního systému
        // by tichý default poslal cizí plnění do českého přiznání.
        $country = self::iso2OrNull($row['country_iso2'] ?? $row['iso2'] ?? null);
        $dic = trim((string) ($row['dic'] ?? ''));

        return new self($country, !empty($row['is_eu']), $dic === '' ? null : $dic, $countryFromDocument);
    }

    /** Jediná interpretace „je tohle použitelný ISO2 kód země" pro celou OSS větev. */
    public static function iso2OrNull(mixed $value): ?string
    {
        $code = strtoupper(trim((string) ($value ?? '')));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }

    /**
     * Má odběratel DIČ? Pak jde o B2B (reverse charge / dodání do JČS), ne o OSS.
     *
     * Test je ZÁMĚRNĚ doslova stejný jako v {@see OssThresholdService::b2cRows()}
     * (`dic IS NULL OR TRIM(dic) = ''`): obojí odpovídá na tutéž otázku „je to přeshraniční
     * B2C plnění". Kdyby se rozešly, práh 10 000 EUR by měřil jinou množinu dokladů, než
     * jaká se skutečně vykáže v OSS. Případné zjemnění (např. „DIČ musí vypadat jako EU
     * VAT ID") se musí udělat na obou místech naráz.
     */
    public function hasVatId(): bool
    {
        return trim((string) $this->dic) !== '';
    }
}
