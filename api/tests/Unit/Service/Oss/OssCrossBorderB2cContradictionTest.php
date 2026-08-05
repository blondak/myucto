<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Oss;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Oss\OssDerivationReason;
use MyInvoice\Service\Oss\OssItemDeriver;
use MyInvoice\Service\Oss\OssRateCodebook;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PŘESHRANIČNÍ B2C PLNĚNÍ ZA TUZEMSKOU SAZBU PŘI AKTIVNÍ REGISTRACI DO OSS (§ H2).
 *
 * Naměřený stav: polský spotřebitel BEZ DIČ, dodavatel s AKTIVNÍ registrací do OSS pro
 * dané období, `percentVAT 21` → doklad prošel jako `created`, nula varování, nula
 * poznámek, nula OSS řádků, kód '1' a ř. 1 přiznání. Kvadrant „tuzemské plnění" byl
 * úplně němý.
 *
 * Není to únik cizí daně: sazbu uvádí sám doklad a číselník členských států ji v zemi
 * dodavatele POZITIVNĚ potvrdil, takže invariant proti úniku je splněný. Je to ale vnitřní
 * rozpor dokladu — registrace do OSS je sice dobrovolná a plnění pod prahem § 8/3 tuzemské
 * opravdu být může, jenže mnohem častěji je to špatná sazba. ROZHODNUTÍ se proto nemění
 * a mění se jedině to, že o tom uživatel ví.
 *
 * Test stojí na obou tvrzeních zároveň — „řádek zůstává tuzemský" i „řádek je označený" —,
 * protože každé z nich samo o sobě jde splnit špatně: přeznačkováním na OSS (to by byla
 * cizí daň v jiném státě, než kam patří) i mlčením (to je původní stav).
 *
 * Druhá polovina testu je ÚZKOST podmínky. Varování, které vyskočí na běžném tuzemském
 * dokladu, je u migrace 1 670 dokladů horší než žádné: uživatel ho přestane číst dřív, než
 * dojde k tomu jednomu, kde o něco jde. Každá podmínka má proto vlastní protipól.
 *
 * Fixture je vlastní a minimální (SQLite v paměti, jen tabulky, na které se deriver ptá),
 * ať test nezávisí na databázi ani na obsahu ostatních fixture.
 */
final class OssCrossBorderB2cContradictionTest extends TestCase
{
    /** Dodavatel v ČR s registrací do OSS platnou od 1. 1. 2026 bez konce. */
    private const SUP_ACTIVE = 1;
    /** Týž dodavatel, ale režim OSS vypnutý. */
    private const SUP_NO_OSS = 2;
    /** Registrace OHRANIČENÁ na 1. 6. – 31. 12. 2026. */
    private const SUP_BOUNDED = 3;

    /** Polský spotřebitel bez DIČ — přesně odběratel z naměřeného případu. */
    private const CLI_PL = 1;
    /** Týž odběratel, ale s DIČ (B2B). */
    private const CLI_PL_VAT = 2;
    private const CLI_CZ = 3;
    private const CLI_US = 4;

    private const TAX_DATE = '2026-07-15';

    private PDO $pdo;
    private OssItemDeriver $deriver;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->seed();

        $conn = new Connection($this->createStub(Config::class));
        (new \ReflectionClass($conn))->getProperty('pdo')->setValue($conn, $this->pdo);
        $this->deriver = new OssItemDeriver($conn, new OssRateCodebook($conn));
    }

    /** @return \MyInvoice\Service\Oss\OssItemDecision */
    private function derive(
        int $supplierId,
        int $clientId,
        float $rate,
        string $taxDate = self::TAX_DATE,
        bool $reverseCharge = false,
        string $unit = 'kg',
    ) {
        // Jednotka je schválně „kg": u 'ks' bez CZ-NACE dodavatele se typ plnění nemá
        // z čeho odvodit a přibude varování o výchozí „službě", které s rozporem nemá nic
        // společného — tvrzení „řádek nemá co hlásit" by pak nešlo napsat.
        return $this->deriver->derive(
            $supplierId,
            $this->deriver->clientContext($clientId),
            $rate,
            $unit,
            $taxDate,
            $reverseCharge,
        );
    }

    // ── Případ B: tuzemská sazba na přeshraničním B2C plnění ─────────────────

    /**
     * Obě tuzemské sazby, které v Polsku neplatí: základní 21 % (naměřený případ B)
     * i snížená 12 % (tuzemská polovina naměřeného SMÍŠENÉHO dokladu z případu A).
     * Rozpor nesmí být navázaný na konkrétní procento.
     *
     * @return list<array{0:float}>
     */
    public static function domesticRates(): array
    {
        return [
            'základní sazba 21 %' => [21.0],
            'snížená sazba 12 %' => [12.0],
        ];
    }

    #[DataProvider('domesticRates')]
    public function testDomesticRateForEuConsumerWithActiveRegistrationIsFlagged(float $rate): void
    {
        $decision = $this->derive(self::SUP_ACTIVE, self::CLI_PL, $rate);

        // 1) ROZHODNUTÍ SE NEMĚNÍ — řádek zůstává tuzemský a půjde do přiznání k DPH.
        self::assertFalse($decision->applicable, 'Sazbu uvádí doklad; registrace do OSS je dobrovolná.');
        self::assertFalse($decision->isRejected(), 'Číselník sazbu v zemi dodavatele potvrdil, není co odmítat.');
        self::assertSame(OssDerivationReason::RateMatchesDomesticOnly, $decision->reason);
        self::assertSame(0, $decision->toItemColumns()['oss_applicable']);
        self::assertNull($decision->consumerCountry);

        // 2) ALE UŽIVATEL SE TO DOZVÍ — poznámka, varování v reportu i příznak v datech.
        self::assertContains(OssDerivationReason::DomesticRateOnCrossBorderB2c, $decision->notes);
        self::assertTrue($decision->needsManualReview(),
            'Rozpor nese POZNÁMKA, ne důvod — tuzemská větev vlastní důvod „je to podezřelé" mít '
                . 'nemůže, protože důvod odpovídá na otázku „je řádek OSS".');
        self::assertSame(1, $decision->toItemColumns()['oss_needs_manual_review'],
            'Příznak musí přežít zavření reportu, jinak je to jednorázová hláška.');

        $report = $decision->toReport();
        self::assertTrue($report['needs_manual_review']);
        $warnings = implode("\n", $report['warnings']);
        self::assertStringContainsString('TUZEMSKOU sazbou', $warnings);
        self::assertStringContainsString('K RUČNÍMU POSOUZENÍ', $warnings);
        self::assertStringContainsString('aktivní registraci do OSS', $warnings,
            'Hláška musí říct, PROČ je to podezřelé — jinak uživatel neví, co má na dokladu hledat.');
    }

    /**
     * Druhá polovina naměřeného SMÍŠENÉHO dokladu: řádek 23 % je sám o sobě čistý OSS
     * případ bez jediné výhrady. Právě proto rozpor mezi řádky NEMŮŽE odhalit deriver —
     * obě rozhodnutí jsou z pohledu řádku správná a soudržnost dokladu se dá zkontrolovat
     * až nad všemi jeho položkami (§ H1,
     * {@see \MyInvoice\Tests\Unit\Service\Import\InvoiceImportDocumentCoherenceTest}).
     */
    public function testOssHalfOfTheMixedDocumentIsCleanOnItsOwn(): void
    {
        $decision = $this->derive(self::SUP_ACTIVE, self::CLI_PL, 23.0);

        self::assertTrue($decision->applicable);
        self::assertSame('PL', $decision->consumerCountry);
        self::assertSame(OssDerivationReason::B2cEuConsumer, $decision->reason);
        self::assertFalse($decision->needsManualReview(), 'Na tomhle řádku není co posuzovat.');
        self::assertSame([], $decision->toReport()['warnings']);
    }

    /**
     * Přenesená daňová povinnost na hlavičce je jediný blokující důvod, který se s aktivní
     * registrací a spotřebitelem bez DIČ z JČS potká — a takový doklad si o zpochybnění
     * říká stejně naléhavě jako sazba samotná. Rozhodnutí se opět nemění.
     */
    public function testHeaderReverseChargeOnCrossBorderB2cIsFlaggedToo(): void
    {
        $decision = $this->derive(self::SUP_ACTIVE, self::CLI_PL, 21.0, reverseCharge: true);

        self::assertSame(OssDerivationReason::HeaderReverseCharge, $decision->reason);
        self::assertFalse($decision->applicable);
        self::assertContains(OssDerivationReason::DomesticRateOnCrossBorderB2c, $decision->notes);
        self::assertSame(1, $decision->toItemColumns()['oss_needs_manual_review']);
    }

    /**
     * U ODMÍTNUTÉ položky se poznámka připojit NESMÍ: hláška odmítnutí říká, co konkrétně
     * doplnit, a zpochybnění by ji přebilo. Doklad v přenesené daňové povinnosti se sazbou,
     * kterou tuzemsko nezná, je přesně ten průnik — rozpor „platí", ale řádek se nezapíše.
     */
    public function testRejectedItemKeepsItsRemedyInsteadOfTheContradictionNote(): void
    {
        $decision = $this->derive(self::SUP_ACTIVE, self::CLI_PL, 23.0, reverseCharge: true);

        self::assertTrue($decision->isRejected());
        self::assertNotContains(OssDerivationReason::DomesticRateOnCrossBorderB2c, $decision->notes,
            'Zpochybnění patří k rozhodnutí, ne k odmítnutí — tam by přebilo návod, co doplnit.');
        self::assertStringContainsString('přenesené daňové povinnosti', (string) $decision->rejectionMessage);
    }

    // ── Protipóly: úzkost podmínky ───────────────────────────────────────────

    /**
     * Každá podmínka rozporu má vlastní protipól. Kdyby kterákoli chyběla, dostal by
     * varování běžný provoz — a hláška, která svítí na každé druhé faktuře, je horší než
     * žádná: uživatel ji přestane číst.
     *
     * @return list<array{0:int, 1:int, 2:float, 3:string, 4:OssDerivationReason}>
     */
    public static function silentCases(): array
    {
        return [
            'firma NEMÁ registraci do OSS' => [
                self::SUP_NO_OSS, self::CLI_PL, 21.0, self::TAX_DATE, OssDerivationReason::SupplierOssDisabled,
            ],
            'datum plnění je PŘED platností registrace' => [
                self::SUP_BOUNDED, self::CLI_PL, 21.0, '2026-03-15', OssDerivationReason::SupplierOssNotValidOnDate,
            ],
            'datum plnění je PO platnosti registrace' => [
                self::SUP_BOUNDED, self::CLI_PL, 21.0, '2027-02-01', OssDerivationReason::SupplierOssNotValidOnDate,
            ],
            'odběratel je tuzemský' => [
                self::SUP_ACTIVE, self::CLI_CZ, 21.0, self::TAX_DATE, OssDerivationReason::ClientDomestic,
            ],
            'odběratel má DIČ (B2B)' => [
                self::SUP_ACTIVE, self::CLI_PL_VAT, 21.0, self::TAX_DATE, OssDerivationReason::ClientHasVatId,
            ],
            'odběratel je mimo EU' => [
                self::SUP_ACTIVE, self::CLI_US, 21.0, self::TAX_DATE, OssDerivationReason::ClientNotEu,
            ],
            'osvobozený řádek (0 %)' => [
                self::SUP_ACTIVE, self::CLI_PL, 0.0, self::TAX_DATE, OssDerivationReason::ZeroRate,
            ],
        ];
    }

    #[DataProvider('silentCases')]
    public function testNarrowConditionKeepsOrdinaryDocumentsSilent(
        int $supplierId,
        int $clientId,
        float $rate,
        string $taxDate,
        OssDerivationReason $reason,
    ): void {
        $decision = $this->derive($supplierId, $clientId, $rate, $taxDate);

        self::assertSame($reason, $decision->reason);
        self::assertFalse($decision->applicable);
        self::assertFalse($decision->isRejected(), 'Tuzemská sazba je tu legitimní, nemá se odmítat.');
        self::assertNotContains(OssDerivationReason::DomesticRateOnCrossBorderB2c, $decision->notes);
        self::assertFalse($decision->needsManualReview());
        self::assertSame(0, $decision->toItemColumns()['oss_needs_manual_review']);
        self::assertSame([], $decision->toReport()['warnings'], 'Běžný doklad nemá co hlásit.');
    }

    /**
     * Hraniční dny platnosti registrace patří DOVNITŘ. Test protipólu výše by jinak
     * „procházel" i tehdy, kdyby se rozpor nehlásil nikdy — stačilo by porovnávat data
     * o den vedle.
     *
     * @return list<array{0:string}>
     */
    public static function boundaryDates(): array
    {
        return [
            'první den registrace' => ['2026-06-01'],
            'poslední den registrace' => ['2026-12-31'],
        ];
    }

    #[DataProvider('boundaryDates')]
    public function testRegistrationBoundaryDaysAreInsideAndStillFlagged(string $taxDate): void
    {
        $decision = $this->derive(self::SUP_BOUNDED, self::CLI_PL, 21.0, $taxDate);

        self::assertSame(OssDerivationReason::RateMatchesDomesticOnly, $decision->reason);
        self::assertContains(OssDerivationReason::DomesticRateOnCrossBorderB2c, $decision->notes);
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
        // Deriver na `invoice_items` zajímá jediné: existuje `oss_applicable`, tedy
        // proběhla migrace 0137?
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
    }

    private function seed(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO countries (id, iso2, is_eu) VALUES (?, ?, ?)');
        foreach ([[1, 'CZ', 1], [2, 'PL', 1], [3, 'US', 0]] as $row) {
            $stmt->execute($row);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO supplier
                (id, country_id, oss_enabled, oss_valid_from, oss_valid_to, oss_identification_country, cz_nace_code)
             VALUES (?, ?, ?, ?, ?, ?, NULL)'
        );
        $stmt->execute([self::SUP_ACTIVE, 1, 1, '2026-01-01', null, 'CZ']);
        $stmt->execute([self::SUP_NO_OSS, 1, 0, null, null, 'CZ']);
        $stmt->execute([self::SUP_BOUNDED, 1, 1, '2026-06-01', '2026-12-31', 'CZ']);

        $stmt = $this->pdo->prepare('INSERT INTO clients (id, country_id, dic) VALUES (?, ?, ?)');
        $stmt->execute([self::CLI_PL, 2, null]);
        $stmt->execute([self::CLI_PL_VAT, 2, 'PL1234567890']);
        $stmt->execute([self::CLI_CZ, 1, null]);
        $stmt->execute([self::CLI_US, 3, null]);

        // Výřez číselníku členských států podle migrací 1152/1294. Podstatné je, že se
        // sazby obou zemí NEPŘEKRÝVAJÍ: 21 % a 12 % zná jen ČR, 23 % a 8 % jen Polsko.
        // Kdyby se překrývaly, místo plnění by z procenta neplynulo a řádek by šel do OSS
        // jako nejednoznačný — tedy jinou větví, než jakou má tenhle test hlídat.
        $stmt = $this->pdo->prepare(
            'INSERT INTO oss_member_state_rates (country, rate_type, rate_percent, valid_from, valid_to)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ([
            ['CZ', 'standard', '21.00', '2024-01-01', null],
            ['CZ', 'reduced', '12.00', '2024-01-01', null],
            ['PL', 'standard', '23.00', '2021-07-01', null],
            ['PL', 'reduced', '8.00', '2021-07-01', null],
        ] as $row) {
            $stmt->execute($row);
        }
    }
}
