<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

/**
 * Strojově čitelný důvod, proč {@see OssItemDeriver} rozhodl tak, jak rozhodl.
 *
 * Automatická derivace se dá udělat dvěma způsoby: buď tiše nastaví pole, nebo řekne proč.
 * Tichá varianta je u daní nepoužitelná — uživatel s 850 importovanými doklady musí umět
 * odpovědět na otázku „proč zrovna tenhle řádek OSS nedostal", jinak mu nezbývá než všech
 * 850 prokliknout ručně, což je přesně to, čemu se derivace vyhýbá. Enum proto nese
 * důvod jako HODNOTU: import ho dá do reportu, backfill do logu a test na něj může
 * asertovat, aniž by parsoval českou větu.
 *
 * ── Důvod vs. poznámka ──────────────────────────────────────────────────────────────
 * Primární důvod je právě jeden a odpovídá na otázku „je řádek OSS". Poznámky jsou
 * doprovodné a říkají, ODKUD se vzaly odvozené parametry, případně že se odvodit
 * nepodařilo ({@see isWarning()}).
 *
 * ── Nejednoznačnost se řeší ve prospěch OSS ─────────────────────────────────────────
 * Sazba sama o sobě místo plnění neurčuje: 21 % platí v NL, BE, ES, LT i LV stejně jako
 * v ČR a 12 % ve Švédsku stejně jako česká snížená. Když sazba sedí OBĚMA výkladům
 * ({@see RateAmbiguousDomesticOrConsumer}), nebo když se číselník na jednu ze stran
 * nedokáže zeptat ({@see RateOriginUnverifiable}), zařadí se řádek do OSS a označí se
 * jako případ K RUČNÍMU POSOUZENÍ.
 *
 * Směr té úlevy není libovolný, plyne z ASYMETRIE VIDITELNOSTI CHYBY:
 *  - chybně označený OSS řádek se objeví v náhledu OSS podání, což je krátký seznam,
 *    který uživatel před odesláním prochází — chyba je vidět a jde opravit;
 *  - chybně označený tuzemský řádek zmizí mezi stovkami legitimních řádků přiznání
 *    k DPH a nikdo ho nenajde, dokud nepřijde výzva.
 * Dřívější podoba rozhodovala opačně („při pochybnosti nech řádek tuzemský") a byla to
 * chyba: nejednoznačné řádky mizely přesně tam, kde je nikdo nehledá.
 *
 * ── Nevědomost není odpověď ─────────────────────────────────────────────────────────
 * Poznámky {@see CodebookUnavailable}, {@see ConsumerCountryNotInCodebook} a
 * {@see DomesticRatesNotInCodebook} říkají, že se číselníku nedalo zeptat (chybí migrace
 * 1152, stát v něm k datu není). Od invariantu, který je TOTÁLNÍ
 * ({@see OssItemDeriver}), z nich nikdy nevznikne tuzemský řádek: buď jde řádek do OSS
 * k ručnímu posouzení, nebo se položka odmítne. Tuzemské plnění stojí výhradně na
 * POZITIVNÍM potvrzení číselníku, takže „řádek s poznámkou o nevědomosti" už jako
 * tuzemský vzniknout neumí — a poznámky tím přestaly být poslední pojistkou a jsou
 * jen vysvětlením pro report.
 *
 * ── Rozpor, o kterém se NEROZHODUJE ─────────────────────────────────────────────────
 * {@see DomesticRateOnCrossBorderB2c} je jiná kategorie než všechno výše: nemění
 * rozhodnutí, jen ho zpochybňuje. Kvadrant „tuzemské plnění" byl do téhle chvíle úplně
 * tichý, takže doklad pro polského spotřebitele bez DIČ se sazbou 21 % prošel bez
 * jediného slova, přestože firma měla na to období aktivní registraci do OSS. Není to
 * únik cizí daně (sazbu uvádí sám doklad a číselník ji v zemi dodavatele potvrdil) ani
 * chyba systému — registrace je dobrovolná a plnění pod prahem § 8/3 tuzemské být může.
 * Je to ale vnitřní rozpor, o kterém se uživatel nesmí dozvědět až z výzvy: řádek proto
 * zůstává tuzemský a dostane varování a příznak k ručnímu posouzení.
 */
enum OssDerivationReason: string
{
    // ── Primární důvody: řádek NENÍ OSS (pořadí = pořadí vyhodnocení v derive()) ──
    case MissingTaxDate = 'missing_tax_date';
    case OssSchemaMissing = 'oss_schema_missing';
    case SupplierOssDisabled = 'supplier_oss_disabled';
    case SupplierOssNotValidOnDate = 'supplier_oss_not_valid_on_date';
    case HeaderReverseCharge = 'header_reverse_charge';
    case ClientCountryUnknown = 'client_country_unknown';
    case ClientDomestic = 'client_domestic';
    case ClientNotEu = 'client_not_eu';
    case ClientHasVatId = 'client_has_vat_id';
    case ClientOssExcluded = 'client_oss_excluded';
    case ZeroRate = 'zero_rate';
    case RateMatchesDomesticOnly = 'rate_matches_domestic_only';

    // ── Primární důvody: řádek JE OSS ──
    case B2cEuConsumer = 'b2c_eu_consumer';
    case RateAmbiguousDomesticOrConsumer = 'rate_ambiguous_domestic_or_consumer';
    case RateOriginUnverifiable = 'rate_origin_unverifiable';

    // ── Poznámky (jen v OssItemDecision::$notes, nikdy jako $reason) ──
    case RateTypeFromCodebook = 'rate_type_from_codebook';
    case RateTypeUnknown = 'rate_type_unknown';
    case RateUnknownInConsumerCountry = 'rate_unknown_in_consumer_country';
    case DomesticRateOnCrossBorderB2c = 'domestic_rate_on_cross_border_b2c';
    case ConsumerCountryNotInCodebook = 'consumer_country_not_in_codebook';
    case DomesticRatesNotInCodebook = 'domestic_rates_not_in_codebook';
    case CodebookUnavailable = 'codebook_unavailable';
    case ClientCountryFromDocument = 'client_country_from_document';
    case SupplyTypeFromUnit = 'supply_type_from_unit';
    case SupplyTypeFromClientDefault = 'supply_type_from_client_default';
    case SupplyTypeFromSupplierNace = 'supply_type_from_supplier_nace';
    case SupplyTypeDefaultServices = 'supply_type_default_services';

    /** Česká hláška do reportu importu / backfillu (jedna věta, bez tečky na konci). */
    public function message(): string
    {
        return match ($this) {
            self::MissingTaxDate => 'Doklad nemá použitelné datum plnění (očekává se tvar RRRR-MM-DD) — '
                . 'bez něj nelze ověřit, komu sazba patří',
            self::OssSchemaMissing => 'Chybí databázová migrace OSS (0137_oss_foundation.sql) — spusťte php api/bin/migrate.php',
            self::SupplierOssDisabled => 'Firma nemá zapnutý režim OSS (Nastavení → DPH → OSS)',
            self::SupplierOssNotValidOnDate => 'Datum plnění je mimo platnost registrace firmy do OSS',
            self::HeaderReverseCharge => 'Doklad je v režimu přenesené daňové povinnosti, OSS se na něj neuplatní',
            self::ClientCountryUnknown => 'Odběratel nemá vyplněnou zemi — země spotřeby by byla dohad, OSS se neodvozuje',
            self::ClientDomestic => 'Odběratel je ze země dodavatele, jde o tuzemské plnění',
            self::ClientNotEu => 'Odběratel není ze státu EU, OSS se nepoužije',
            self::ClientHasVatId => 'Odběratel má DIČ, jde o B2B plnění (reverse charge / dodání do JČS), ne o OSS',
            self::ClientOssExcluded => 'Odběratel má v kartě nastaveno, že se u něj OSS neuplatňuje '
                . '(Klient → DPH a OSS → režim OSS)',
            self::ZeroRate => 'Řádek má nulovou sazbu — OSS se týká jen plnění zdaněného sazbou státu spotřeby',
            self::RateMatchesDomesticOnly => 'Sazba podle číselníku členských států platí v zemi dodavatele a ve státě '
                . 'spotřeby neplatí — jde o tuzemské plnění',

            self::B2cEuConsumer => 'Přeshraniční B2C plnění do jiného členského státu, řádek se vykáže v OSS',
            self::RateAmbiguousDomesticOrConsumer => 'Sazba platí podle číselníku členských států zároveň ve státě spotřeby '
                . 'i v zemi dodavatele, takže z ní nejde určit místo plnění — řádek je zařazen do OSS a je '
                . 'K RUČNÍMU POSOUZENÍ; jde-li o tuzemské plnění, vypněte na položce režim OSS',
            self::RateOriginUnverifiable => 'Číselník sazeb členských států neumí k datu plnění odpovědět, komu sazba patří, '
                . 'takže místo plnění z ní určit nejde — řádek je zařazen do OSS a je K RUČNÍMU POSOUZENÍ; '
                . 'jde-li o tuzemské plnění, vypněte na položce režim OSS',

            self::RateTypeFromCodebook => 'Typ sazby převzat z číselníku sazeb členských států',
            self::RateTypeUnknown => 'Typ sazby se nepodařilo zjistit, zůstal prázdný — doplňte ho na položce, '
                . 'jinak řádek nepůjde zahrnout do OSS podání',
            self::RateUnknownInConsumerCountry => 'Sazba neodpovídá žádné sazbě platné ve státě spotřeby — ověřte sazbu na dokladu',
            self::DomesticRateOnCrossBorderB2c => 'Řádek je zdaněný TUZEMSKOU sazbou, přestože jde o přeshraniční plnění '
                . 'spotřebiteli bez DIČ z jiného členského státu a firma má k datu plnění aktivní registraci do OSS. '
                . 'Sazbu ani zařazení jsme nezměnili — uvádí je doklad a registrace do OSS je dobrovolná, takže plnění '
                . 'tuzemské být může. Řádek je ale K RUČNÍMU POSOUZENÍ: patří-li do OSS, opravte na dokladu sazbu na '
                . 'sazbu státu spotřeby',
            self::ConsumerCountryNotInCodebook => 'Stát spotřeby v číselníku sazeb členských států není — sazbu nelze ověřit',
            self::DomesticRatesNotInCodebook => 'Země dodavatele v číselníku sazeb členských států k datu plnění není — '
                . 'nešlo ověřit, jestli je sazba tuzemská',
            self::CodebookUnavailable => 'Chybí číselník sazeb členských států (migrace 1152) — spusťte php api/bin/migrate.php',
            self::ClientCountryFromDocument => 'Země spotřeby převzata z importovaného dokladu, ne z uloženého odběratele',
            self::SupplyTypeFromUnit => 'Typ plnění odvozen z měrné jednotky položky',
            self::SupplyTypeFromClientDefault => 'Typ plnění převzat z výchozího nastavení OSS v kartě odběratele',
            self::SupplyTypeFromSupplierNace => 'Typ plnění odvozen z převažující činnosti dodavatele (CZ-NACE)',
            self::SupplyTypeDefaultServices => 'Typ plnění nebylo z čeho odvodit, použita výchozí „služba"',
        };
    }

    /** Případ, který má uživatel po importu zkontrolovat — report ho zvýrazní. */
    public function isWarning(): bool
    {
        return match ($this) {
            // Chybějící datum plnění je varování, ne poznámka: u sazby > 0 skončí odmítnutím
            // dokladu, ale u nulové sazby (osvobození, RC, vývoz) položka projde — a bez
            // varování by o vadném datu na dokladu report mlčel úplně.
            self::MissingTaxDate,
            self::RateAmbiguousDomesticOrConsumer,
            self::RateOriginUnverifiable,
            self::RateTypeUnknown,
            self::RateUnknownInConsumerCountry,
            self::DomesticRateOnCrossBorderB2c,
            self::ConsumerCountryNotInCodebook,
            self::DomesticRatesNotInCodebook,
            self::CodebookUnavailable,
            // Výchozí „služba" není odvození, ale dosazení: jednotka ani NACE dodavatele
            // nic neřekly. Typ plnění přitom rozhoduje o sazbě ve státě spotřeby (zboží
            // a služba tam mají různé sazby i různá pravidla), takže špatně dosazený typ
            // vykáže OSS podání ve špatné sazbě. Ve sbalených poznámkách by se to k
            // uživateli s 850 doklady nedostalo — patří mezi varování.
            self::SupplyTypeDefaultServices => true,
            default => false,
        };
    }

    /**
     * Případ, kde je MÍSTO PLNĚNÍ sporné a čeká na člověka. Report ho počítá zvlášť od
     * běžných varování: u migrace 1 670 dokladů je rozdíl mezi „zkontrolujte typ sazby"
     * a „tady je místo plnění sporné" rozdílem mezi kosmetikou a chybným přiznáním.
     *
     * Příznak musí přežít zavření reportu a uložit se k položce
     * (`invoice_items.oss_needs_manual_review`), jinak ho po zavření stránky nikdo
     * nedohledá.
     *
     * Kategorie NENÍ podmnožinou OSS řádků. První dva případy stojí jako DŮVOD u řádku,
     * který do OSS šel (nejednoznačnost se řeší ve prospěch OSS); třetí je POZNÁMKA
     * u řádku, který zůstal TUZEMSKÝ, a právě proto je podezřelý. Kdo z toho počítá
     * čísla do reportu, nesmí předpokládat, že „k ručnímu posouzení" ⊆ „v OSS".
     */
    public function needsManualReview(): bool
    {
        return match ($this) {
            self::RateAmbiguousDomesticOrConsumer,
            self::RateOriginUnverifiable,
            self::DomesticRateOnCrossBorderB2c => true,
            default => false,
        };
    }

    /**
     * Smí tenhle důvod stát u OSS řádku? Invariant {@see OssItemDecision} ho vynucuje, aby
     * se do OSS větve nedal propašovat důvod, který znamená pravý opak (např. ClientDomestic).
     */
    public function canBeOssReason(): bool
    {
        return match ($this) {
            self::B2cEuConsumer,
            self::RateAmbiguousDomesticOrConsumer,
            self::RateOriginUnverifiable => true,
            default => false,
        };
    }

    /**
     * Co konkrétně uživateli chybí, aby řádek šel zařadit do OSS — druhá věta odmítnutí
     * podle {@see OssItemDeriver} (invariant „tuzemské plnění stojí výhradně na pozitivním
     * potvrzení číselníku, každá jiná odpověď vede k odmítnutí").
     *
     * Obecné „nelze zpracovat" je u migrace 1 670 dokladů k ničemu: uživatel potřebuje
     * vědět, jestli má doplnit zemi odběratele, zapnout OSS, nebo opravit sazbu.
     */
    public function rejectionRemedy(): string
    {
        return match ($this) {
            self::MissingTaxDate => 'doplňte na dokladu datum uskutečnění zdanitelného plnění (DUZP), '
                . 'případně datum vystavení, ve tvaru RRRR-MM-DD',
            self::OssSchemaMissing => 'chybí databázová migrace OSS (0137_oss_foundation.sql) — '
                . 'spusťte php api/bin/migrate.php a import opakujte',
            self::SupplierOssDisabled => 'firma nemá zapnutý režim OSS — zapněte ho v Nastavení → DPH → OSS, '
                . 'nebo opravte sazbu na dokladu',
            self::SupplierOssNotValidOnDate => 'datum plnění je mimo platnost registrace firmy do OSS — '
                . 'upravte platnost v Nastavení → DPH → OSS, nebo opravte sazbu na dokladu',
            self::HeaderReverseCharge => 'doklad je v režimu přenesené daňové povinnosti — zrušte ho, '
                . 'jde-li o plnění do jiného členského státu, jinak opravte sazbu na dokladu',
            self::ClientCountryUnknown => 'odběratel nemá vyplněnou zemi — doplňte ji na dokladu nebo v kartě '
                . 'odběratele, bez ní nelze určit stát spotřeby',
            self::ClientDomestic => 'odběratel je veden ve stejné zemi jako dodavatel — opravte zemi odběratele '
                . 'na dokladu nebo v jeho kartě, jinak opravte sazbu na dokladu',
            self::ClientNotEu => 'odběratel je mimo EU, kde se OSS neuplatní — opravte zemi odběratele '
                . 'na dokladu nebo v jeho kartě, jinak opravte sazbu na dokladu',
            self::ClientHasVatId => 'odběratel má DIČ, takže jde o B2B plnění — odeberte DIČ, jde-li o spotřebitele, '
                . 'jinak opravte sazbu na dokladu',
            self::ClientOssExcluded => 'odběratel má v kartě vypnutý režim OSS — přepněte ho v kartě odběratele '
                . 'zpět na automatický, jde-li o plnění do jiného členského státu, jinak opravte sazbu na dokladu',
            default => 'opravte sazbu na dokladu, nebo zemi odběratele',
        };
    }
}
