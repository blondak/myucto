<?php

declare(strict_types=1);

namespace MyInvoice\Service\Oss;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\VatClassificationDefaulter;

/**
 * Jediný zdroj pravdy pro otázku „je tenhle řádek OSS a s jakými parametry".
 *
 * OSS byl v systému čistě ruční přepínač: jediná cesta, jak se řádek do evidence dostal,
 * bylo zaškrtnutí `oss_applicable` v editoru a ruční doplnění země spotřeby, typu sazby
 * a typu plnění na KAŽDÉ položce. Import (Pohoda XML, ISDOC), API importy ani AI extrakce
 * o OSS nevěděly nic. U migrace s 850 zahraničními doklady to není ergonomický problém,
 * ale blokátor — a hlavně: dokud řádek OSS nedostane, pustí ho `VatLedgerService` do
 * TUZEMSKÉHO přiznání, takže se polská daň vykáže na ř. 1 jako česká.
 *
 * ── AUTORITA: číselník členských států, NIKDY vat_rates ─────────────────────────────
 * Otázku „komu ta sazba patří" klade deriver VÝHRADNĚ číselníku sazeb členských států
 * ({@see OssRateCodebook}, tabulka `oss_member_state_rates`, seed migrací 1152). Ten je
 * seedovaný a uživatel do něj nesahá, takže obě strany rozhodnutí — stát spotřeby i země
 * dodavatele — stojí na stejném a nemanipulovatelném podkladu.
 *
 * Tabulka `vat_rates` autorita NENÍ a nesmí se na ni nikdy odkázat jako na důkaz o místě
 * plnění. Je to uživatelsky editovatelný GLOBÁLNÍ číselník sazeb pro DOKLAD: zákazník
 * z analýzy si v něm založil sazbu s kódem „PL-23", ale se zemí CZ, protože formulář má
 * CZ předvyplněnou. Dotaz „zná ČR 23 %" nad `vat_rates` by tedy vrátil ANO, polský řádek
 * by spadl do nejednoznačnosti, zůstal tuzemský, dostal klasifikaci '1' a skončil na ř. 1
 * českého přiznání — přesně původní chyba, pro přesně tu konfiguraci, kterou zákazník má.
 * `vat_rates` slouží jedině k dohledání `vat_rate_id` pro zápis položky
 * ({@see \MyInvoice\Service\Vat\VatRateResolver}), a to až POTÉ, co je místo plnění
 * rozhodnuté.
 *
 * ── ROZHODOVACÍ TABULKA: TUZEMSKÁ VĚTEV MÁ JEDINÝ VSTUP ─────────────────────────────
 * O tuzemsku rozhoduje JEDINÁ otázka, a ta tvoří sloupce tabulky: „potvrdil číselník
 * ČLENSKÝCH STÁTŮ, že sazba v ZEMI DODAVATELE k datu plnění platí?" Odpovědi jsou tři,
 * protože „nevím" není „ne":
 *   PLATÍ   — číselník zemi zná a sazba mezi jejími sazbami k datu je (POZITIVNÍ potvrzení)
 *   NEPLATÍ — číselník zemi zná a sazba mezi jejími sazbami NENÍ
 *   NEVÍM   — číselník chybí (migrace 1152), zemi k datu nezná, nebo datum plnění chybí
 *             či je nečitelné, takže se nedá položit ani otázka
 *
 * Řádky jsou všechno ostatní: buď řádek OSS být nemůže ({@see blockingReason()} —
 * vypnutý OSS, odběratel bez země, s DIČ, mimo EU…), nebo se doptáváme státu spotřeby.
 *
 *   ↓ ostatní \ země dodavatele → |  PLATÍ            |  NEPLATÍ           |  NEVÍM
 *   ------------------------------|-------------------|--------------------|-------------------
 *   řádek OSS být nemůže          |  TUZEMSKÉ PLNĚNÍ  |  ODMÍTNUTO         |  ODMÍTNUTO
 *   stát spotřeby: NEPLATÍ        |  TUZEMSKÉ PLNĚNÍ  |  OSS, typ prázdný  |  OSS + RUČNĚ
 *   stát spotřeby: PLATÍ          |  OSS + RUČNĚ      |  OSS               |  OSS + RUČNĚ
 *   stát spotřeby: NEVÍM          |  OSS + RUČNĚ      |  OSS, typ prázdný  |  OSS + RUČNĚ
 *
 * „TUZEMSKÉ PLNĚNÍ" stojí JEN ve sloupci PLATÍ — jinam se dostat nedá. To je celý
 * invariant proti úniku: do tuzemské větve smí výhradně řádek, u kterého číselník
 * POZITIVNĚ potvrdil, že sazba v zemi dodavatele k datu plnění platí. Každá jiná
 * odpověď (NEPLATÍ, NEVÍM, chybějící i nečitelné datum) vede buď na OSS, nebo na
 * ODMÍTNUTÍ položky ({@see OssItemDecision::rejected()}) hláškou, která pojmenuje, co
 * konkrétně doplnit. Dřívější podoba tuhle jedinou otázku obracela („odmítni jen na
 * tvrdé NE") a nevědomost tím mlčky vydávala za tuzemsko — a protože číselník mlčí
 * přesně tam, kde neproběhla migrace 1152 nebo kde je doklad starší než seed, byl to
 * nejčastější stav, ne okrajový.
 *
 * VÝJIMKA — sazba 0 %: invariant se na ni ZÁMĚRNĚ neuplatní a řádek zůstává mimo OSS
 * i bez potvrzení číselníku. Důvod není shovívavost, ale to, že tu není co unikat:
 * osvobození, přenesená daňová povinnost i vývoz se vykazují BEZ DANĚ, takže z takového
 * řádku žádná cizí daň do tuzemského přiznání spadnout nemůže. Číselník členských států
 * navíc nulové sazby vůbec nevede, takže by na nulu odpověděl „NEPLATÍ" u každé země
 * a vynucení invariantu by odmítlo každé osvobozené plnění.
 *
 * ── Tuzemský kvadrant není tichý ────────────────────────────────────────────────────
 * „TUZEMSKÉ PLNĚNÍ" je rozhodnutí, ne odbavení. Dojde-li se do něj přesto, že všechny
 * věcné podmínky OSS platí — dodavatel má k datu plnění AKTIVNÍ registraci a odběratel
 * je spotřebitel bez DIČ z jiného členského státu — je to vnitřní rozpor dokladu.
 * Rozhodnutí se NEMĚNÍ (sazbu uvádí sám doklad, registrace do OSS je dobrovolná a plnění
 * pod prahem § 8/3 tuzemské opravdu být může), ale řádek dostane poznámku
 * {@see OssDerivationReason::DomesticRateOnCrossBorderB2c} a s ní varování i příznak
 * k ručnímu posouzení. Bez toho by přeshraniční B2C plnění za tuzemskou sazbu prošlo
 * úplně beze slova — což byl až dosud stav.
 *
 * Nerozhodnuté případy jdou do OSS, ne do tuzemska, kvůli ASYMETRII VIDITELNOSTI CHYBY:
 * chybně označený OSS řádek se objeví v náhledu OSS podání, což je krátký seznam
 * procházený před odesláním, kdežto chybně označený tuzemský řádek zmizí mezi stovkami
 * řádků přiznání k DPH a najde ho až výzva správce daně.
 *
 * ── Datum plnění se kanonizuje, nikdy nehádá ────────────────────────────────────────
 * Nekanonický, ale čitelný tvar ('2096-5-15') se přepíše na 'Y-m-d'. Není to kosmetika:
 * platnost sazby v číselníku i platnost registrace do OSS se porovnávají jako ŘETĚZCE,
 * takže '2096-5-15' by proti '2024-12-31' vyšlo lexikograficky jinak než datumově a
 * číselník by odpověděl na jinou otázku, než jaká byla položena. Co zkanonizovat nejde
 * (prázdno, '15. 7. 2026', '2026-02-30'), je vada DOKLADU — položka se odmítne, protože
 * z nevědomosti o datu se nikdy nesmí stát tuzemské zařazení.
 *
 * ── Tuzemsko není natvrdo 'CZ' ──────────────────────────────────────────────────────
 * OSS je z definice plnění do JINÉHO členského státu, než ve kterém je dodavatel
 * identifikovaný. „Tuzemsko" se proto bere ze země dodavatele
 * ({@see domesticCountry()}); zadrátovaná 'CZ' by nasazení mimo ČR tiše rozbila a
 * u importu by se rozešla s tím, proti čemu se páruje `vat_rate_id`.
 *
 * ── Typ sazby se nedomýšlí ──────────────────────────────────────────────────────────
 * Do podání jde TYP sazby, ne procento ({@see OssXmlExporter::rateTypeCode()}), takže
 * „když nevíme, dej standard" není výchozí hodnota, ale nesprávně odvedená daň. Nezjištěný
 * typ zůstává `null` a systém na něj reaguje dál po trase: náhled přiznání varuje,
 * export XML takový řádek do podání nepustí.
 *
 * ── Vysvětlitelnost místo tichého nastavení ─────────────────────────────────────────
 * Každé rozhodnutí vrací strojově čitelný důvod ({@see OssDerivationReason}), aby import
 * uměl napsat report a backfill log. Pořadí testů v {@see blockingReason()} je závazné
 * a zvolené tak, aby report dal na celý balík JEDNU vysvětlující větu („firma nemá zapnutý
 * OSS") místo 850 řádkových.
 *
 * ── Chybějící číselník je chyba běhu, ne stav k tolerování ──────────────────────────
 * Po zavedení totality výše odmítne chybějící tabulka `oss_member_state_rates` KAŽDOU
 * položku se sazbou > 0. Ošetřit se to musí O ÚROVEŇ VÝŠ, jednou hlasitou chybou na
 * začátku běhu importu („spusťte php api/bin/migrate.php"), ne shovívavostí tady —
 * shovívavost je právě to, čím cizí daň unikala na ř. 1. Deriver proto zůstává přísný
 * a odpověď „nevím" nikdy nevydává za „ano, tuzemsko".
 *
 * Beze změny schématu použitelný i na starší instanci: chybí-li sloupce migrace 0137,
 * vrací se `OssSchemaMissing` (stejný `hasColumn()` pattern jako zbytek OSS kódu).
 */
final class OssItemDeriver
{
    /**
     * Tolerance porovnání sazby v procentních bodech (DECIMAL(5,2) vs. float).
     * Shodná s `OssRateCodebook::EPSILON`; ta je private, takže se nedá odkázat —
     * při změně jedné je nutné změnit i druhou.
     */
    public const EPSILON = 0.005;

    /** Číselník zemi zná a sazba mezi jejími sazbami k datu plnění je. */
    private const RATE_APPLIES = 1;
    /** Číselník zemi zná a sazba mezi jejími sazbami NENÍ — tvrdá záporná odpověď. */
    private const RATE_DOES_NOT_APPLY = 0;
    /** Číselníku se nedalo zeptat (chybí migrace 1152, zemi k datu nezná, chybí datum). */
    private const RATE_UNVERIFIABLE = -1;

    /** @var array<int, array<string,mixed>> Nastavení dodavatele per supplierId. */
    private array $supplierCache = [];

    /** @var array<int, OssClientContext> Uložený klient, bez údajů z dokladu. */
    private array $clientCache = [];

    /** @var array<string, bool> Klíč = ISO2 státu. */
    private array $euCache = [];

    /** @var array<string, list<array{rate_type:string, rate_percent:float}>> Klíč "CC|Y-m-d". */
    private array $codebookCache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly OssRateCodebook $codebook,
    ) {}

    /**
     * @param float   $ratePercent         sazba DPH v procentech (23.0, ne 0.23)
     * @param ?string $unit                měrná jednotka položky; null/'' = bez signálu
     * @param string  $taxDate             EFEKTIVNÍ datum plnění; přijímá se 'Y-m-d' i
     *                                     nekanonický, ale čitelný tvar ('2026-7-1'), který
     *                                     se zkanonizuje. Fallback DUZP → datum vystavení
     *                                     řeší volající, deriver nehádá
     * @param bool    $headerReverseCharge `invoices.reverse_charge` dokladu
     */
    public function derive(
        int $supplierId,
        OssClientContext $client,
        float $ratePercent,
        ?string $unit,
        string $taxDate,
        bool $headerReverseCharge,
    ): OssItemDecision {
        $supplier = $this->supplierSettings($supplierId);
        $domesticCountry = $supplier['country_iso2'];
        $origin = $client->countryFromDocument ? [OssDerivationReason::ClientCountryFromDocument] : [];

        $canonicalDate = self::canonicalDate($taxDate);
        if ($canonicalDate === null) {
            // Bez použitelného data se číselníku nedá položit ANI JEDNA otázka. Dřív se
            // z toho stalo tuzemské plnění, což byl druhý reprodukovaný únik: stačilo
            // datum bez vodicích nul a cizí sazba obešla invariant ještě dřív, než se
            // vůbec vyhodnotil. Nevědomost o datu je odpověď „NEVÍM", ne „ano, tuzemsko".
            // Bez data se nedá vyhodnotit ani platnost registrace do OSS, takže se
            // kvadrant tuzemského plnění nemá čím zpochybnit — kontradikce je `null`.
            return $this->domesticOrReject(
                OssDerivationReason::MissingTaxDate,
                $domesticCountry,
                $ratePercent,
                null,
                $origin,
                null,
            );
        }
        $taxDate = $canonicalDate;

        // Zpochybnění tuzemského kvadrantu se počítá PŘED blokujícími důvody, protože
        // musí platit na obou cestách, kterými se řádek stane tuzemským: přes blokující
        // důvod (řádek OSS být nemůže) i přes rozhodovací tabulku níž.
        $contradiction = $this->crossBorderB2cContradiction($supplier, $client, $ratePercent, $taxDate);

        $blocker = $this->blockingReason($supplier, $client, $ratePercent, $taxDate, $headerReverseCharge);
        if ($blocker !== null) {
            return $this->domesticOrReject(
                $blocker,
                $domesticCountry,
                $ratePercent,
                $taxDate,
                $origin,
                $contradiction,
            );
        }

        $country = (string) OssClientContext::iso2OrNull($client->countryIso2);

        $rateNotes = [];
        $consumerRateType = null;
        $consumer = $this->consumerKnowledge($country, $ratePercent, $taxDate, $rateNotes, $consumerRateType);
        $domestic = $this->domesticKnowledge($domesticCountry, $ratePercent, $taxDate, $rateNotes);

        // Jediný tuzemský kvadrant tabulky: země dodavatele POTVRZENA a stát spotřeby
        // sazbu vylučuje. Poznámky o číselníku se sem nepřenášejí — o nevědomosti tu
        // není co psát: obě strany odpověděly, jinak by se řádek do téhle větve nedostal.
        //
        // Přenáší se ale zpochybnění kvadrantu: sem se dojde jedině tehdy, když
        // `blockingReason()` nic nenašla, tzn. firma má k datu plnění AKTIVNÍ registraci
        // do OSS a odběratel je spotřebitel bez DIČ z jiného členského státu. Tuzemská
        // sazba je za těch podmínek vnitřní rozpor dokladu — rozhodnutí se nemění (sazbu
        // uvádí doklad, registrace je dobrovolná a plnění pod prahem § 8/3 tuzemské být
        // může), ale tichý být nesmí. Právě tenhle kvadrant byl do teď úplně němý.
        if ($domestic === self::RATE_APPLIES && $consumer === self::RATE_DOES_NOT_APPLY) {
            return OssItemDecision::notApplicable(
                OssDerivationReason::RateMatchesDomesticOnly,
                $contradiction !== null ? [...$origin, $contradiction] : $origin,
            );
        }

        $reason = match (true) {
            // Tuzemsko sazbu vylučuje → místo plnění je určené, i kdyby stát spotřeby mlčel.
            $domestic === self::RATE_DOES_NOT_APPLY => OssDerivationReason::B2cEuConsumer,
            // Obě strany říkají „platí u mě" → z procenta se místo plnění určit nedá.
            $domestic === self::RATE_APPLIES && $consumer === self::RATE_APPLIES
                => OssDerivationReason::RateAmbiguousDomesticOrConsumer,
            // Zbytek: některá strana neodpověděla. Nevědomost není důkaz, že sazba není
            // tuzemská — ale ani že je; řádek jde do OSS a člověku pod ruku.
            default => OssDerivationReason::RateOriginUnverifiable,
        };

        $notes = [...$origin, ...$rateNotes];
        if ($consumerRateType === null) {
            $notes[] = OssDerivationReason::RateTypeUnknown;
        }
        $supplyType = $this->deriveSupplyType($unit, (string) ($supplier['cz_nace_code'] ?? ''), $notes);

        return OssItemDecision::oss($country, $consumerRateType, $supplyType, $notes, $reason);
    }

    /**
     * Kontext odběratele.
     *
     * `$documentClient` je klient tak, jak ho nese IMPORTOVANÝ DOKLAD (`country_iso2`,
     * `dic` z parseru). Má přednost před uloženým klientem, protože `ClientResolver`
     * ukládá zemi s fallbackem `'CZ'` a `ClientRepository::countryIdFromIso2()` na neznámé
     * ISO odpovídá rovněž Českem — uložený klient tedy umí tvrdit „tuzemsko" i tam, kde
     * doklad žádnou zemi nenesl. Bez téhle přednosti by cizí sazba skončila na ř. 1
     * tuzemského přiznání a oprava by musela sáhnout do `ClientResolveru`, který je
     * sdílený se všemi ostatními importy.
     *
     * DIČ se naopak bere doplňkově (doklad, jinak uložený klient): chybějící DIČ na
     * dokladu je běžná neúplnost exportu, kdežto DIČ u uloženého klienta je tvrdý signál
     * B2B, který OSS vylučuje. Širší odpověď tu znamená méně OSS řádků — což od zavedení
     * invariantu proti úniku neznamená víc tuzemských řádků, ale víc odmítnutých položek
     * s hláškou.
     *
     * Uložená část se cachuje v rámci instance — import 850 dokladů nesmí dělat 850 dotazů.
     *
     * @param ?array<string,mixed> $documentClient tvar `$inv['client']` z parseru importu
     */
    public function clientContext(int $clientId, ?array $documentClient = null): OssClientContext
    {
        $stored = $this->storedClientContext($clientId);
        if ($documentClient === null) {
            return $stored;
        }

        $docDic = trim((string) ($documentClient['dic'] ?? ''));
        $dic = $docDic !== '' ? $docDic : $stored->dic;

        $docCountry = OssClientContext::iso2OrNull($documentClient['country_iso2'] ?? $documentClient['iso2'] ?? null);
        if ($docCountry === null) {
            return new OssClientContext($stored->countryIso2, $stored->isEu, $dic);
        }

        return new OssClientContext($docCountry, $this->isEuCountry($docCountry), $dic, true);
    }

    /**
     * Tuzemsko pro daného dodavatele. Veřejné schválně: import páruje `vat_rate_id`
     * tuzemského řádku proti TÉŽE zemi, ze které deriver bere odpověď „platí tahle sazba
     * v zemi dodavatele". Vlastní konstanta `DOMESTIC_COUNTRY = 'CZ'` na straně volajícího
     * by obě strany rozešla přesně u dodavatele identifikovaného mimo ČR.
     */
    public function domesticCountry(int $supplierId): string
    {
        return $this->supplierSettings($supplierId)['country_iso2'];
    }

    /**
     * První důvod, proč řádek OSS být NEMŮŽE, nebo `null`, když projde až k rozhodovací
     * tabulce. Pořadí je závazné: hlavičkový příznak je explicitní rozhodnutí uživatele,
     * takže se čte dřív než odvozené DIČ odběratele — v reportu se to líp vysvětluje.
     *
     * @param array{oss_enabled:bool, oss_valid_from:?string, oss_valid_to:?string,
     *              cz_nace_code:?string, country_iso2:string} $supplier
     */
    private function blockingReason(
        array $supplier,
        OssClientContext $client,
        float $ratePercent,
        string $taxDate,
        bool $headerReverseCharge,
    ): ?OssDerivationReason {
        if (!$this->hasOssSchema()) {
            return OssDerivationReason::OssSchemaMissing;
        }
        if (!$supplier['oss_enabled']) {
            return OssDerivationReason::SupplierOssDisabled;
        }

        if (!self::registrationActiveOn($supplier, $taxDate)) {
            return OssDerivationReason::SupplierOssNotValidOnDate;
        }

        if ($headerReverseCharge) {
            return OssDerivationReason::HeaderReverseCharge;
        }

        $country = OssClientContext::iso2OrNull($client->countryIso2);
        if ($country === null) {
            return OssDerivationReason::ClientCountryUnknown;
        }
        if ($country === $supplier['country_iso2']) {
            return OssDerivationReason::ClientDomestic;
        }
        if (!$client->isEu) {
            return OssDerivationReason::ClientNotEu;
        }
        if ($client->hasVatId()) {
            return OssDerivationReason::ClientHasVatId;
        }
        // Osvobozené / RC / vývozní plnění řeší tuzemská klasifikace ('20', '22', '26');
        // OSS je vždy plnění zdaněné sazbou státu spotřeby.
        if ($ratePercent <= self::EPSILON) {
            return OssDerivationReason::ZeroRate;
        }

        return null;
    }

    /**
     * Má dodavatel k datu plnění AKTIVNÍ registraci do OSS? Sdílené
     * {@see blockingReason()} a {@see crossBorderB2cContradiction()} — dvě kopie téhle
     * podmínky by se rozešly a jedna větev by pak mluvila o jiné registraci než druhá.
     *
     * @param array{oss_enabled:bool, oss_valid_from:?string, oss_valid_to:?string} $supplier
     */
    private static function registrationActiveOn(array $supplier, string $taxDate): bool
    {
        if (!$supplier['oss_enabled']) {
            return false;
        }
        $validFrom = $supplier['oss_valid_from'];
        $validTo = $supplier['oss_valid_to'];

        return !(($validFrom !== null && $taxDate < $validFrom) || ($validTo !== null && $taxDate > $validTo));
    }

    /**
     * Poznámka pro případ, že řádek skončí jako TUZEMSKÉ plnění, přestože všechny věcné
     * podmínky OSS platí: firma má k datu plnění aktivní registraci a odběratel je
     * spotřebitel BEZ DIČ z JINÉHO členského státu, než ve kterém je dodavatel
     * identifikovaný. `null` = žádný rozpor, o kterém by mělo smysl psát.
     *
     * Podmínka je schválně úzká a testuje se celá, aby nevzniklo varování na každém
     * tuzemském dokladu: český odběratel padá na `$country === země dodavatele`,
     * B2B na DIČ, třetí země na `isEu`, nulová sazba na prahu (osvobození, RC a vývoz
     * o místě plnění nevypovídají) a firma bez registrace na `registrationActiveOn()`.
     * Zbývá právě ten kvadrant, který review naměřila jako úplně tichý.
     *
     * NEJDE o rozhodnutí ani o jeho revizi — místo plnění zůstává tuzemsko. Volající
     * poznámku připojí VÝHRADNĚ k tuzemskému výsledku; u OSS řádku by lhala a
     * u odmítnuté položky by přebila hlášku, která říká, co doplnit.
     *
     * @param array{oss_enabled:bool, oss_valid_from:?string, oss_valid_to:?string,
     *              cz_nace_code:?string, country_iso2:string} $supplier
     */
    private function crossBorderB2cContradiction(
        array $supplier,
        OssClientContext $client,
        float $ratePercent,
        string $taxDate,
    ): ?OssDerivationReason {
        if (!$this->hasOssSchema() || !self::registrationActiveOn($supplier, $taxDate)) {
            return null;
        }
        // Nulová sazba je z rozporu vyňatá ze stejného důvodu jako z invariantu proti
        // úniku: osvobození, přenesená daňová povinnost i vývoz se vykazují BEZ DANĚ,
        // takže tam žádná „tuzemská sazba" na přeshraničním plnění není.
        if ($ratePercent <= self::EPSILON) {
            return null;
        }

        $country = OssClientContext::iso2OrNull($client->countryIso2);
        if ($country === null || $country === $supplier['country_iso2'] || !$client->isEu || $client->hasVatId()) {
            return null;
        }

        return OssDerivationReason::DomesticRateOnCrossBorderB2c;
    }

    /**
     * Levý sloupec rozhodovací tabulky: řádek OSS být nemůže, takže se ptáme jediné
     * otázky, která smí vpustit do tuzemské větve — „potvrdil číselník sazbu v zemi
     * dodavatele k datu plnění?". POUZE odpověď PLATÍ znamená tuzemské plnění; NEPLATÍ
     * i NEVÍM (včetně chybějícího nebo nečitelného data, kdy `$taxDate` je `null`)
     * znamenají odmítnutí položky.
     *
     * @param ?string                   $taxDate       `null` = datum nešlo zkanonizovat,
     *                                                 otázku tedy nelze ani položit
     * @param list<OssDerivationReason> $origin
     * @param ?OssDerivationReason      $contradiction poznámka z
     *                                  {@see crossBorderB2cContradiction()}; připojí se
     *                                  VÝHRADNĚ k tuzemskému výsledku — u odmítnutí by
     *                                  přebila hlášku, která říká, co konkrétně doplnit
     */
    private function domesticOrReject(
        OssDerivationReason $reason,
        string $domesticCountry,
        float $ratePercent,
        ?string $taxDate,
        array $origin,
        ?OssDerivationReason $contradiction,
    ): OssItemDecision {
        // Nulová sazba nemá co unikat: osvobození, přenesená daňová povinnost i vývoz
        // se vykazují bez daně, takže z nich cizí daň do tuzemského přiznání nespadne.
        // Číselník členských států navíc nulové sazby nevede, takže by vynucení
        // invariantu odmítlo každé osvobozené plnění (viz docblock třídy).
        if ($ratePercent <= self::EPSILON) {
            return OssItemDecision::notApplicable($reason, $origin);
        }

        $notes = $origin;
        $knowledge = $taxDate === null
            ? self::RATE_UNVERIFIABLE
            : $this->domesticKnowledge($domesticCountry, $ratePercent, $taxDate, $notes);

        if ($knowledge === self::RATE_APPLIES) {
            // Tuzemský výsledek přes blokující důvod. Jediný blokující důvod, který se
            // s aktivní registrací a spotřebitelem bez DIČ z JČS potká, je přenesená
            // daňová povinnost na hlavičce — a ta si na takovém dokladu o zpochybnění
            // říká stejně naléhavě jako sazba samotná.
            if ($contradiction !== null) {
                self::addNote($notes, $contradiction);
            }

            return OssItemDecision::notApplicable($reason, $notes);
        }

        return OssItemDecision::rejected(
            $reason,
            self::rejectionMessage($knowledge, $reason, $domesticCountry, $ratePercent, $taxDate, $notes),
            $notes,
        );
    }

    /**
     * Hláška odmítnutí. První věta říká, PROČ řádek nemůže být tuzemský, druhá co s tím.
     *
     * Rozlišit „číselník sazbu vyloučil" od „číselník na to neumí odpovědět" je tu
     * podstatné: v prvním případě má uživatel opravit doklad, ve druhém spustit migraci.
     * Sloučení obou do jedné věty je táž chyba, kvůli které zákazník z analýzy hledal
     * chybějící PL/HU/SK v datech místo v neproběhlé migraci. Ze stejného důvodu se
     * u chybějícího číselníku nepoužije rada navázaná na důvod („zapněte OSS") — ta by
     * uživatele poslala úplně jinam, než kde je příčina.
     *
     * @param self::RATE_*              $knowledge
     * @param list<OssDerivationReason> $notes poznámky, které o číselníku posbírala
     *                                        {@see domesticKnowledge()}
     */
    private static function rejectionMessage(
        int $knowledge,
        OssDerivationReason $reason,
        string $domesticCountry,
        float $ratePercent,
        ?string $taxDate,
        array $notes,
    ): string {
        $percent = self::fmtPercent($ratePercent);
        $where = $taxDate === null
            ? sprintf('v zemi dodavatele (%s)', $domesticCountry)
            : sprintf('v zemi dodavatele (%s) k %s', $domesticCountry, self::fmtDate($taxDate));
        $codebookMissing = in_array(OssDerivationReason::CodebookUnavailable, $notes, true);

        $why = match (true) {
            $knowledge === self::RATE_DOES_NOT_APPLY => sprintf(
                'Sazba %s %% podle číselníku sazeb členských států %s neplatí',
                $percent,
                $where,
            ),
            $taxDate === null => sprintf(
                'Sazbu %s %% nelze bez použitelného data plnění ověřit %s',
                $percent,
                $where,
            ),
            $codebookMissing => sprintf(
                'Sazbu %s %% nelze ověřit %s — chybí číselník sazeb členských států (migrace 1152)',
                $percent,
                $where,
            ),
            default => sprintf(
                'Sazbu %s %% se nepodařilo ověřit %s — číselník sazeb členských států tuhle zemi k datu plnění nevede',
                $percent,
                $where,
            ),
        };

        return sprintf(
            '%s, takže řádek nemůže být tuzemské plnění — ale do OSS ho zařadit nelze: %s.',
            $why,
            $codebookMissing
                ? 'spusťte php api/bin/migrate.php a import opakujte'
                : $reason->rejectionRemedy(),
        );
    }

    /**
     * Zná stát spotřeby tuhle sazbu, a jak se u něj jmenuje její typ?
     *
     * Typ se NIKDY neodhaduje — viz {@see OssItemDecision} a doktrína „číselník varuje,
     * neblokuje" (migrace 1152).
     *
     * @param list<OssDerivationReason> $notes
     * @return self::RATE_*
     */
    private function consumerKnowledge(
        string $country,
        float $ratePercent,
        string $taxDate,
        array &$notes,
        ?string &$rateType,
    ): int {
        $rateType = null;

        if (!$this->codebook->isAvailable()) {
            // Odlišeno od „stát v číselníku není" schválně: sloučení obou stavů je
            // původní zavádějící hláška, kvůli které uživatel hledal chybu v datech
            // místo v neproběhlé migraci.
            self::addNote($notes, OssDerivationReason::CodebookUnavailable);
            return self::RATE_UNVERIFIABLE;
        }

        $rates = $this->ratesFor($country, $taxDate);
        if ($rates === []) {
            self::addNote($notes, OssDerivationReason::ConsumerCountryNotInCodebook);
            return self::RATE_UNVERIFIABLE;
        }

        foreach ($rates as $rate) {
            if (abs($rate['rate_percent'] - $ratePercent) > self::EPSILON) {
                continue;
            }
            // Typ mimo naši čtveřici by musel přijít z ručně upraveného číselníku (ENUM ho
            // jinak nepustí). Nepřepisuje se na „standard": neznámý typ je vada PODÁNÍ,
            // kterou zastaví export XML — ne důkaz o místě plnění. Sazba ve státě spotřeby
            // platí tak jako tak, takže odpověď je kladná a prázdný zůstane jen typ.
            if (in_array($rate['rate_type'], OssItemDecision::RATE_TYPES, true)) {
                $rateType = $rate['rate_type'];
                self::addNote($notes, OssDerivationReason::RateTypeFromCodebook);
            }

            return self::RATE_APPLIES;
        }

        self::addNote($notes, OssDerivationReason::RateUnknownInConsumerCountry);

        return self::RATE_DOES_NOT_APPLY;
    }

    /**
     * Platí tahle sazba v zemi DODAVATELE? Ptá se TÉHOŽ číselníku jako
     * {@see consumerKnowledge()} — symetrie je tu podstatná: obě strany rozhodnutí o místě
     * plnění musí stát na stejném a uživatelem nemanipulovatelném podkladu. Dotaz nad
     * `vat_rates` by tuhle symetrii rozbil (viz docblock třídy).
     *
     * Záporná odpověď se ZÁMĚRNĚ neoznačuje jako varování: „sazba není tuzemská" je
     * u přeshraničního B2C plnění normální stav, ne podezření.
     *
     * @param list<OssDerivationReason> $notes
     * @return self::RATE_*
     */
    private function domesticKnowledge(string $country, float $ratePercent, string $taxDate, array &$notes): int
    {
        if (!$this->codebook->isAvailable()) {
            self::addNote($notes, OssDerivationReason::CodebookUnavailable);
            return self::RATE_UNVERIFIABLE;
        }

        $rates = $this->ratesFor($country, $taxDate);
        if ($rates === []) {
            // Typicky doklad staršího data, než kam sahá seed. Tvrdit z toho „sazba není
            // tuzemská" nejde, ale ani opak: je to odpověď NEVÍM, se kterou se řádek do
            // tuzemské větve nedostane (migrace 1294 proto seed dotahuje do historie).
            self::addNote($notes, OssDerivationReason::DomesticRatesNotInCodebook);
            return self::RATE_UNVERIFIABLE;
        }

        foreach ($rates as $rate) {
            if (abs($rate['rate_percent'] - $ratePercent) <= self::EPSILON) {
                return self::RATE_APPLIES;
            }
        }

        return self::RATE_DOES_NOT_APPLY;
    }

    /**
     * Zboží vs. služba třístupňovým žebříkem, celý ze SDÍLENÉ existující logiky — žádná
     * nová heuristika, aby se OSS nerozešlo s klasifikací tuzemských plnění.
     *
     * @param list<OssDerivationReason> $notes
     */
    private function deriveSupplyType(?string $unit, string $nace, array &$notes): string
    {
        $fromUnit = VatClassificationDefaulter::classifyUnitsGoodsVsServices([(string) ($unit ?? '')]);
        if ($fromUnit !== null) {
            $notes[] = OssDerivationReason::SupplyTypeFromUnit;
            return $fromUnit;
        }

        // 'ks' i neznámá jednotka jsou bez signálu — rozhodne převažující činnost
        // dodavatele, což je signál, který systém pro tutéž nejednoznačnost už používá.
        if (trim($nace) !== '') {
            $notes[] = OssDerivationReason::SupplyTypeFromSupplierNace;
            return VatClassificationDefaulter::naceIsGoods($nace) ? 'goods' : 'services';
        }

        $notes[] = OssDerivationReason::SupplyTypeDefaultServices;
        return 'services';
    }

    private function storedClientContext(int $clientId): OssClientContext
    {
        if (isset($this->clientCache[$clientId])) {
            return $this->clientCache[$clientId];
        }
        if ($clientId <= 0) {
            return $this->clientCache[$clientId] = new OssClientContext(null, false, null);
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(UPPER(TRIM(co.iso2)), '') AS country_iso2,
                    COALESCE(co.is_eu, 0) AS is_eu,
                    c.dic
               FROM clients c
          LEFT JOIN countries co ON co.id = c.country_id
              WHERE c.id = ?
              LIMIT 1"
        );
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $this->clientCache[$clientId] = $row === false
            ? new OssClientContext(null, false, null)
            : OssClientContext::fromArray($row);
    }

    /** Členství v EU pro zemi z dokladu — uložený klient ho nese, doklad jen ISO kód. */
    private function isEuCountry(string $iso2): bool
    {
        if (isset($this->euCache[$iso2])) {
            return $this->euCache[$iso2];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT is_eu FROM countries WHERE UPPER(TRIM(iso2)) = ? LIMIT 1'
        );
        $stmt->execute([$iso2]);
        $value = $stmt->fetchColumn();

        return $this->euCache[$iso2] = $value !== false && (bool) $value;
    }

    /** @return list<array{rate_type:string, rate_percent:float}> */
    private function ratesFor(string $country, string $onDate): array
    {
        return $this->codebookCache[$country . '|' . $onDate] ??= $this->codebook->ratesFor($country, $onDate);
    }

    /**
     * @return array{oss_enabled:bool, oss_valid_from:?string, oss_valid_to:?string,
     *               cz_nace_code:?string, country_iso2:string}
     */
    private function supplierSettings(int $supplierId): array
    {
        if (isset($this->supplierCache[$supplierId])) {
            return $this->supplierCache[$supplierId];
        }

        $empty = [
            'oss_enabled' => false,
            'oss_valid_from' => null,
            'oss_valid_to' => null,
            'cz_nace_code' => null,
            'country_iso2' => 'CZ',
        ];
        if (!$this->db->hasColumn('supplier', 'oss_enabled')) {
            return $this->supplierCache[$supplierId] = $empty;
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT s.oss_enabled, s.oss_valid_from, s.oss_valid_to, s.cz_nace_code,
                    s.oss_identification_country,
                    COALESCE(UPPER(TRIM(co.iso2)), '') AS supplier_country
               FROM supplier s
          LEFT JOIN countries co ON co.id = s.country_id
              WHERE s.id = ?
              LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return $this->supplierCache[$supplierId] = $empty;
        }

        return $this->supplierCache[$supplierId] = [
            'oss_enabled' => (bool) ($row['oss_enabled'] ?? false),
            'oss_valid_from' => self::canonicalDate($row['oss_valid_from'] ?? null),
            'oss_valid_to' => self::canonicalDate($row['oss_valid_to'] ?? null),
            'cz_nace_code' => $row['cz_nace_code'] ?? null,
            'country_iso2' => OssClientContext::iso2OrNull($row['supplier_country'] ?? null)
                ?? OssClientContext::iso2OrNull($row['oss_identification_country'] ?? null)
                ?? 'CZ',
        ];
    }

    private function hasOssSchema(): bool
    {
        return $this->db->hasColumn('invoice_items', 'oss_applicable')
            && $this->db->hasColumn('supplier', 'oss_enabled');
    }

    /**
     * Obě strany rozhodovací tabulky se ptají téhož číselníku, takže „chybí migrace 1152"
     * by se do poznámek přidalo dvakrát a report by tutéž větu zopakoval.
     *
     * @param list<OssDerivationReason> $notes
     */
    private static function addNote(array &$notes, OssDerivationReason $note): void
    {
        if (!in_array($note, $notes, true)) {
            $notes[] = $note;
        }
    }

    /**
     * Datum na kanonický 'Y-m-d', nebo `null`, když to není čitelné datum.
     *
     * Tolerují se chybějící vodicí nuly ('2096-5-15'), protože to je pořád jednoznačné
     * datum — jen v tvaru, ve kterém by porovnání řetězců proti `valid_from`/`valid_to`
     * dalo jinou odpověď než porovnání dat. Neexistující den ('2026-02-30') ani jiný
     * formát ('15. 7. 2026') se NEDOMÝŠLÍ: `null` je odpověď „nevím", se kterou se řádek
     * do tuzemské větve nedostane.
     *
     * Veřejná schválně: TÝŽ tvar data potřebuje normalizovat i vrstva nad derivací
     * (import normalizuje datum vystavení / DUZP / splatnost na hranici, dřív než se
     * o dokladu cokoli rozhodne). Kdyby si pravidlo napsala znovu, obě kopie se rozejdou
     * a doklad projde s datem, na které deriver odpoví jinak než zápis do DB.
     */
    public static function canonicalDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) !== 1) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }

    /** Formát procenta a data pro hlášku odmítnutí — shodný s `VatRateResolver`. */
    private static function fmtPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, ',', ' '), '0'), ',');
    }

    private static function fmtDate(string $date): string
    {
        try {
            return (new \DateTimeImmutable($date))->format('j. n. Y');
        } catch (\Exception) {
            return $date;
        }
    }
}
