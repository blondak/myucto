<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Oss;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Oss\OssItemPlanner;
use MyInvoice\Service\Oss\OssRateCodebook;
use MyInvoice\Service\Oss\OssItemDeriver;
use MyInvoice\Service\Vat\VatRateResolver;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Sdílený plánovač řádku vydané faktury — jediná cesta, kterou se OSS ptají ostatní
 * kanály (iDoklad, Fakturoid, AI extrakce vydané faktury).
 *
 * Test vznikl z nálezu OSS-11: OSS znal jediný vstupní kanál (import Pohoda XML /
 * ISDOC), takže po vyčištění historických dat začala nová faktura z e-shopu zase
 * vznikat bez OSS. Druhá polovina nálezu je párování sazby: iDoklad, Fakturoid i AI
 * extraktor si psaly vlastní „nejbližší / první shodné procento" napříč celou tabulkou
 * `vat_rates`, bez země, bez platnosti k datu a bez `is_reverse_charge`.
 *
 * Fixture je záměrně TÁŽ past jako v {@see OssItemDeriverTest}: `vat_rates` obsahuje
 * uživatelem založený řádek 'PL-23-vlastni' s procentem 23 a zemí **CZ** (formulář má
 * CZ předvyplněnou). Kanál, který se ptá jen na procento, ho trefí a polský doklad tím
 * dostane českou sazbu.
 */
final class OssItemPlannerTest extends TestCase
{
    private PDO $pdo;
    private OssItemPlanner $planner;

    /** Dodavatel v ČR se zapnutým OSS od 1. 1. 2026. */
    private const SUP_CZ = 1;

    /** Polský spotřebitel bez DIČ. */
    private const CLI_PL = 1;
    /** Český odběratel. */
    private const CLI_CZ = 2;
    /** Polský odběratel S DIČ — B2B, OSS se neuplatní. */
    private const CLI_PL_VAT = 3;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->seed();
        $this->planner = $this->newPlanner();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Hlavní scénář: e-shop fakturuje polskému spotřebiteli
    // ─────────────────────────────────────────────────────────────────────────

    public function testPolishConsumerLineGetsOssColumnsAndPolishRate(): void
    {
        $items = $this->planner->planIssuedItems(self::SUP_CZ, self::CLI_PL, '2026-07-15', false, [
            ['description' => 'Sada hrnků', 'quantity' => 2.0, 'unit' => 'kg', 'vat_rate' => 23.0],
        ]);

        self::assertCount(1, $items);
        self::assertSame(1, $items[0]['oss_applicable']);
        self::assertSame('PL', $items[0]['oss_consumer_country']);
        self::assertSame('standard', $items[0]['oss_rate_type']);
        self::assertSame('goods', $items[0]['oss_supply_type'], 'jednotka kg je signál zboží');
        self::assertSame(0, $items[0]['oss_needs_manual_review']);
        self::assertSame(
            $this->rateId('PL-23'),
            $items[0]['vat_rate_id'],
            'sazba se hledá ve STÁTĚ SPOTŘEBY — uživatelský řádek PL-23-vlastni má zemi CZ a nesmí se trefit',
        );
        self::assertArrayNotHasKey('vat_rate', $items[0], 'procento z dokladu se převedlo na vat_rate_id');
        self::assertSame('Sada hrnků', $items[0]['description'], 'ostatní pole položky zůstávají');
    }

    public function testDomesticLineResolvesRateInSupplierCountry(): void
    {
        $items = $this->planner->planIssuedItems(self::SUP_CZ, self::CLI_CZ, '2026-07-15', false, [
            ['description' => 'Konzultace', 'quantity' => 1.0, 'unit' => 'hod', 'vat_rate' => 21.0],
        ]);

        self::assertSame(0, $items[0]['oss_applicable']);
        self::assertNull($items[0]['oss_consumer_country']);
        self::assertSame($this->rateId('CZ-21'), $items[0]['vat_rate_id']);
    }

    /**
     * B2B do Polska: OSS se neuplatní (odběratel má DIČ), ale sazba 23 % v ČR podle
     * číselníku členských států NEPLATÍ — invariant proti úniku takový řádek odmítne,
     * místo aby ho tiše prohlásil za tuzemský.
     */
    public function testForeignRateOnB2bLineRejectsWholeDocument(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Položka č\. 1: /');

        $this->planner->planIssuedItems(self::SUP_CZ, self::CLI_PL_VAT, '2026-07-15', false, [
            ['description' => 'Licence', 'quantity' => 1.0, 'unit' => 'ks', 'vat_rate' => 23.0],
        ]);
    }

    /** Hláška musí říct, KTERÝ řádek doklad shodil — u dokladu o deseti položkách jinak. */
    public function testRejectionMessageNamesTheOffendingLineNumber(): void
    {
        $this->expectExceptionMessageMatches('/^Položka č\. 2: /');

        $this->planner->planIssuedItems(self::SUP_CZ, self::CLI_PL_VAT, '2026-07-15', false, [
            ['description' => 'První', 'quantity' => 1.0, 'unit' => 'ks', 'vat_rate' => 21.0],
            ['description' => 'Druhá', 'quantity' => 1.0, 'unit' => 'ks', 'vat_rate' => 23.0],
        ]);
    }

    /**
     * Nenalezená sazba na VYDANÉ straně je tvrdá chyba dokladu, ne tichá náhrada.
     * Dřív se dosadila „nejbližší" (23 % → českých 21 %), čímž doklad změnil odvedenou daň.
     */
    public function testUnknownRateInConsumerCountryRejectsInsteadOfSubstituting(): void
    {
        $this->pdo->exec("DELETE FROM vat_rates WHERE code = 'PL-23'");
        $planner = $this->newPlanner();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Sazba 23 % pro PL/');

        $planner->planIssuedItems(self::SUP_CZ, self::CLI_PL, '2026-07-15', false, [
            ['description' => 'Sada hrnků', 'quantity' => 1.0, 'unit' => 'kg', 'vat_rate' => 23.0],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Soudržnost DOKLADU — platí na VŠECH kanálech, které jdou přes plánovač
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Doklad rozpadlý mezi OSS podání a tuzemské přiznání. Kontrolu dosud volal jen
     * editor a souborový import; iDoklad, Fakturoid ani AI extrakce o ní nevěděly,
     * přestože rozpor umí vyrobit stejně — a e-shop, kvůli kterému tahle vlna vznikla,
     * fakturuje právě odtud.
     *
     * Příznak musí nést OBĚ strany rozporu: OSS řádek je ta polovina, kterou uživatel
     * uvidí v náhledu podání, tuzemský ta, kterou má opravdu prověřit.
     */
    public function testMixedOssAndDomesticDocumentIsFlaggedOnEveryChannel(): void
    {
        $warnings = [];
        $items = $this->planner->planIssuedItems(self::SUP_CZ, self::CLI_PL, '2026-07-15', false, [
            ['description' => 'Sada hrnků', 'quantity' => 1.0, 'unit' => 'kg', 'vat_rate' => 23.0],
            ['description' => 'Montáž', 'quantity' => 1.0, 'unit' => 'hod', 'vat_rate' => 21.0],
        ], $warnings);

        self::assertSame(1, $items[0]['oss_applicable']);
        self::assertSame(0, $items[1]['oss_applicable'], 'sazba 21 % v PL neplatí, řádek zůstal tuzemský');

        self::assertSame(1, $items[0]['oss_document_contradiction']);
        self::assertSame(1, $items[1]['oss_document_contradiction']);
        self::assertSame(1, $items[0]['oss_needs_manual_review']);
        self::assertSame(1, $items[1]['oss_needs_manual_review']);

        $joined = implode("\n", $warnings);
        self::assertStringContainsString('Doklad si protiřečí', $joined);
        self::assertStringContainsString('PL', $joined, 'hláška pojmenuje stát spotřeby');
        self::assertStringContainsString('CZ', $joined, 'i zemi dodavatele — tuzemsko není zadrátované');
    }

    /** Soudržný doklad se nesmí označit — jinak by se z příznaku stal šum. */
    public function testCoherentDocumentCarriesNoContradictionFlag(): void
    {
        $warnings = [];
        $items = $this->planner->planIssuedItems(self::SUP_CZ, self::CLI_PL, '2026-07-15', false, [
            ['description' => 'Sada hrnků', 'quantity' => 1.0, 'unit' => 'kg', 'vat_rate' => 23.0],
            ['description' => 'Poštovné', 'quantity' => 1.0, 'unit' => 'ks', 'vat_rate' => 0.0],
        ], $warnings);

        self::assertArrayNotHasKey('oss_document_contradiction', $items[0]);
        self::assertArrayNotHasKey('oss_document_contradiction', $items[1]);
        self::assertStringNotContainsString('protiřečí', implode("\n", $warnings));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Přijatá strana — jen párování sazby, ale se stejnými filtry
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Nula NESMÍ trefit reverse-charge sazbu: CZ-0 i CZ-RC mají `rate_percent = 0.00`
     * a dřívější „první shodné procento" je rozlišilo jen pořadím řádků v tabulce.
     * Na přijaté faktuře je to přímo nárok na odpočet.
     */
    public function testZeroRateNeverMatchesReverseChargeRate(): void
    {
        $match = $this->planner->resolveDomesticRate(self::SUP_CZ, 0.0, '2026-07-15');

        self::assertSame($this->rateId('CZ-0'), $match->id);
    }

    /**
     * Historický doklad se sazbou platnou k jeho datu se napáruje bez varování — kvůli
     * tomuhle případu si Fakturoid import načítal sazby BEZ filtru na platnost.
     */
    public function testHistoricRateValidAtDocumentDateMatchesSilently(): void
    {
        $match = $this->planner->resolveDomesticRate(self::SUP_CZ, 15.0, '2022-05-10');

        self::assertSame($this->rateId('CZ-15'), $match->id);
        self::assertSame('', $match->message);
    }

    /** Sazba, která k datu plnění NEPLATÍ, se použije, ale musí to být vidět. */
    public function testRateOutsideValidityMatchesWithWarning(): void
    {
        $match = $this->planner->resolveDomesticRate(self::SUP_CZ, 15.0, '2026-07-15');

        self::assertSame($this->rateId('CZ-15'), $match->id);
        self::assertNotSame('', $match->message, 'shoda mimo platnost musí být vidět');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pre-flight číselníku
    // ─────────────────────────────────────────────────────────────────────────

    public function testCodebookProblemIsSilentWhenCodebookIsSeeded(): void
    {
        self::assertNull($this->planner->codebookProblem(self::SUP_CZ));
    }

    /**
     * Prázdný číselník = na každou zemi odpověď „nevím" = odmítnutý každý řádek se
     * sazbou > 0 %, tedy i běžná česká faktura českému odběrateli. Kanál to musí zjistit
     * JEDNOU na začátku běhu, ne N-krát po dokladech.
     */
    public function testCodebookProblemNamesMissingMigrationAndAppendsChannelConsequence(): void
    {
        $this->pdo->exec('DELETE FROM oss_member_state_rates');
        $problem = $this->newPlanner()->codebookProblem(self::SUP_CZ, 'Import vydaných faktur se nespustil.');

        self::assertNotNull($problem);
        self::assertStringContainsString('migrate.php', $problem);
        self::assertStringEndsWith('Import vydaných faktur se nespustil.', $problem);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function newPlanner(): OssItemPlanner
    {
        $conn = new Connection($this->createStub(Config::class));
        (new \ReflectionClass($conn))->getProperty('pdo')->setValue($conn, $this->pdo);

        return new OssItemPlanner(
            $conn,
            new OssItemDeriver($conn, new OssRateCodebook($conn)),
            new OssRateCodebook($conn),
            new VatRateResolver($conn),
        );
    }

    private function rateId(string $code): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM vat_rates WHERE code = ?');
        $stmt->execute([$code]);

        return (int) $stmt->fetchColumn();
    }

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
        $this->pdo->exec(
            "CREATE TABLE clients (
                id INTEGER PRIMARY KEY,
                country_id INTEGER,
                dic TEXT,
                oss_mode TEXT DEFAULT 'auto',
                oss_default_supply_type TEXT
            )"
        );
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
        $stmt = $this->pdo->prepare('INSERT INTO countries (id, iso2, is_eu) VALUES (?, ?, ?)');
        foreach ([[1, 'CZ', 1], [2, 'PL', 1]] as $row) {
            $stmt->execute($row);
        }

        $this->pdo->prepare(
            'INSERT INTO supplier (id, country_id, oss_enabled, oss_valid_from, oss_identification_country)
             VALUES (?, 1, 1, ?, ?)'
        )->execute([self::SUP_CZ, '2026-01-01', 'CZ']);

        $stmt = $this->pdo->prepare('INSERT INTO clients (id, country_id, dic) VALUES (?, ?, ?)');
        $stmt->execute([self::CLI_PL, 2, null]);
        $stmt->execute([self::CLI_CZ, 1, null]);
        $stmt->execute([self::CLI_PL_VAT, 2, 'PL1234567890']);

        $stmt = $this->pdo->prepare(
            'INSERT INTO oss_member_state_rates (country, rate_type, rate_percent, valid_from, valid_to)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ([
            ['CZ', 'standard', '21.00', '2024-01-01', null],
            ['CZ', 'reduced', '12.00', '2024-01-01', null],
            ['PL', 'standard', '23.00', '2021-07-01', null],
        ] as $row) {
            $stmt->execute($row);
        }

        // 'PL-23-vlastni' = řádek, který si uživatel založí, protože formulář má CZ
        // předvyplněnou. Past na kanál, který se ptá jen na procento.
        $stmt = $this->pdo->prepare(
            'INSERT INTO vat_rates
                (code, rate_percent, country, label_cs, label_en, is_reverse_charge, valid_from, valid_to, display_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 10)'
        );
        foreach ([
            ['CZ-21', '21.00', 'CZ', 0, '2024-01-01', null],
            ['CZ-15', '15.00', 'CZ', 0, '2013-01-01', '2023-12-31'],
            ['CZ-0', '0.00', 'CZ', 0, '2024-01-01', null],
            ['CZ-RC', '0.00', 'CZ', 1, '2024-01-01', null],
            ['PL-23', '23.00', 'PL', 0, '2021-07-01', null],
            ['PL-23-vlastni', '23.00', 'CZ', 0, '2021-07-01', null],
        ] as [$code, $percent, $country, $rc, $from, $to]) {
            $stmt->execute([$code, $percent, $country, $code, $code, $rc, $from, $to]);
        }
    }
}
