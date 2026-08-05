<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Vat;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Vat\VatRateResolution;
use MyInvoice\Service\Vat\VatRateResolver;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Napárování sazby z dokladu na řádek `vat_rates`.
 *
 * Test vznikl z nálezu A-4: dosavadní párování při importu hledalo NEJBLIŽŠÍ procento
 * napříč celou tabulkou — bez země, bez platnosti k datu a bez `is_reverse_charge`.
 * Důsledky byly tři a všechny tiché: polská 23% položka dostala `vat_rate_id` české
 * sazby (a s ním kód „1“ = tuzemské plnění na ř. 1 přiznání), 23 % se mezi PL-23 a PT-23
 * rozhodovalo pořadím řádků v tabulce, a nulová sazba mohla trefit CZ-RC místo CZ-0.
 *
 * Testy proto asertují VÝBĚR, ne jen „něco se našlo“: kdyby resolver hledal napříč
 * zeměmi nebo bral nejbližší procento, každý z případů níže svítí červeně.
 */
final class VatRateResolverTest extends TestCase
{
    private PDO $pdo;
    private Connection $conn;
    private VatRateResolver $resolver;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->seed();

        $this->conn = new Connection($this->createStub(Config::class));
        (new \ReflectionClass($this->conn))->getProperty('pdo')->setValue($this->conn, $this->pdo);
        $this->resolver = $this->newResolver();
    }

    private function newResolver(): VatRateResolver
    {
        return new VatRateResolver($this->conn);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Shoda se vždy hledá JEN v požadované zemi
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 23 % existuje v PL i PT, 21 % v CZ. Bez filtru na zemi rozhodovalo pořadí řádků
     * v tabulce; polský doklad tak skončil na české sazbě.
     */
    public function testTwentyThreePercentForPolandHitsPolishRate(): void
    {
        $match = $this->resolver->resolve('PL', 23.0, '2026-07-15');

        self::assertTrue($match->found());
        self::assertSame('PL-23', $match->code);
        self::assertSame($this->rateId('PL-23'), $match->id);
        self::assertSame(VatRateResolution::Matched, $match->status);
        self::assertSame('', $match->message);
        self::assertNotSame($this->rateId('PT-23'), $match->id, 'nikdy portugalská sazba');
        self::assertNotSame($this->rateId('CZ-21'), $match->id, 'nikdy česká sazba');
    }

    /**
     * Past se sazbou 21 %: CZ-21 existuje, NL-21 ne. Kdyby resolver hledal napříč
     * zeměmi, dostal by nizozemský OSS řádek `vat_rate_id` české sazby — přesně stav,
     * který editor nezobrazí a validace odmítne.
     */
    public function testRateIsNeverMatchedAcrossCountries(): void
    {
        $match = $this->resolver->resolve('NL', 21.0, '2026-07-15');

        self::assertFalse($match->found());
        self::assertNull($match->id);
        self::assertSame(VatRateResolution::NoRateInCountry, $match->status);
        self::assertSame('NL', $match->country);
    }

    /** Zákaz „nejbližší“ sazby: 23 % v ČR neexistuje, i když 21 % je na dosah. */
    public function testNoNearestRateFallback(): void
    {
        $match = $this->resolver->resolve('CZ', 23.0, '2026-07-15');

        self::assertFalse($match->found());
        self::assertSame(VatRateResolution::NoRateInCountry, $match->status);
        self::assertStringContainsString('CZ-23', $match->message, 'hláška má pojmenovat konkrétní chybějící sazbu');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Platnost k datu
    // ─────────────────────────────────────────────────────────────────────────

    /** Slovensko: 20 % skončilo 31. 12. 2024, od 1. 1. 2025 platí 23 %. */
    public function testCurrentRateWinsOverExpiredOneOfSameCountry(): void
    {
        $match = $this->resolver->resolve('SK', 23.0, '2026-07-15');

        self::assertSame('SK-23', $match->code);
        self::assertSame(VatRateResolution::Matched, $match->status);
    }

    /**
     * Sazba platná jen do minulého roku se pro letošní datum plnění NEPOUŽIJE jako
     * platná shoda — projde jen s varováním, aby migrace historických dokladů nespadla.
     */
    public function testExpiredRateIsNotAValidMatchForCurrentDate(): void
    {
        $match = $this->resolver->resolve('SK', 20.0, '2026-07-15');

        self::assertNotSame(VatRateResolution::Matched, $match->status);
        self::assertSame(VatRateResolution::MatchedOutsideValidity, $match->status);
        self::assertTrue($match->isWarning());
        self::assertStringContainsString('platná k 15. 7. 2026', $match->message);
    }

    /** Když v téže zemi existuje platný i prošlý řádek téhož procenta, vyhrává platný. */
    public function testValidRowWinsOverExpiredRowWithSamePercent(): void
    {
        $match = $this->resolver->resolve('CZ', 15.0, '2026-07-15');

        self::assertSame('CZ-15-nova', $match->code);
        self::assertSame(VatRateResolution::Matched, $match->status);
    }

    /**
     * Historický tuzemský doklad: CZ-21 má `valid_from = 2024-01-01`, ale zákazník
     * migruje doklady z let 2013–2023. Striktní filtr by je odmítl všechny.
     */
    public function testHistoricalDomesticDocumentMatchesOutsideValidity(): void
    {
        $match = $this->resolver->resolve('CZ', 21.0, '2019-06-01');

        self::assertTrue($match->found());
        self::assertSame('CZ-21', $match->code);
        self::assertSame(VatRateResolution::MatchedOutsideValidity, $match->status);
        self::assertTrue($match->isWarning());
        self::assertStringContainsString('vat_rate_snapshot', $match->message);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reverse charge
    // ─────────────────────────────────────────────────────────────────────────

    /** CZ-0 i CZ-RC mají 0,00 — bez filtru je rozlišilo jen pořadí řádků v tabulce. */
    public function testZeroRateHitsExemptRateNotReverseCharge(): void
    {
        $match = $this->resolver->resolve('CZ', 0.0, '2026-07-15');

        self::assertSame('CZ-0', $match->code);
        self::assertSame($this->rateId('CZ-0'), $match->id);
        self::assertNotSame($this->rateId('CZ-RC'), $match->id);
    }

    /** Je-li v zemi POUZE reverse-charge sazba, výsledek je „nenalezeno“, ne ona. */
    public function testReverseChargeRateIsNeverReturnedEvenAsLastResort(): void
    {
        $match = $this->resolver->resolve('AT', 0.0, '2026-07-15');

        self::assertFalse($match->found());
        self::assertSame(VatRateResolution::NoRateInCountry, $match->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Autoritativní procento a tolerance
    // ─────────────────────────────────────────────────────────────────────────

    public function testPercentComesFromDatabaseNotFromDocument(): void
    {
        $match = $this->resolver->resolve('PL', 23.004, '2026-07-15');

        self::assertTrue($match->found(), 'tolerance 0,005 p. b. pokrývá zaokrouhlení DECIMAL(5,2)');
        self::assertSame(23.0, $match->ratePercent);
    }

    public function testPercentOutsideToleranceDoesNotMatch(): void
    {
        self::assertFalse($this->resolver->resolve('PL', 23.02, '2026-07-15')->found());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Služba je čistě čtecí — sazby nezakládá
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * `vat_rates` je GLOBÁLNÍ tabulka bez `supplier_id`, takže sazba založená z importu
     * jednoho nájemníka změní číselník celé instalaci. Žádná cesta resolverem proto do
     * tabulky nesmí zapsat, ani pro OSS.
     */
    public function testResolverNeverWritesToVatRates(): void
    {
        $before = $this->rateCount();

        $this->resolver->resolve('HU', 27.0, '2026-07-15');
        $this->resolver->resolve('SI', 9.5, '2026-07-15');
        $this->resolver->resolveBatch([['country' => 'HU', 'rate' => 27.0, 'on_date' => '2026-07-15']]);

        self::assertSame($before, $this->rateCount(), 'čtecí služba nesmí nic založit');
    }

    /**
     * Hláška o chybějící sazbě musí pojmenovat ZEMI. Formulář v Nastavení → Sazby DPH má
     * zemi předvyplněnou na CZ, takže rada „založte 23 % (kód PL-23)" spolehlivě vyrobí
     * řádek se zemí CZ — a ten se na polský doklad nenaváže.
     */
    public function testMissingRateMessageNamesTheCountryNotJustTheCode(): void
    {
        $match = $this->resolver->resolve('HU', 27.0, '2026-07-15');

        self::assertFalse($match->found());
        self::assertSame(VatRateResolution::NoRateInCountry, $match->status);
        self::assertStringContainsString('jako sazbu pro zemi HU', $match->message);
        self::assertStringContainsString('HU-27', $match->message);
        self::assertStringContainsString(
            'předvyplněnou na CZ',
            $match->message,
            'hláška musí VAROVAT před ponecháním výchozí země, ne ji nechat na uživateli',
        );
    }

    /**
     * Hláška se používá v OBOU rolích — pro OSS řádek se ptá na stát spotřeby, pro tuzemský
     * na zemi dodavatele. Ve druhé roli si dřívější znění protiřečilo: ve stejné větě radilo
     * založit cizí procento „jako sazbu pro zemi CZ" a varovalo, že „se zemí CZ by se plnění
     * vykázalo v tuzemském přiznání". Obojí je navíc nepravda — kam se plnění vykáže, řídí
     * `oss_applicable` na položce a číselník sazeb členských států, ne `country` v `vat_rates`.
     */
    public function testMissingRateMessageDoesNotContradictItselfWhenMatchingAgainstDomestic(): void
    {
        $message = $this->resolver->resolve('CZ', 23.0, '2026-07-15')->message;

        self::assertStringNotContainsString('tuzemském přiznání', $message, 'země u sazby o výkazu nerozhoduje');
        self::assertStringNotContainsString(
            'předvyplněnou na CZ',
            $message,
            'u tuzemské sazby je varování před předvyplněnou CZ nesmysl',
        );
        self::assertStringContainsString(
            'bývá plnění do jiného členského státu',
            $message,
            'sazba, která v zemi neplatí, má uživatele nasměrovat na OSS, ne na založení české sazby',
        );
    }

    /**
     * Doplněk téhož: hláška nesmí nikde pojmenovat český kód pro CIZÍ procento. „Založte
     * sazbu 27 % (kód CZ-27)" je přesně ten návod, po kterém maďarské plnění skončí na
     * ř. 1 tuzemského přiznání — tedy chyba, kvůli které celá tahle vlna vznikla.
     *
     * @return list<array{0:string, 1:float, 2:string}>
     */
    public static function foreignMissingRates(): array
    {
        return [
            'Maďarsko 27 %' => ['HU', 27.0, 'CZ-27'],
            'Slovinsko 9,5 %' => ['SI', 9.5, 'CZ-9.5'],
        ];
    }

    #[DataProvider('foreignMissingRates')]
    public function testMissingRateMessageNeverProposesACzechCodeForAForeignPercent(
        string $country,
        float $rate,
        string $forbidden,
    ): void {
        $message = $this->resolver->resolve($country, $rate, '2026-07-15')->message;

        self::assertStringNotContainsString($forbidden, $message);
        self::assertStringContainsString('pro zemi ' . $country, $message);
    }

    /**
     * A když už kód se stejným názvem existuje pod jinou zemí, hláška NESMÍ radit zakládat
     * další — `uq_vat_code` je na samotném kódu, takže druhý pokus spadne na kolizi, kterou
     * uživatel z chybové hlášky databáze nerozklíčuje.
     */
    public function testMessageForATakenCodeDoesNotProposeFoundingAnother(): void
    {
        $this->insertRate('HU-27', 27.0, 'CZ', '2021-07-01');

        $message = $this->newResolver()->resolve('HU', 27.0, '2026-07-15')->message;

        self::assertStringNotContainsString('Založte', $message);
        self::assertStringContainsString('opravte u něj zemi na HU', $message);
    }

    /**
     * Past `uq_vat_code`: uživatel si už kód 'PL-23' založil, ale se zemí CZ (formulář ji
     * má předvyplněnou). Druhý pokus se správnou zemí by spadl na UNIQUE, takže jediné
     * řešení je opravit zemi u existujícího řádku — a hláška to musí říct, ne poslat
     * uživatele zakládat další.
     */
    public function testMessageDetectsCodeTakenByAnotherCountry(): void
    {
        $this->insertRate('HU-27', 27.0, 'CZ', '2021-07-01');

        $match = $this->newResolver()->resolve('HU', 27.0, '2026-07-15');

        self::assertFalse($match->found(), 'sazba se zemí CZ se na maďarský řádek použít nesmí');
        self::assertStringContainsString('se zemí CZ', $match->message);
        self::assertStringContainsString('opravte u něj zemi na HU', $match->message);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // `vat_rates` není důkaz o místě plnění
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Kritický nález: `countryHasRate()` svádělo položit nad `vat_rates` otázku „je tahle
     * sazba tuzemská". `country` v tabulce ale vyplňuje uživatel do formuláře, který má CZ
     * předvyplněnou — zákazník z analýzy tam takhle má sazbu s kódem „PL-23" a zemí CZ,
     * takže odpověď zněla „ČR zná 23 %" a polské plnění se vrátilo na ř. 1 přiznání.
     * Autoritou je jedině číselník sazeb členských států; API, které umožňovalo tuhle
     * otázku položit, proto na resolveru být NESMÍ.
     */
    public function testResolverExposesNoDomesticKnowledgeApi(): void
    {
        $this->insertRate('PL-23-uzivatelska', 23.0, 'CZ', '2021-07-01');

        self::assertFalse(
            method_exists(VatRateResolver::class, 'countryHasRate'),
            'countryHasRate() se nesmí vrátit — vat_rates o místě plnění nerozhoduje',
        );
        self::assertTrue(
            $this->newResolver()->resolve('CZ', 23.0, '2026-07-15')->found(),
            'fixture musí obsahovat uživatelskou 23% sazbu se zemí CZ, jinak test nic nehlídá',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dávkové rozhraní pro backfill
    // ─────────────────────────────────────────────────────────────────────────

    public function testResolveBatchDeduplicatesRequests(): void
    {
        $out = $this->resolver->resolveBatch([
            ['country' => 'PL', 'rate' => 23.0, 'on_date' => '2026-07-15'],
            ['country' => 'pl', 'rate' => 23.0, 'on_date' => '2026-07-15'],
            ['country' => 'CZ', 'rate' => 21.0, 'on_date' => '2026-07-15'],
        ]);

        self::assertSame(['PL|23.00|2026-07-15', 'CZ|21.00|2026-07-15'], array_keys($out));
        self::assertSame('PL-23', $out['PL|23.00|2026-07-15']->code);
        self::assertSame('CZ-21', $out['CZ|21.00|2026-07-15']->code);
    }

    /** @return list<array{0:string}> */
    public static function countryCasings(): array
    {
        return [['pl'], ['Pl'], [' PL ']];
    }

    #[DataProvider('countryCasings')]
    public function testCountryIsNormalisedToUppercase(string $country): void
    {
        $match = $this->resolver->resolve($country, 23.0, '2026-07-15');

        self::assertSame('PL-23', $match->code);
        self::assertSame('PL', $match->country);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function createSchema(): void
    {
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
        // Tuzemská škála jako na stock instalaci (CZ-21 platí až od 2024-01-01)
        // plus sazby, které si zákazník sám založil pro migraci ze SuperFaktury.
        $this->insertRate('CZ-21', 21.0, 'CZ', '2024-01-01', null, 1);
        $this->insertRate('CZ-12', 12.0, 'CZ', '2024-01-01');
        // CZ-RC je záměrně PŘED CZ-0: dosavadní párování bralo první nejbližší procento,
        // takže o výsledku rozhodovalo pořadí řádků. V tomhle pořadí by vyhrála RC sazba.
        $this->insertRate('CZ-RC', 0.0, 'CZ', '2024-01-01', null, 0, 1);
        $this->insertRate('CZ-0', 0.0, 'CZ', '2024-01-01');
        $this->insertRate('CZ-15-stara', 15.0, 'CZ', '2013-01-01', '2023-12-31');
        $this->insertRate('CZ-15-nova', 15.0, 'CZ', '2024-01-01');
        $this->insertRate('PT-23', 23.0, 'PT', '2011-01-01');
        $this->insertRate('PL-23', 23.0, 'PL', '2011-01-01');
        $this->insertRate('SK-20', 20.0, 'SK', '2011-01-01', '2024-12-31');
        $this->insertRate('SK-23', 23.0, 'SK', '2025-01-01');
        // Rakousko má v tabulce POUZE reverse-charge sazbu.
        $this->insertRate('AT-RC', 0.0, 'AT', '2011-01-01', null, 0, 1);
    }

    private function insertRate(
        string $code,
        float $percent,
        string $country,
        string $validFrom,
        ?string $validTo = null,
        int $isDefault = 0,
        int $isReverseCharge = 0,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO vat_rates
                (code, rate_percent, country, label_cs, label_en, is_default, is_reverse_charge,
                 valid_from, valid_to, display_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 10)'
        )->execute([
            $code,
            number_format($percent, 2, '.', ''),
            $country,
            $code,
            $code,
            $isDefault,
            $isReverseCharge,
            $validFrom,
            $validTo,
        ]);
    }

    private function rateId(string $code): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM vat_rates WHERE code = ?');
        $stmt->execute([$code]);

        return (int) $stmt->fetchColumn();
    }

    private function rateCount(?string $country = null): int
    {
        if ($country === null) {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM vat_rates')->fetchColumn();
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM vat_rates WHERE country = ?');
        $stmt->execute([$country]);

        return (int) $stmt->fetchColumn();
    }
}
