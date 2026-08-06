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
 *
 * ── Výchozí nastavení OSS v kartě odběratele (migrace 1298) ─────────────────────────
 * Karta odběratele umí ke dvěma otázkám říct svoje, ale ani jedna z nich nesmí přebít
 * rozhodnutí o MÍSTĚ PLNĚNÍ — to zůstává výhradně na {@see OssItemDeriver}:
 *
 *  - `$ossExcluded` (režim „u tohohle odběratele OSS neuplatňovat") umí OSS jedině UBRAT,
 *    nikdy přidat. Přidat by znamenalo druhou autoritu nad místem plnění, a ta by uměla
 *    poslat do OSS i plnění, které tam nepatří (odběratel s DIČ, tuzemská dodávka).
 *    Ubrat je bezpečné, protože invariant proti úniku cizí daně platí dál: řádek s cizí
 *    sazbou se do tuzemské větve ani po vyloučení nedostane, jen se odmítne s hláškou.
 *  - `$defaultSupplyType` odpovídá na otázku, kterou deriver dnes DOHADUJE (zboží vs.
 *    služba, fallback „služba"). Uživatelská znalost je vždy lepší než fallback a o místě
 *    plnění nevypovídá nic — proto se uplatní automaticky, ale až POD měrnou jednotkou
 *    položky: jednotka je důkaz z konkrétního řádku, kdežto default je vlastnost karty.
 *
 * ZÁMĚRNĚ tu NENÍ „výchozí země spotřeby". Země spotřeby se bere z adresy odběratele
 * (nebo z dokladu) a je to týž údaj, proti kterému se rozhoduje o tuzemsku; default
 * v kartě by uměl daň poslat do jiného státu, než jaký doklad uvádí, a rozpor by nebyl
 * nikde vidět. Chybí-li země, je to vada karty s vlastní hláškou
 * ({@see OssDerivationReason::ClientCountryUnknown}) — druhé místo, kam ji vyplnit,
 * by problém neřešilo, jen rozdvojilo.
 */
final readonly class OssClientContext
{
    /**
     * @param bool     $ossExcluded       karta odběratele říká „OSS se u něj neuplatňuje";
     *                                    smí OSS jedině vyloučit, nikdy vynutit
     * @param ?string  $defaultSupplyType 'goods'|'services' z karty odběratele, nebo `null`
     */
    public function __construct(
        public ?string $countryIso2,
        public bool $isEu,
        public ?string $dic,
        public bool $countryFromDocument = false,
        public bool $ossExcluded = false,
        public ?string $defaultSupplyType = null,
    ) {}

    /**
     * @param array<string,mixed> $row akceptuje `country_iso2` i `iso2` (tvar z JOINu
     *                                 i z parseru importu), `is_eu`, `dic`,
     *                                 `oss_mode`, `oss_default_supply_type`
     */
    public static function fromArray(array $row, bool $countryFromDocument = false): self
    {
        // Neznámá země se NIKDY nedomýšlí na 'CZ' — u importu ze zahraničního systému
        // by tichý default poslal cizí plnění do českého přiznání.
        $country = self::iso2OrNull($row['country_iso2'] ?? $row['iso2'] ?? null);
        $dic = trim((string) ($row['dic'] ?? ''));

        return new self(
            $country,
            !empty($row['is_eu']),
            $dic === '' ? null : $dic,
            $countryFromDocument,
            // Jen doslovné 'never' vylučuje. Neznámá hodnota (starší instalace bez migrace
            // 1298, ručně upravený ENUM) znamená „automaticky", protože vypnutí OSS musí
            // být vědomé rozhodnutí, ne důsledek prázdného sloupce.
            ($row['oss_mode'] ?? 'auto') === 'never',
            self::supplyTypeOrNull($row['oss_default_supply_type'] ?? null),
        );
    }

    /** Jediná interpretace „je tohle použitelný typ plnění" — shodná s `OssItemDecision::SUPPLY_TYPES`. */
    public static function supplyTypeOrNull(mixed $value): ?string
    {
        $type = strtolower(trim((string) ($value ?? '')));

        return in_array($type, OssItemDecision::SUPPLY_TYPES, true) ? $type : null;
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
