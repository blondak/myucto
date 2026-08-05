<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Oss;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Oss\OssClientContext;
use MyInvoice\Service\Oss\OssDerivationReason;
use MyInvoice\Service\Oss\OssItemDecision;
use MyInvoice\Service\Oss\OssItemDeriver;
use MyInvoice\Service\Oss\OssRateCodebook;
use MyInvoice\Service\Vat\VatRateResolver;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Automatické odvození OSS pro řádek dokladu.
 *
 * Test vznikl z nálezu, kvůli kterému se importované zahraniční doklady vykázaly
 * v ČESKÉM přiznání k DPH na ř. 1: import OSS pole vůbec nezapisoval, takže řádek
 * s polskou sazbou 23 % zůstal s `oss_applicable = 0` a `VatLedgerService` ho do
 * přiznání pustil. Deriver je jediná odpověď na otázku „je tenhle řádek OSS";
 * pokud se rozejde s tím, co pak filtrují výkazy, vrátí se přesně ta chyba.
 *
 * Testuje se proti in-memory SQLite (stejný vzor jako `DphBookBuilderTest`) —
 * rozhodovací pravidla musí být ověřitelná bez ostré DB, protože jejich pořadí je
 * závazné a měnitelné jedním řádkem.
 */
final class OssItemDeriverTest extends TestCase
{
    private PDO $pdo;
    private Connection $conn;
    private OssItemDeriver $deriver;

    /** Dodavatel identifikovaný v ČR, OSS zapnutý od 1. 1. 2026 bez konce, bez CZ-NACE. */
    private const SUP_CZ = 1;
    /** Dodavatel bez zapnutého OSS. */
    private const SUP_NO_OSS = 2;
    /** Dodavatel identifikovaný na Slovensku — kontrola, že „tuzemsko" není zadrátované 'CZ'. */
    private const SUP_SK = 3;
    /** Dodavatel s OHRANIČENOU platností registrace 1. 6. – 31. 12. 2026. */
    private const SUP_BOUNDED = 4;
    /** Dodavatel s CZ-NACE 47.11 (maloobchod = zboží). */
    private const SUP_NACE_GOODS = 5;
    /** Dodavatel identifikovaný v HU — stát, který v číselníku členských států SCHVÁLNĚ není. */
    private const SUP_HU = 6;

    private const CLI_PL = 1;
    private const CLI_PL_VAT = 2;
    private const CLI_CZ = 3;
    private const CLI_US = 4;
    private const CLI_GB = 5;
    private const CLI_NL = 6;
    private const CLI_ES = 7;
    private const CLI_NO_COUNTRY = 8;
    private const CLI_SK = 9;
    /** EU stát, který v číselníku sazeb členských států SCHVÁLNĚ není. */
    private const CLI_HU = 10;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->seed();

        $this->conn = new Connection($this->createStub(Config::class));
        (new \ReflectionClass($this->conn))->getProperty('pdo')->setValue($this->conn, $this->pdo);
        $this->deriver = $this->newDeriver();
    }

    /**
     * Deriver ZÁMĚRNĚ nedostává `VatRateResolver`: `vat_rates` je uživatelsky editovatelný
     * číselník sazeb pro doklad a nesmí sloužit jako důkaz o místě plnění. Autoritou je
     * jedině číselník sazeb členských států, který uživatel needituje.
     */
    private function newDeriver(): OssItemDeriver
    {
        return new OssItemDeriver($this->conn, new OssRateCodebook($this->conn));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Hlavní scénář: polský spotřebitel bez DIČ
    // ─────────────────────────────────────────────────────────────────────────

    public function testPolishConsumerWithPolishRateIsOss(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_PL),
            23.0,
            'kg',
            '2026-07-15',
            false,
        );

        self::assertTrue($decision->applicable);
        self::assertSame('PL', $decision->consumerCountry);
        self::assertSame('standard', $decision->rateType);
        self::assertSame('goods', $decision->supplyType, 'jednotka kg je signál zboží');
        self::assertSame(OssDerivationReason::B2cEuConsumer, $decision->reason);
        self::assertContains(OssDerivationReason::RateTypeFromCodebook, $decision->notes);
        self::assertContains(OssDerivationReason::SupplyTypeFromUnit, $decision->notes);
        self::assertSame([], $decision->toReport()['warnings'], 'potvrzená sazba nemá co varovat');
        self::assertSame(
            [
                'oss_applicable' => 1,
                'oss_consumer_country' => 'PL',
                'oss_rate_type' => 'standard',
                'oss_supply_type' => 'goods',
                'oss_needs_manual_review' => 0,
            ],
            $decision->toItemColumns(),
        );
    }

    /**
     * KRITICKÝ NÁLEZ: tuzemskost sazby se NESMÍ ptát `vat_rates`.
     *
     * Tabulka je uživatelsky editovatelný GLOBÁLNÍ číselník a formulář v Nastavení →
     * Sazby DPH má zemi předvyplněnou na CZ. Zákazník z analýzy si tam proto založil 23%
     * sazbu se zemí **CZ**. Dotaz „zná ČR 23 %" nad `vat_rates` by vrátil ANO, polský
     * řádek by spadl do nejednoznačnosti, zůstal tuzemský, dostal klasifikaci '1'
     * a skončil na ř. 1 českého přiznání — přesně původní chyba, pro přesně tu konfiguraci,
     * kterou zákazník má. Autoritou je jedině číselník sazeb členských států, kde ČR
     * k 2026 zná 21 % a 12 %, nikoli 23 %.
     */
    public function testDomesticKnowledgeIgnoresUserEditableVatRates(): void
    {
        self::assertTrue(
            (new VatRateResolver($this->conn))->resolve('CZ', 23.0, '2026-07-15')->found(),
            'fixture musí obsahovat uživatelskou 23% sazbu se zemí CZ, jinak test nic nehlídá',
        );

        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_PL),
            23.0,
            'kg',
            '2026-07-15',
            false,
        );

        self::assertTrue($decision->applicable, 'sazba z vat_rates nesmí udělat z OSS řádku tuzemský');
        self::assertSame(OssDerivationReason::B2cEuConsumer, $decision->reason, 'a ani nejednoznačný');
        self::assertFalse($decision->needsManualReview());
    }

    /**
     * Pravidlo 12: OSS řádek NESMÍ nést tuzemský kód plnění. Kdyby ho nesl, stačí
     * kdekoli zhasnout `oss_applicable` (bulk edit, storno, reissue) a polská daň
     * se objeví na ř. 1 českého přiznání.
     */
    public function testOssRowCarriesNoDomesticClassificationCode(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_PL),
            23.0,
            'ks',
            '2026-07-15',
            false,
        );

        self::assertTrue($decision->applicable);
        self::assertNull($decision->vatClassificationCode);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Rozhodovací tabulka místa plnění
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Kvadrant „zná ji stát spotřeby, tuzemsko ne" — jediný silný signál pro OSS.
     * Sazbu, kterou tuzemská škála nezná, nelze vyložit jako tuzemské plnění.
     */
    public function testRateKnownOnlyInConsumerCountryIsOss(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_PL),
            8.0,
            'ks',
            '2026-07-15',
            false,
        );

        self::assertTrue($decision->applicable);
        self::assertSame('reduced', $decision->rateType);
        self::assertContains(OssDerivationReason::RateTypeFromCodebook, $decision->notes);
    }

    /**
     * Kvadrant „zná ji tuzemsko, stát spotřeby ne" — česká snížená sazba 12 % v Polsku
     * neplatí, takže jde nejspíš o tuzemské plnění zadané na zahraničního odběratele.
     * ROZHODNUTÍ se nemění: řádek zůstává mimo OSS, sazbu uvádí sám doklad, registrace
     * do OSS je dobrovolná a plnění pod prahem § 8/3 tuzemské opravdu být může.
     *
     * TICHÝ ale zůstat nesmí. Tenhle kvadrant byl do téhle vlny úplně němý: přeshraniční
     * B2C plnění za tuzemskou sazbu prošlo při AKTIVNÍ registraci do OSS bez jediného
     * varování, bez poznámky a bez příznaku. Naměřeno na dokladu pro polského
     * spotřebitele bez DIČ se sazbou 21 % — status `created`, nula varování, nula
     * poznámek, nula OSS řádků. Je to vnitřní rozpor dokladu a uživatel se o něm musí
     * dozvědět dřív než z výzvy správce daně.
     */
    public function testDomesticRateOnCrossBorderB2cStaysDomesticButIsFlagged(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_PL),
            12.0,
            'ks',
            '2026-07-15',
            false,
        );

        self::assertFalse($decision->applicable, 'rozhodnutí se nemění — řádek zůstává tuzemský');
        self::assertSame(OssDerivationReason::RateMatchesDomesticOnly, $decision->reason);
        self::assertContains(OssDerivationReason::DomesticRateOnCrossBorderB2c, $decision->notes);
        self::assertTrue($decision->needsManualReview(), 'rozpor nese POZNÁMKA, ne důvod — musí se přesto projevit');

        $report = $decision->toReport();
        self::assertTrue($report['needs_manual_review']);
        self::assertNotSame([], $report['warnings'], 'kvadrant tuzemského plnění nesmí být tichý');
        self::assertStringContainsString('K RUČNÍMU POSOUZENÍ', implode("\n", $report['warnings']));

        // Příznak musí přežít zavření reportu i u TUZEMSKÉHO řádku — jinak je to
        // jednorázová hláška a po zavření stránky ho nikdo nedohledá.
        self::assertSame(1, $decision->toItemColumns()['oss_needs_manual_review']);
        self::assertSame(0, $decision->toItemColumns()['oss_applicable']);
    }

    /**
     * Druhá půlka téhož pravidla: podmínka je ÚZKÁ. Kdyby nebyla, dostal by varování
     * každý běžný tuzemský doklad pro českého odběratele — a report by se tím stal
     * nečitelným přesně u zákazníka s 1 670 doklady, kvůli kterému celá vlna vznikla.
     *
     * @return list<array{0:int, 1:int, 2:float, 3:string}>
     */
    public static function noContradictionCases(): array
    {
        return [
            'tuzemský odběratel' => [self::SUP_CZ, self::CLI_CZ, 21.0, '2026-07-15'],
            'odběratel s DIČ (B2B)' => [self::SUP_CZ, self::CLI_PL_VAT, 12.0, '2026-07-15'],
            'odběratel mimo EU' => [self::SUP_CZ, self::CLI_US, 12.0, '2026-07-15'],
            'firma bez registrace do OSS' => [self::SUP_NO_OSS, self::CLI_PL, 12.0, '2026-07-15'],
            'datum mimo platnost registrace' => [self::SUP_BOUNDED, self::CLI_PL, 12.0, '2026-03-15'],
            'nulová sazba' => [self::SUP_CZ, self::CLI_PL, 0.0, '2026-07-15'],
        ];
    }

    #[DataProvider('noContradictionCases')]
    public function testDomesticRowsWithoutContradictionStaySilent(
        int $supplierId,
        int $clientId,
        float $rate,
        string $taxDate,
    ): void {
        $decision = $this->deriver->derive(
            $supplierId,
            $this->deriver->clientContext($clientId),
            $rate,
            'ks',
            $taxDate,
            false,
        );

        self::assertFalse($decision->applicable);
        self::assertNotContains(OssDerivationReason::DomesticRateOnCrossBorderB2c, $decision->notes);
        self::assertFalse($decision->needsManualReview());
    }

    /**
     * Kvadrant „zná ji obojí" — NL, BE, ES, LT i LV mají 21 % shodně s ČR a Švédsko
     * 12 % shodně s českou sníženou. Sazba tedy místo plnění neurčuje a systém NESMÍ
     * hádat.
     *
     * Nerozhodnutý případ jde do OSS, ne do tuzemska, a to kvůli ASYMETRII VIDITELNOSTI
     * CHYBY: chybně označený OSS řádek se objeví v náhledu OSS podání, což je krátký
     * seznam procházený před odesláním, kdežto chybně označený tuzemský řádek zmizí mezi
     * stovkami řádků přiznání k DPH a najde ho až výzva správce daně. Dřívější podoba
     * rozhodovala opačně a byla to chyba.
     *
     * @return list<array{0:int, 1:float}>
     */
    public static function ambiguousRates(): array
    {
        return [
            'Nizozemsko 21 %' => [self::CLI_NL, 21.0],
            'Španělsko 21 %' => [self::CLI_ES, 21.0],
        ];
    }

    #[DataProvider('ambiguousRates')]
    public function testRateKnownInBothCountriesGoesToOssForManualReview(int $clientId, float $rate): void
    {
        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext($clientId),
            $rate,
            'ks',
            '2026-07-15',
            false,
        );

        self::assertTrue($decision->applicable, 'pochybnost se řeší ve prospěch OSS, ne tuzemska');
        self::assertSame(OssDerivationReason::RateAmbiguousDomesticOrConsumer, $decision->reason);
        self::assertSame('standard', $decision->rateType, 'typ sazby se bere z číselníku STÁTU SPOTŘEBY');
        self::assertTrue($decision->needsManualReview());

        $report = $decision->toReport();
        self::assertTrue($report['needs_manual_review']);
        self::assertNotSame([], $report['warnings'], 'nejednoznačnost musí být v reportu vidět');
        self::assertStringContainsString('K RUČNÍMU POSOUZENÍ', $report['warnings'][0]);
    }

    /**
     * „K ručnímu posouzení" musí přežít zavření reportu importu — u migrace 1 670 dokladů
     * je kategorie, kterou po zavření stránky nikdo nedohledá, k ničemu. Příznak proto
     * patří i mezi sloupce položky.
     */
    public function testManualReviewFlagIsWrittenToTheItem(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_NL),
            21.0,
            'ks',
            '2026-07-15',
            false,
        );

        self::assertSame(1, $decision->toItemColumns()['oss_needs_manual_review']);
    }

    /**
     * Kvadrant „nevím" — číselník na jednu ze stran neumí odpovědět (chybí migrace 1152,
     * stát v něm k datu není). Nevědomost NENÍ odpověď „sazba není tuzemská" ani „je":
     * řádek jde do OSS a člověku pod ruku, stejně jako u nejednoznačnosti.
     *
     * Slovenský dodavatel + český odběratel + 15 % k roku 2019: seed číselníku začíná
     * u obou států později, takže se k tomuhle datu nedá zeptat ani na jednu stranu.
     * Přesně tenhle případ produkuje migrace historických dokladů.
     */
    public function testUnverifiableRateOriginGoesToOssForManualReview(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_SK,
            $this->deriver->clientContext(self::CLI_CZ),
            15.0,
            'ks',
            '2019-06-01',
            false,
        );

        self::assertTrue($decision->applicable);
        self::assertSame(OssDerivationReason::RateOriginUnverifiable, $decision->reason);
        self::assertTrue($decision->needsManualReview());
        self::assertContains(OssDerivationReason::DomesticRatesNotInCodebook, $decision->notes);
        self::assertContains(OssDerivationReason::ConsumerCountryNotInCodebook, $decision->notes);
        self::assertNull($decision->rateType, 'typ sazby se z nevědomosti nedomýšlí');
    }

    /**
     * Kvadrant „o zemi DODAVATELE číselník mlčí, stát spotřeby sazbu zná" — jediný, kde
     * se nevědomost dá snadno zaměnit za odpověď.
     *
     * Maďarský dodavatel: číselník členských států HU nevede (seed migrace 1152 ho nemá),
     * takže na otázku „je 23 % tuzemská sazba" neumí odpovědět. Z toho NESMÍ vzniknout
     * závěr „tuzemská tedy není" a s ním čistý OSS řádek bez příznaku — místo plnění
     * určené není a řádek patří člověku pod ruku. Předchozí podoba se ptala `vat_rates`,
     * kde se prázdná odpověď od záporné nedá odlišit, takže tenhle případ tiše propadl
     * jako jednoznačný.
     */
    public function testUnknownDomesticSideIsNotAnAnswerThatTheRateIsForeign(): void
    {
        self::assertFalse(
            (new VatRateResolver($this->conn))->resolve('HU', 23.0, '2026-07-15')->found(),
            'předpoklad testu: `vat_rates` o maďarských 23 % neví, takže dotaz nad nimi '
                . 'vrací tutéž prázdnou odpověď jako tvrdé NE — a právě tím se dřív '
                . 'nevědomost změnila v jednoznačný OSS řádek',
        );

        $decision = $this->deriver->derive(
            self::SUP_HU,
            $this->deriver->clientContext(self::CLI_PL),
            23.0,
            'ks',
            '2026-07-15',
            false,
        );

        self::assertTrue($decision->applicable, 'nejistota se řeší ve prospěch OSS');
        self::assertSame(
            OssDerivationReason::RateOriginUnverifiable,
            $decision->reason,
            'mlčení číselníku o zemi dodavatele není důkaz, že sazba není tuzemská',
        );
        self::assertTrue($decision->needsManualReview());
        self::assertSame(1, $decision->toItemColumns()['oss_needs_manual_review']);
        self::assertContains(OssDerivationReason::DomesticRatesNotInCodebook, $decision->notes);
        self::assertSame('standard', $decision->rateType, 'stát spotřeby odpovědět umí, typ se z něj vezme');

        // Kontrast na TÉŽE sazbě a TÉMŽE odběrateli: u českého dodavatele číselník
        // odpovědět UMÍ (21 % a 12 %, tedy 23 % ne), takže je řádek OSS jednoznačně
        // a bez příznaku. Rozdíl tedy dělá odpověď o zemi dodavatele, ne procento.
        $known = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_PL),
            23.0,
            'ks',
            '2026-07-15',
            false,
        );
        self::assertSame(OssDerivationReason::B2cEuConsumer, $known->reason);
        self::assertFalse($known->needsManualReview());
    }

    /**
     * Kvadrant „nezná ji nikdo" — sazba není v číselníku státu spotřeby ani v tuzemské
     * škále, ale všechny ostatní znaky (EU, cizí stát, bez DIČ, nenulová sazba) ukazují
     * na OSS. Řádek OSS dostane, typ sazby ale zůstane prázdný a jde s varováním.
     */
    public function testRateKnownNowhereIsOssWithoutRateType(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_PL),
            25.0,
            'ks',
            '2026-07-15',
            false,
        );

        self::assertTrue($decision->applicable);
        self::assertSame('PL', $decision->consumerCountry);
        self::assertNull($decision->rateType, 'typ sazby se nikdy neodhaduje');
        self::assertNull($decision->toItemColumns()['oss_rate_type']);
        self::assertContains(OssDerivationReason::RateUnknownInConsumerCountry, $decision->notes);
        self::assertContains(OssDerivationReason::RateTypeUnknown, $decision->notes);
        self::assertNotSame([], $decision->toReport()['warnings']);
    }

    /**
     * Druhá větev téhož kvadrantu: řádek sice OSS je, ale sazbu, kterou nezná NIKDO,
     * nemá `vat_rates` z čeho napárovat — a `vat_rate_id` je NOT NULL s cizím klíčem.
     * Položka se proto ODMÍTNE hláškou, která pojmenuje stát spotřeby, a do číselníku
     * se nic nezaloží.
     *
     * Tohle je celý smysl § D4: dřívější `resolveOrCreateForOss()` v téhle situaci sazbu
     * TICHE vytvořil, čímž změnil globální číselník (tabulka nemá `supplier_id`) všem
     * nájemníkům instalace.
     */
    public function testOssRowWithARateNobodyKnowsIsRejectedAndFoundsNothing(): void
    {
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM vat_rates')->fetchColumn();

        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_PL),
            25.0,
            'ks',
            '2026-07-15',
            false,
        );
        self::assertTrue($decision->applicable);
        self::assertNull($decision->rateType);

        $match = (new VatRateResolver($this->conn))
            ->resolve((string) $decision->consumerCountry, 25.0, '2026-07-15');

        self::assertFalse($match->found(), 'nenalezená sazba je tvrdá chyba dokladu, ne důvod k zápisu');
        self::assertNull($match->id);
        self::assertStringContainsString('pro PL', $match->message);
        self::assertStringContainsString('pro zemi PL', $match->message);
        self::assertSame(
            $before,
            (int) $this->pdo->query('SELECT COUNT(*) FROM vat_rates')->fetchColumn(),
            'derivace ani párování nesmí do globálního číselníku sazeb nic přidat',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Short-circuit pravidla (pořadí vyhodnocení)
    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<array{0:string}> */
    public static function outsideValidityDates(): array
    {
        return [
            'den před začátkem registrace' => ['2026-05-31'],
            'den po konci registrace' => ['2027-01-01'],
        ];
    }

    #[DataProvider('outsideValidityDates')]
    public function testTaxDateOutsideSupplierRegistrationIsNotOss(string $taxDate): void
    {
        $decision = $this->deriver->derive(
            self::SUP_BOUNDED,
            $this->deriver->clientContext(self::CLI_PL),
            23.0,
            'ks',
            $taxDate,
            false,
        );

        self::assertFalse($decision->applicable);
        self::assertSame(OssDerivationReason::SupplierOssNotValidOnDate, $decision->reason);
        self::assertNull($decision->consumerCountry);
        // Polských 23 % ale nesmí propadnout do tuzemské větve, i když OSS nepřipadá
        // v úvahu — položka se odmítne a hláška řekne, co s tím.
        self::assertTrue($decision->isRejected());
        self::assertStringContainsString('platnost', (string) $decision->rejectionMessage);
    }

    /** Tuzemská sazba na dokladu mimo platnost registrace je legitimní — odmítat se nemá. */
    public function testDomesticRateOutsideSupplierRegistrationIsJustNotOss(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_BOUNDED,
            $this->deriver->clientContext(self::CLI_PL),
            21.0,
            'ks',
            '2027-01-01',
            false,
        );

        self::assertSame(OssDerivationReason::SupplierOssNotValidOnDate, $decision->reason);
        self::assertFalse($decision->isRejected());
        self::assertSame(0, $decision->toItemColumns()['oss_applicable']);
    }

    public function testTaxDateInsideSupplierRegistrationIsOss(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_BOUNDED,
            $this->deriver->clientContext(self::CLI_PL),
            23.0,
            'ks',
            '2026-06-01',
            false,
        );

        self::assertTrue($decision->applicable, 'hraniční den platnosti je ještě uvnitř');
    }

    public function testSupplierWithoutOssIsNeverOss(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_NO_OSS,
            $this->deriver->clientContext(self::CLI_PL),
            23.0,
            'kg',
            '2026-07-15',
            false,
        );

        self::assertFalse($decision->applicable);
        self::assertSame(OssDerivationReason::SupplierOssDisabled, $decision->reason);
    }

    public function testDomesticClientIsNotOss(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_CZ),
            21.0,
            'ks',
            '2026-07-15',
            false,
        );

        self::assertFalse($decision->applicable);
        self::assertSame(OssDerivationReason::ClientDomestic, $decision->reason);
    }

    /** @return list<array{0:int, 1:string}> */
    public static function nonEuClients(): array
    {
        return [
            'USA' => [self::CLI_US, 'US'],
            'Spojené království' => [self::CLI_GB, 'GB'],
        ];
    }

    #[DataProvider('nonEuClients')]
    public function testClientOutsideEuIsNotOss(int $clientId, string $iso2): void
    {
        $context = $this->deriver->clientContext($clientId);
        self::assertSame($iso2, $context->countryIso2);

        $decision = $this->deriver->derive(self::SUP_CZ, $context, 21.0, 'ks', '2026-07-15', false);

        self::assertFalse($decision->applicable);
        self::assertFalse($decision->isRejected(), 'tuzemská sazba na vývozu je legitimní');
        self::assertSame(OssDerivationReason::ClientNotEu, $decision->reason);
    }

    /**
     * Nulová sazba = osvobození / RC / vývoz — to řeší tuzemská klasifikace, ne OSS.
     * Invariant proti úniku se na ni ZÁMĚRNĚ neuplatní: bez daně nemá co uniknout
     * a číselník členských států nulové sazby nevede, takže by odmítl každý takový řádek.
     */
    public function testZeroRateIsNotOss(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_PL),
            0.0,
            'kg',
            '2026-07-15',
            false,
        );

        self::assertFalse($decision->applicable);
        self::assertFalse($decision->isRejected());
        self::assertSame(OssDerivationReason::ZeroRate, $decision->reason);
    }

    public function testClientWithVatIdIsB2bNotOss(): void
    {
        $context = $this->deriver->clientContext(self::CLI_PL_VAT);
        self::assertTrue($context->hasVatId());

        $decision = $this->deriver->derive(self::SUP_CZ, $context, 21.0, 'kg', '2026-07-15', false);

        self::assertFalse($decision->applicable);
        self::assertSame(OssDerivationReason::ClientHasVatId, $decision->reason);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Invariant proti úniku: cizí sazba se nikdy nevykáže jako tuzemské plnění
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Nález 4.1 uzavřený ze VŠECH stran najednou.
     *
     * Každá z podmínek níže vyřazuje řádek z OSS z jiného důvodu, a bez tohohle invariantu
     * by pokaždé skončil v tuzemské větvi s klasifikací '1' — tedy polských 23 % na ř. 1
     * českého přiznání. Sazba, kterou číselník členských států v zemi dodavatele nezná,
     * se proto nesmí zapsat vůbec; položka se odmítne a hláška pojmenuje, co doplnit.
     *
     * @return list<array{0:int, 1:?array<string,mixed>, 2:OssDerivationReason, 3:string}>
     */
    public static function foreignRateEscapeRoutes(): array
    {
        return [
            'vypnutý OSS u firmy' => [
                self::SUP_NO_OSS, null, OssDerivationReason::SupplierOssDisabled, 'Nastavení → DPH → OSS',
            ],
            'odběratel s DIČ (B2B)' => [
                self::SUP_CZ, ['client' => self::CLI_PL_VAT], OssDerivationReason::ClientHasVatId, 'odeberte DIČ',
            ],
            'odběratel mimo EU' => [
                self::SUP_CZ, ['client' => self::CLI_US], OssDerivationReason::ClientNotEu, 'mimo EU',
            ],
            'odběratel bez země' => [
                self::SUP_CZ, ['client' => self::CLI_NO_COUNTRY], OssDerivationReason::ClientCountryUnknown,
                'doplňte ji na dokladu nebo v kartě odběratele',
            ],
            'uložený klient tvrdí tuzemsko' => [
                self::SUP_CZ, ['client' => self::CLI_CZ], OssDerivationReason::ClientDomestic,
                'opravte zemi odběratele',
            ],
        ];
    }

    /**
     * @param ?array{client:int} $clientOverride
     */
    #[DataProvider('foreignRateEscapeRoutes')]
    public function testForeignRateNeverLeaksIntoDomesticSupply(
        int $supplierId,
        ?array $clientOverride,
        OssDerivationReason $reason,
        string $remedyFragment,
    ): void {
        $context = $this->deriver->clientContext($clientOverride['client'] ?? self::CLI_PL);

        $decision = $this->deriver->derive($supplierId, $context, 23.0, 'ks', '2026-07-15', false);

        self::assertSame($reason, $decision->reason);
        self::assertFalse($decision->applicable);
        self::assertTrue($decision->isRejected(), 'cizí sazba se nesmí tvářit jako tuzemské plnění');
        self::assertStringContainsString('(CZ)', (string) $decision->rejectionMessage, 'hláška pojmenuje zemi dodavatele');
        self::assertStringContainsString(
            $remedyFragment,
            (string) $decision->rejectionMessage,
            'hláška musí říct, co konkrétně chybí, ne obecné „nelze zpracovat"',
        );
        self::assertTrue($decision->toReport()['rejected']);
    }

    /**
     * Přesně ta varianta, kterou review našla zvlášť: doklad zemi odběratele nenese,
     * `ClientResolver` klienta založil s fallbackem 'CZ', takže uložený klient tvrdí
     * tuzemsko — a cizí sazba by prošla do českého přiznání.
     */
    public function testDocumentWithoutCountryFallingBackToCzClientIsRejected(): void
    {
        $context = $this->deriver->clientContext(self::CLI_CZ, ['country_iso2' => '', 'dic' => '']);
        self::assertSame('CZ', $context->countryIso2, 'uložený klient tvrdí tuzemsko');

        $decision = $this->deriver->derive(self::SUP_CZ, $context, 23.0, 'ks', '2026-07-15', false);

        self::assertTrue($decision->isRejected());
        self::assertSame(OssDerivationReason::ClientDomestic, $decision->reason);
    }

    /** Odmítnutá položka nemá sloupce k zápisu — volající to nesmí přehlédnout. */
    public function testRejectedDecisionRefusesToProduceColumns(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_NO_OSS,
            $this->deriver->clientContext(self::CLI_PL),
            23.0,
            'ks',
            '2026-07-15',
            false,
        );

        self::assertTrue($decision->isRejected());
        $this->expectException(\LogicException::class);
        $decision->toItemColumns();
    }

    /**
     * ÚNIK Č. 2, uzavřený u kořene: bez použitelného data plnění se číselníku nedá položit
     * ANI JEDNA otázka — a z nevědomosti se nikdy nesmí stát tuzemské zařazení. Dřív se
     * tady vracelo `notApplicable`, takže stačilo datum, které neprošlo `preg_match`,
     * a cizí sazba obešla invariant JEŠTĚ DŘÍV, než se vůbec vyhodnotil.
     */
    public function testMissingTaxDateIsRejectedNeverDomestic(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_PL),
            23.0,
            'ks',
            '',
            false,
        );

        self::assertSame(OssDerivationReason::MissingTaxDate, $decision->reason);
        self::assertTrue($decision->isRejected(), 'nevědomost o datu není důkaz o tuzemsku');
        self::assertStringContainsString('(CZ)', (string) $decision->rejectionMessage);
        self::assertStringContainsString('DUZP', (string) $decision->rejectionMessage);
    }

    /**
     * Nulová sazba je jediná výjimka z totality invariantu — bez daně nemá co unikat.
     * Doklad bez použitelného data je ale pořád vadný, takže se to musí objevit aspoň
     * jako varování; jinak by o něm report mlčel úplně.
     */
    public function testMissingTaxDateOnAZeroRatedRowIsOnlyAWarning(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_PL),
            0.0,
            'ks',
            '',
            false,
        );

        self::assertSame(OssDerivationReason::MissingTaxDate, $decision->reason);
        self::assertFalse($decision->isRejected());
        self::assertSame(0, $decision->toItemColumns()['oss_applicable']);
        self::assertNotSame([], $decision->toReport()['warnings'], 'vadné datum nesmí zmizet beze stopy');
    }

    /**
     * Nekanonický, ale ČITELNÝ tvar data se zkanonizuje, ne odmítne. Přesně tenhle tvar
     * (`<inv:date>2096-5-15</inv:date>` bez `dateTax`) použila review k reprodukci úniku:
     * datum neprošlo `preg_match`, řádek se prohlásil za tuzemský a 23 % skončilo na ř. 1.
     * Zároveň je kanonizace nutná i věcně — platnost sazby se porovnává jako ŘETĚZEC.
     */
    public function testNonCanonicalTaxDateIsNormalisedNotRejected(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_PL),
            23.0,
            'ks',
            '2026-7-5',
            false,
        );

        self::assertTrue($decision->applicable, 'čitelné datum je odpověď, ne nevědomost');
        self::assertSame(OssDerivationReason::B2cEuConsumer, $decision->reason);
        self::assertSame('PL', $decision->consumerCountry);
    }

    /** Neexistující den se NEDOMÝŠLÍ na nejbližší platný — je to vada dokladu. */
    public function testImpossibleCalendarDateIsRejected(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_PL),
            23.0,
            'ks',
            '2026-02-30',
            false,
        );

        self::assertSame(OssDerivationReason::MissingTaxDate, $decision->reason);
        self::assertTrue($decision->isRejected());
    }

    /**
     * Nevědomost o zemi DODAVATELE (historický doklad před seedem) vede k ODMÍTNUTÍ, ne
     * k tuzemskému zařazení. Dřív tenhle řádek zůstal tuzemský „s poznámkou", jenže
     * poznámka nikoho neochrání: řetěz pokračoval na `defaultSaleClassificationCode()`,
     * kód '1' a ř. 1 českého přiznání. Do tuzemské větve smí jedině POZITIVNÍ potvrzení.
     */
    public function testUnverifiableDomesticSideIsRejectedNotSilentlyDomestic(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_NO_OSS,
            $this->deriver->clientContext(self::CLI_PL),
            23.0,
            'ks',
            '2019-06-01',
            false,
        );

        self::assertFalse($decision->applicable);
        self::assertTrue($decision->isRejected(), 'nevědomost není potvrzení tuzemskosti');
        self::assertSame(OssDerivationReason::SupplierOssDisabled, $decision->reason);
        self::assertContains(OssDerivationReason::DomesticRatesNotInCodebook, $decision->notes);
        self::assertStringContainsString('nepodařilo ověřit', (string) $decision->rejectionMessage);
        self::assertStringContainsString('(CZ)', (string) $decision->rejectionMessage);
    }

    /**
     * Totéž, když chybí celý číselník (migrace 1152). Rada navázaná na důvod („zapněte
     * OSS") by uživatele poslala jinam, než kde je příčina — hláška proto ukazuje na
     * migraci, ne na nastavení.
     */
    public function testMissingCodebookRejectsBlockedRowsAndPointsAtTheMigration(): void
    {
        $this->pdo->exec('DROP TABLE oss_member_state_rates');

        $decision = $this->newDeriver()->derive(
            self::SUP_NO_OSS,
            new OssClientContext('PL', true, null),
            23.0,
            'ks',
            '2026-07-15',
            false,
        );

        self::assertTrue($decision->isRejected());
        self::assertContains(OssDerivationReason::CodebookUnavailable, $decision->notes);
        self::assertStringContainsString('1152', (string) $decision->rejectionMessage);
        self::assertStringContainsString('migrate.php', (string) $decision->rejectionMessage);
        self::assertStringNotContainsString(
            'Nastavení → DPH → OSS',
            (string) $decision->rejectionMessage,
            'příčina je neproběhlá migrace, ne vypnutý přepínač',
        );
    }

    /**
     * Druhá strana téhož pravidla: POTVRZENÁ tuzemská sazba projde i tehdy, když řádek
     * OSS být nemůže. Bez tohohle případu by „totální invariant" znamenal jen „odmítej
     * všechno" a běžný tuzemský import by přestal fungovat.
     */
    public function testConfirmedDomesticRateOnABlockedRowStaysDomestic(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_NO_OSS,
            $this->deriver->clientContext(self::CLI_PL),
            21.0,
            'ks',
            '2026-07-15',
            false,
        );

        self::assertFalse($decision->applicable);
        self::assertFalse($decision->isRejected(), 'číselník tuzemskou sazbu POTVRDIL');
        self::assertSame(OssDerivationReason::SupplierOssDisabled, $decision->reason);
        self::assertSame([], $decision->toReport()['warnings']);
    }

    /** Hlavičkový příznak je explicitní rozhodnutí uživatele → čte se dřív než DIČ. */
    public function testHeaderReverseChargeOutranksVatId(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_PL_VAT),
            23.0,
            'kg',
            '2026-07-15',
            true,
        );

        self::assertSame(OssDerivationReason::HeaderReverseCharge, $decision->reason);
    }

    public function testClientWithoutCountryIsNotOssAndCountryIsNeverGuessed(): void
    {
        $context = $this->deriver->clientContext(self::CLI_NO_COUNTRY);
        self::assertNull($context->countryIso2, 'chybějící země se nikdy nedomýšlí na CZ');

        $decision = $this->deriver->derive(self::SUP_CZ, $context, 23.0, 'kg', '2026-07-15', false);

        self::assertSame(OssDerivationReason::ClientCountryUnknown, $decision->reason);
    }

    /** @return list<array{0:string}> */
    public static function unusableTaxDates(): array
    {
        return [
            'prázdné' => [''],
            'český formát' => ['15. 7. 2026'],
            'jen rok' => ['2026'],
            'neexistující měsíc' => ['2026-13-01'],
            's časem' => ['2026-07-15 00:00:00'],
        ];
    }

    #[DataProvider('unusableTaxDates')]
    public function testUnusableTaxDateIsNotGuessed(string $taxDate): void
    {
        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_PL),
            23.0,
            'kg',
            $taxDate,
            false,
        );

        self::assertSame(OssDerivationReason::MissingTaxDate, $decision->reason);
        // Nečitelné datum je vada dokladu, ne důvod k tuzemskému zařazení cizí sazby.
        self::assertTrue($decision->isRejected());
    }

    /**
     * Chybí-li migrace 0137, dostane report JEDNU srozumitelnou větu místo 850
     * nesmyslných řádků.
     */
    public function testMissingOssSchemaShortCircuitsEverything(): void
    {
        $this->pdo->exec('DROP TABLE invoice_items');
        $this->pdo->exec('CREATE TABLE invoice_items (id INTEGER PRIMARY KEY)');

        $decision = $this->newDeriver()->derive(
            self::SUP_CZ,
            new OssClientContext('PL', true, null),
            23.0,
            'kg',
            '2026-07-15',
            false,
        );

        self::assertSame(OssDerivationReason::OssSchemaMissing, $decision->reason);
        self::assertStringContainsString('migrate.php', $decision->toReport()['reason_message']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Země z importovaného dokladu přebíjí uloženého klienta
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * `ClientResolver` ukládá `country_iso2` s fallbackem 'CZ' a `countryIdFromIso2()`
     * na neznámé ISO odpovídá rovněž Českem. Uložený klient tedy umí tvrdit „tuzemsko"
     * i u polského spotřebitele — a derivace by z toho udělala tuzemské plnění a poslala
     * cizí daň na ř. 1. Země z dokladu proto uloženou přebíjí.
     */
    public function testDocumentCountryOverridesStoredClientCountry(): void
    {
        $stored = $this->deriver->clientContext(self::CLI_CZ);
        self::assertSame('CZ', $stored->countryIso2, 'uložený klient tvrdí tuzemsko');

        $context = $this->deriver->clientContext(self::CLI_CZ, ['country_iso2' => 'pl', 'dic' => '']);
        self::assertSame('PL', $context->countryIso2);
        self::assertTrue($context->isEu, 'členství v EU se dohledá podle ISO kódu z dokladu');
        self::assertTrue($context->countryFromDocument);

        $decision = $this->deriver->derive(self::SUP_CZ, $context, 23.0, 'kg', '2026-07-15', false);

        self::assertTrue($decision->applicable);
        self::assertSame('PL', $decision->consumerCountry);
        self::assertContains(OssDerivationReason::ClientCountryFromDocument, $decision->notes);
    }

    /** Doklad zemi nenese → rozhodne uložený klient, nic se nedomýšlí. */
    public function testDocumentWithoutCountryFallsBackToStoredClient(): void
    {
        $context = $this->deriver->clientContext(self::CLI_PL, ['country_iso2' => '', 'dic' => '']);

        self::assertSame('PL', $context->countryIso2);
        self::assertFalse($context->countryFromDocument);
    }

    /** Ani doklad, ani uložený klient zemi nemá → položka se odmítne, nikdy „tuzemsko". */
    public function testNeitherDocumentNorClientCountryIsRejected(): void
    {
        $context = $this->deriver->clientContext(self::CLI_NO_COUNTRY, ['country_iso2' => 'Česko', 'dic' => '']);
        self::assertNull($context->countryIso2, 'nepoužitelný kód se nedomýšlí, ani z dokladu');

        $decision = $this->deriver->derive(self::SUP_CZ, $context, 23.0, 'kg', '2026-07-15', false);

        self::assertSame(OssDerivationReason::ClientCountryUnknown, $decision->reason);
    }

    /**
     * Doklad nese ISO kód, který systém nezná (`countries` ho nemá). Nesmí se z něj stát
     * tuzemsko ani člen EU — řádek skončí jako plnění mimo EU, tedy mimo OSS.
     */
    public function testUnknownDocumentCountryIsNotTreatedAsEu(): void
    {
        $context = $this->deriver->clientContext(self::CLI_CZ, ['country_iso2' => 'XX', 'dic' => '']);
        self::assertSame('XX', $context->countryIso2);
        self::assertFalse($context->isEu);

        $decision = $this->deriver->derive(self::SUP_CZ, $context, 23.0, 'kg', '2026-07-15', false);

        self::assertSame(OssDerivationReason::ClientNotEu, $decision->reason);
    }

    /** DIČ z dokladu vyřadí řádek z OSS, i když uložený klient žádné nemá. */
    public function testVatIdFromDocumentExcludesOss(): void
    {
        $context = $this->deriver->clientContext(self::CLI_PL, ['country_iso2' => 'PL', 'dic' => 'PL1234567890']);

        $decision = $this->deriver->derive(self::SUP_CZ, $context, 23.0, 'kg', '2026-07-15', false);

        self::assertSame(OssDerivationReason::ClientHasVatId, $decision->reason);
    }

    /**
     * Chybějící DIČ na dokladu je běžná neúplnost exportu, kdežto DIČ u uloženého klienta
     * je tvrdý signál B2B. Uložené se proto zachová — méně OSS řádků je bezpečnější směr
     * chyby než víc.
     */
    public function testStoredVatIdSurvivesDocumentWithoutOne(): void
    {
        $context = $this->deriver->clientContext(self::CLI_PL_VAT, ['country_iso2' => 'PL', 'dic' => '']);

        self::assertTrue($context->hasVatId());
    }

    /** Neznámý stát z dokladu není v `countries`, takže není EU — a OSS se neuplatní. */
    public function testDocumentCountryOutsideEuIsNotOss(): void
    {
        $context = $this->deriver->clientContext(self::CLI_PL, ['country_iso2' => 'US', 'dic' => '']);

        $decision = $this->deriver->derive(self::SUP_CZ, $context, 23.0, 'kg', '2026-07-15', false);

        self::assertSame(OssDerivationReason::ClientNotEu, $decision->reason);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Číselník sazeb členských států
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Chybějící stát v číselníku nesmí shodit derivaci — jen nechat typ sazby prázdný.
     * Místo plnění je tu přitom určené: tuzemsko sazbu 23 % k roku 2026 VYLUČUJE, takže
     * nevědomost o státu spotřeby se do rozhodnutí nepromítne a řádek jde do OSS čistě.
     */
    public function testConsumerCountryMissingFromCodebookStillDerivesOss(): void
    {
        $decision = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_HU),
            23.0,
            'ks',
            '2026-07-15',
            false,
        );

        self::assertTrue($decision->applicable);
        self::assertSame('HU', $decision->consumerCountry);
        self::assertSame(OssDerivationReason::B2cEuConsumer, $decision->reason);
        self::assertNull($decision->rateType, 'chybějící stát v číselníku se nenahrazuje odhadem');
        self::assertContains(OssDerivationReason::ConsumerCountryNotInCodebook, $decision->notes);
        self::assertContains(OssDerivationReason::RateTypeUnknown, $decision->notes);
        self::assertNotSame([], $decision->toReport()['warnings']);
    }

    /**
     * Nález 4.3: chybějící TABULKA se nesmí hlásit jako „stát není v číselníku".
     * Uživatel pak hledá chybu v datech místo v neproběhlé migraci 1152.
     */
    public function testMissingCodebookTableIsReportedAsMissingMigration(): void
    {
        $this->pdo->exec('DROP TABLE oss_member_state_rates');

        $decision = $this->newDeriver()->derive(
            self::SUP_CZ,
            new OssClientContext('PL', true, null),
            23.0,
            'ks',
            '2026-07-15',
            false,
        );

        self::assertTrue($decision->applicable);
        self::assertNull($decision->rateType);
        self::assertContains(OssDerivationReason::CodebookUnavailable, $decision->notes);
        self::assertNotContains(OssDerivationReason::ConsumerCountryNotInCodebook, $decision->notes);
        self::assertStringContainsString('1152', OssDerivationReason::CodebookUnavailable->message());
        // Bez číselníku nejde určit ani stát spotřeby, ani tuzemsko — místo plnění tedy
        // určené není a řádek jde do OSS s příznakem k ručnímu posouzení.
        self::assertSame(OssDerivationReason::RateOriginUnverifiable, $decision->reason);
        self::assertTrue($decision->needsManualReview());
        self::assertSame(
            1,
            count(array_filter(
                $decision->notes,
                static fn (OssDerivationReason $n): bool => $n === OssDerivationReason::CodebookUnavailable,
            )),
            'obě strany se ptají téhož číselníku — tatáž věta se do reportu nepíše dvakrát',
        );
    }

    /** Sazba platná v jiném období se k datu plnění použít nesmí (SI 22 % → 23 % od 2026). */
    public function testCodebookRespectsValidityAtTaxDate(): void
    {
        // SUP_SK má registraci bez omezení platnosti, takže na starší datum plnění
        // nespadne dřív na SupplierOssNotValidOnDate.
        $old = $this->deriver->derive(
            self::SUP_SK,
            new OssClientContext('SI', true, null),
            22.0,
            'ks',
            '2025-06-01',
            false,
        );
        self::assertSame('standard', $old->rateType);
        self::assertContains(OssDerivationReason::RateTypeFromCodebook, $old->notes);

        $current = $this->newDeriver()->derive(
            self::SUP_CZ,
            new OssClientContext('SI', true, null),
            22.0,
            'ks',
            '2026-07-15',
            false,
        );
        self::assertContains(
            OssDerivationReason::RateUnknownInConsumerCountry,
            $current->notes,
            'k 2026 už 22 % ve Slovinsku neplatí',
        );
        self::assertNull($current->rateType);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tuzemsko = země dodavatele, ne 'CZ'
    // ─────────────────────────────────────────────────────────────────────────

    public function testDomesticIsSupplierCountryNotHardcodedCz(): void
    {
        $sk = $this->deriver->derive(
            self::SUP_SK,
            $this->deriver->clientContext(self::CLI_SK),
            23.0,
            'ks',
            '2026-07-15',
            false,
        );
        self::assertSame(OssDerivationReason::ClientDomestic, $sk->reason, 'SK odběratel u SK dodavatele je tuzemský');

        $cz = $this->deriver->derive(
            self::SUP_SK,
            $this->deriver->clientContext(self::CLI_CZ),
            21.0,
            'ks',
            '2026-07-15',
            false,
        );
        self::assertTrue($cz->applicable, 'CZ odběratel je pro SK dodavatele plnění do jiného členského státu');
        self::assertSame('CZ', $cz->consumerCountry);
    }

    /**
     * Import páruje tuzemský `vat_rate_id` proti TÉŽE zemi, ze které deriver počítá
     * tuzemskou shodu. Vlastní konstanta 'CZ' na straně volajícího by obě strany
     * rozešla přesně u dodavatele identifikovaného mimo ČR.
     *
     * @return list<array{0:int, 1:string}>
     */
    public static function domesticCountries(): array
    {
        return [
            'český dodavatel' => [self::SUP_CZ, 'CZ'],
            'slovenský dodavatel' => [self::SUP_SK, 'SK'],
            'neznámý dodavatel' => [9999, 'CZ'],
        ];
    }

    #[DataProvider('domesticCountries')]
    public function testDomesticCountryIsExposedForCallers(int $supplierId, string $expected): void
    {
        self::assertSame($expected, $this->deriver->domesticCountry($supplierId));
    }

    /** Tatáž sazba u dvou dodavatelů: 23 % je v ČR neznámá, na Slovensku tuzemská. */
    public function testAmbiguityIsEvaluatedAgainstSupplierCountry(): void
    {
        $cz = $this->deriver->derive(
            self::SUP_CZ,
            $this->deriver->clientContext(self::CLI_PL),
            23.0,
            'ks',
            '2026-07-15',
            false,
        );
        self::assertSame(OssDerivationReason::B2cEuConsumer, $cz->reason, 'ČR sazbu 23 % nezná → jednoznačně OSS');
        self::assertFalse($cz->needsManualReview());

        $sk = $this->deriver->derive(
            self::SUP_SK,
            $this->deriver->clientContext(self::CLI_PL),
            23.0,
            'ks',
            '2026-07-15',
            false,
        );
        // Pro slovenského dodavatele je 23 % i tuzemská sazba, takže z procenta místo
        // plnění určit nejde. Řádek jde do OSS (kde je chyba v náhledu podání vidět)
        // a označí se k ručnímu posouzení.
        self::assertTrue($sk->applicable);
        self::assertSame(OssDerivationReason::RateAmbiguousDomesticOrConsumer, $sk->reason);
        self::assertTrue($sk->needsManualReview());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Typ plnění — třístupňový žebřík
    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<array{0:int, 1:?string, 2:string, 3:OssDerivationReason}> */
    public static function supplyTypeLadder(): array
    {
        return [
            'časová jednotka → služba' => [self::SUP_CZ, 'h', 'services', OssDerivationReason::SupplyTypeFromUnit],
            'fyzikální jednotka → zboží' => [self::SUP_CZ, 'kg', 'goods', OssDerivationReason::SupplyTypeFromUnit],
            'ks bez NACE → výchozí služba' => [self::SUP_CZ, 'ks', 'services', OssDerivationReason::SupplyTypeDefaultServices],
            'bez jednotky bez NACE → výchozí služba' => [self::SUP_CZ, null, 'services', OssDerivationReason::SupplyTypeDefaultServices],
            'ks + maloobchodní NACE → zboží' => [self::SUP_NACE_GOODS, 'ks', 'goods', OssDerivationReason::SupplyTypeFromSupplierNace],
            'jednotka přebíjí NACE' => [self::SUP_NACE_GOODS, 'hod', 'services', OssDerivationReason::SupplyTypeFromUnit],
        ];
    }

    #[DataProvider('supplyTypeLadder')]
    public function testSupplyTypeLadder(int $supplierId, ?string $unit, string $expected, OssDerivationReason $note): void
    {
        $decision = $this->deriver->derive(
            $supplierId,
            $this->deriver->clientContext(self::CLI_PL),
            23.0,
            $unit,
            '2026-07-15',
            false,
        );

        self::assertTrue($decision->applicable);
        self::assertSame($expected, $decision->supplyType);
        self::assertContains($note, $decision->notes);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Kontext odběratele
    // ─────────────────────────────────────────────────────────────────────────

    /** Import 850 dokladů nesmí dělat 850 dotazů — kontext se cachuje per instance. */
    public function testClientContextIsCachedPerInstance(): void
    {
        $first = $this->deriver->clientContext(self::CLI_PL);
        $this->pdo->exec('DELETE FROM clients WHERE id = ' . self::CLI_PL);
        $second = $this->deriver->clientContext(self::CLI_PL);

        self::assertSame('PL', $first->countryIso2);
        self::assertSame('PL', $second->countryIso2, 'druhé volání se do DB nesmí vracet');
    }

    public function testUnknownClientYieldsEmptyContext(): void
    {
        $context = $this->deriver->clientContext(9999);

        self::assertNull($context->countryIso2);
        self::assertFalse($context->isEu);
        self::assertFalse($context->hasVatId());
    }

    public function testEmptyDicIsNotAVatId(): void
    {
        $context = OssClientContext::fromArray(['country_iso2' => 'pl', 'is_eu' => 1, 'dic' => '   ']);

        self::assertSame('PL', $context->countryIso2);
        self::assertFalse($context->hasVatId(), 'prázdné DIČ se čte stejně jako v OssThresholdService::b2cRows()');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Invariant výsledku
    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<array{0:string, 1:?string, 2:string}> */
    public static function invalidOssDecisions(): array
    {
        return [
            'země není ISO2' => ['POL', 'standard', 'goods'],
            'neznámý typ sazby' => ['PL', 'super_reduced', 'goods'],
            'neznámý typ plnění' => ['PL', 'standard', 'licence'],
        ];
    }

    #[DataProvider('invalidOssDecisions')]
    public function testDecisionRefusesIncompleteOssRow(string $country, ?string $rateType, string $supplyType): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OssItemDecision::oss($country, $rateType, $supplyType);
    }

    /**
     * Prázdný typ sazby je NAVRŽENÝ stav, ne mezera v invariantu: odhad by skončil ve
     * výkazu, protože do podání jde typ sazby, ne procento. Řádek projde a zastaví se
     * až na exportu XML, který ho do podání nepustí.
     */
    public function testNullRateTypeIsAllowedOnOssRow(): void
    {
        $decision = OssItemDecision::oss('PL', null, 'goods', [OssDerivationReason::RateTypeUnknown]);

        self::assertTrue($decision->applicable);
        self::assertNull($decision->rateType);
        self::assertNull($decision->toItemColumns()['oss_rate_type']);
    }

    public function testReportSeparatesWarningsFromNotes(): void
    {
        $decision = OssItemDecision::oss('SK', null, 'goods', [
            OssDerivationReason::ConsumerCountryNotInCodebook,
            OssDerivationReason::SupplyTypeFromUnit,
        ]);

        $report = $decision->toReport();

        self::assertTrue($report['applicable']);
        self::assertFalse($report['needs_manual_review']);
        self::assertSame('b2c_eu_consumer', $report['reason']);
        self::assertSame(
            ['consumer_country_not_in_codebook', 'supply_type_from_unit'],
            $report['notes'],
        );
        self::assertCount(1, $report['warnings'], 'varování je jen chybějící stát, ne odvozený typ plnění');
    }

    /**
     * Výchozí „služba" NENÍ odvození — jednotka ani NACE dodavatele nic neřekly a typ
     * plnění přitom rozhoduje o sazbě ve státě spotřeby. Musí proto skončit mezi
     * varováními, ne ve sbalených poznámkách, kde by si ho uživatel s 850 doklady
     * nepřečetl.
     */
    public function testDefaultSupplyTypeIsWarningNotJustNote(): void
    {
        self::assertTrue(OssDerivationReason::SupplyTypeDefaultServices->isWarning());
        self::assertFalse(OssDerivationReason::SupplyTypeFromUnit->isWarning());
        self::assertFalse(OssDerivationReason::SupplyTypeFromSupplierNace->isWarning());

        $report = OssItemDecision::oss('SK', null, 'services', [
            OssDerivationReason::SupplyTypeDefaultServices,
        ])->toReport();

        self::assertSame(['supply_type_default_services'], $report['notes']);
        self::assertSame(
            [OssDerivationReason::SupplyTypeDefaultServices->message()],
            $report['warnings'],
        );
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY, iso2 TEXT, is_eu INTEGER DEFAULT 0)');
        $this->pdo->exec(
            'CREATE TABLE supplier (
                id INTEGER PRIMARY KEY,
                country_id INTEGER,
                oss_enabled INTEGER DEFAULT 0,
                oss_valid_from TEXT,
                oss_valid_to TEXT,
                oss_identification_country TEXT,
                cz_nace_code TEXT
            )'
        );
        $this->pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, country_id INTEGER, dic TEXT)');
        // Sloupec `oss_applicable` je to jediné, co deriver na invoice_items zajímá —
        // jeho existencí testuje, jestli proběhla migrace 0137.
        $this->pdo->exec('CREATE TABLE invoice_items (id INTEGER PRIMARY KEY, oss_applicable INTEGER DEFAULT 0)');
        $this->pdo->exec(
            'CREATE TABLE oss_member_state_rates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                country TEXT,
                rate_type TEXT,
                rate_percent DECIMAL(5,2),
                valid_from TEXT NOT NULL,
                valid_to TEXT
            )'
        );
        $this->pdo->exec(
            "CREATE TABLE vat_rates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT NOT NULL UNIQUE,
                rate_percent DECIMAL(5,2) NOT NULL,
                country TEXT NOT NULL DEFAULT 'CZ',
                label_cs TEXT,
                label_en TEXT,
                is_default INTEGER NOT NULL DEFAULT 0,
                is_reverse_charge INTEGER NOT NULL DEFAULT 0,
                valid_from TEXT NOT NULL,
                valid_to TEXT,
                display_order INTEGER NOT NULL DEFAULT 0
            )"
        );
    }

    private function seed(): void
    {
        $countries = [
            [1, 'CZ', 1], [2, 'PL', 1], [3, 'NL', 1], [4, 'ES', 1],
            [5, 'US', 0], [6, 'GB', 0], [7, 'SK', 1], [8, 'SI', 1],
            [9, 'HU', 1],
        ];
        $stmt = $this->pdo->prepare('INSERT INTO countries (id, iso2, is_eu) VALUES (?, ?, ?)');
        foreach ($countries as $row) {
            $stmt->execute($row);
        }

        $suppliers = [
            [self::SUP_CZ, 1, 1, '2026-01-01', null, 'CZ', null],
            [self::SUP_NO_OSS, 1, 0, null, null, 'CZ', null],
            [self::SUP_SK, 7, 1, null, null, 'SK', null],
            [self::SUP_BOUNDED, 1, 1, '2026-06-01', '2026-12-31', 'CZ', null],
            [self::SUP_NACE_GOODS, 1, 1, '2026-01-01', null, 'CZ', '47.11'],
            [self::SUP_HU, 9, 1, '2026-01-01', null, 'HU', null],
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO supplier
                (id, country_id, oss_enabled, oss_valid_from, oss_valid_to, oss_identification_country, cz_nace_code)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($suppliers as $row) {
            $stmt->execute($row);
        }

        $clients = [
            [self::CLI_PL, 2, null],
            [self::CLI_PL_VAT, 2, 'PL1234567890'],
            [self::CLI_CZ, 1, null],
            [self::CLI_US, 5, null],
            [self::CLI_GB, 6, null],
            [self::CLI_NL, 3, null],
            [self::CLI_ES, 4, null],
            [self::CLI_NO_COUNTRY, null, null],
            [self::CLI_SK, 7, null],
            [self::CLI_HU, 9, null],
        ];
        $stmt = $this->pdo->prepare('INSERT INTO clients (id, country_id, dic) VALUES (?, ?, ?)');
        foreach ($clients as $row) {
            $stmt->execute($row);
        }

        // Výřez číselníku podle migrace 1152. HU schválně CHYBÍ (test chybějícího státu),
        // SI má uzavřenou historickou sazbu (test platnosti k datu). CZ začíná až
        // 2024-01-01 stejně jako v migraci — starší datum plnění je proto stav „nevím".
        $rates = [
            ['CZ', 'standard', '21.00', '2024-01-01', null],
            ['CZ', 'reduced', '12.00', '2024-01-01', null],
            ['SK', 'standard', '20.00', '2021-07-01', '2024-12-31'],
            ['SK', 'standard', '23.00', '2025-01-01', null],
            ['PL', 'standard', '23.00', '2021-07-01', null],
            ['PL', 'reduced', '8.00', '2021-07-01', null],
            ['PL', 'second_reduced', '5.00', '2021-07-01', null],
            ['NL', 'standard', '21.00', '2021-07-01', null],
            ['NL', 'reduced', '9.00', '2021-07-01', null],
            ['ES', 'standard', '21.00', '2021-07-01', null],
            ['ES', 'reduced', '10.00', '2021-07-01', null],
            ['SI', 'standard', '22.00', '2021-07-01', '2025-12-31'],
            ['SI', 'standard', '23.00', '2026-01-01', null],
            ['SI', 'reduced', '9.50', '2021-07-01', null],
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO oss_member_state_rates (country, rate_type, rate_percent, valid_from, valid_to)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($rates as $row) {
            $stmt->execute($row);
        }

        // Tuzemská škála jako na stock instalaci plus sazby, které si zákazník založil sám.
        // Řádek 'PL-23-vlastni' má procento 23 a zemi **CZ** — přesně jak ho má zákazník
        // z analýzy, protože formulář v Nastavení → Sazby DPH má CZ předvyplněnou. Kdyby
        // se deriver ptal na tuzemskost `vat_rates`, dostal by „ČR zná 23 %" a polské
        // plnění by skončilo na ř. 1 přiznání. Fixture ho drží schválně.
        $vatRates = [
            ['CZ-21', '21.00', 'CZ', '2024-01-01', null],
            ['CZ-12', '12.00', 'CZ', '2024-01-01', null],
            ['CZ-0', '0.00', 'CZ', '2024-01-01', null],
            ['SK-23', '23.00', 'SK', '2025-01-01', null],
            ['PL-23', '23.00', 'PL', '2021-07-01', null],
            ['PL-23-vlastni', '23.00', 'CZ', '2021-07-01', null],
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO vat_rates
                (code, rate_percent, country, label_cs, label_en, valid_from, valid_to, display_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, 10)'
        );
        foreach ($vatRates as [$code, $percent, $country, $from, $to]) {
            $stmt->execute([$code, $percent, $country, $code, $code, $from, $to]);
        }
    }
}
