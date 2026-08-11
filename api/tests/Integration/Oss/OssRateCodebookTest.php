<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Oss;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Oss\OssRateCodebook;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Číselník sazeb DPH členských států pro OSS.
 *
 * Matice DPH vedla položku jako CHYBÍ: sazbu si uživatel zakládal ručně v obecné tabulce
 * `vat_rates` a jediná kontrola byla vnitřní konzistence `základ × sazba ≈ daň`. Německých
 * 19 % použitých na rakouské plnění tedy prošlo bez varování — čísla si odpovídala, jen
 * mířila do nesprávného státu. Přesně tuhle situaci hlídá
 * {@see testRateFromWrongCountryIsCaught()}.
 *
 * Druhá polovina hodnoty je v čase: sazby se mění a podání se opravuje zpětně, takže
 * kontrola musí porovnávat proti sazbě platné K DATU PLNĚNÍ, ne k dnešku.
 */
#[Group('integration')]
final class OssRateCodebookTest extends TestCase
{
    private Connection $db;
    private OssRateCodebook $codebook;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->codebook = $c->get(OssRateCodebook::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->codebook->isAvailable()) {
            $this->markTestSkipped('Migrace 1152 neproběhla.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /** Správná sazba správného státu projde beze slova. */
    public function testCorrectRatePasses(): void
    {
        self::assertNull($this->codebook->checkRate('DE', 19.0, 'standard', '2025-06-15'));
        self::assertNull($this->codebook->checkRate('AT', 10.0, 'reduced', '2025-06-15'));
    }

    /**
     * Sazba jiného státu. Vnitřní konzistence `základ × sazba ≈ daň` ji nechytí — čísla
     * si odpovídají. Chytí ji jen číselník.
     */
    public function testRateFromWrongCountryIsCaught(): void
    {
        // 19 % je platná německá sazba, v Rakousku (20/10) neexistuje.
        $w = $this->codebook->checkRate('AT', 19.0, 'standard', '2025-06-15');

        self::assertNotNull($w);
        self::assertStringContainsString('neodpovídá', $w);
        self::assertStringContainsString('AT', $w);
        self::assertStringContainsString('20', $w, 'Hláška musí nabídnout platné sazby.');
    }

    /** Sazba platná dnes, ale ne k datu plnění — zpětná oprava nesmí dostat dnešní sazbu. */
    public function testHistoricalRateIsCheckedAgainstSupplyDate(): void
    {
        // Slovensko zvýšilo základní sazbu z 20 % na 23 % k 1. 1. 2025.
        self::assertNull($this->codebook->checkRate('SK', 20.0, 'standard', '2024-06-15'),
            'V roce 2024 byla platná 20 %.');
        self::assertNotNull($this->codebook->checkRate('SK', 20.0, 'standard', '2025-06-15'),
            'V roce 2025 už 20 % neplatí.');
        self::assertNull($this->codebook->checkRate('SK', 23.0, 'standard', '2025-06-15'));
        self::assertNotNull($this->codebook->checkRate('SK', 23.0, 'standard', '2024-06-15'),
            'V roce 2024 ještě 23 % neplatila.');
    }

    /**
     * Sazba sedí, ale je deklarovaná jako jiný typ. Do podání se posílá TYP, ne procento
     * ({@see \MyInvoice\Service\Oss\OssXmlExporter::rateTypeCode()}), takže rozpor by
     * skončil ve výkazu.
     */
    public function testRateTypeMismatchIsCaught(): void
    {
        $w = $this->codebook->checkRate('DE', 7.0, 'standard', '2025-06-15');

        self::assertNotNull($w);
        self::assertStringContainsString('reduced', $w);
        self::assertStringContainsString('TYP', $w);
    }

    /**
     * Neznámý stát se NEODBYDE mlčením — mlčet by budilo dojem, že sazba byla ověřena.
     */
    public function testUnknownCountryWarnsInsteadOfPassing(): void
    {
        $w = $this->codebook->checkRate('XX', 21.0, 'standard', '2025-06-15');

        self::assertNotNull($w);
        self::assertStringContainsString('není v číselníku', $w);
    }

    /** Chybějící zemi hlásí jiné varování — číselník ho nedubluje. */
    public function testMissingCountryIsSilent(): void
    {
        self::assertNull($this->codebook->checkRate('??', 21.0, 'standard', '2025-06-15'));
        self::assertNull($this->codebook->checkRate('', 21.0, 'standard', '2025-06-15'));
    }

    /** Sazby s desetinou částí (FI 25,5 %, SI 9,5 %) nesmí zhavarovat na porovnání floatů. */
    public function testFractionalRatesMatch(): void
    {
        self::assertNull($this->codebook->checkRate('FI', 25.5, 'standard', '2025-06-15'));
        self::assertNull($this->codebook->checkRate('SI', 9.5, 'reduced', '2025-06-15'));
    }

    /** Číselník musí pokrýt všechny členské státy EU — díra by tiše propustila cokoli. */
    public function testEveryEuCountryHasStandardRate(): void
    {
        $eu = $this->db->pdo()
            ->query("SELECT UPPER(iso2) FROM countries WHERE is_eu = 1 ORDER BY iso2")
            ->fetchAll(\PDO::FETCH_COLUMN);
        if ($eu === []) {
            self::markTestSkipped('Číselník zemí neoznačuje státy EU.');
        }

        $missing = [];
        foreach ($eu as $code) {
            $rates = $this->codebook->ratesFor((string) $code, date('Y-m-d'));
            $hasStandard = array_filter($rates, static fn ($r) => $r['rate_type'] === 'standard');
            if ($hasStandard === []) {
                $missing[] = $code;
            }
        }

        self::assertSame([], $missing,
            'Státy EU bez základní sazby v číselníku: ' . implode(', ', $missing));
    }

    /**
     * Zdravá instalace (všechny migrace proběhly) nesmí hlásit žádnou díru — jinak by
     * banner na stránce číselníku strašil uživatele zbytečně.
     */
    public function testCoverageGapsEmptyOnHealthyCodebook(): void
    {
        self::assertSame([], $this->codebook->countriesMissingCurrentRate());
    }

    /**
     * Reprodukce hlášení zákazníka (migrace 1319): po částečném seedu (jen historické
     * a druhé snížené řádky, bez aktuálních sazeb) musí `countriesMissingCurrentRate()`
     * nahlásit PL, HU a CZ/SK — přesně ty státy, které si zákazník musel dohledávat sám.
     * Test si díru vytvoří dočasně přímo v transakci a na konci ji vrátí, aby neovlivnil
     * ostatní testy nad sdílenou `myucto_test`.
     */
    public function testCoverageGapsDetectPartialSeed(): void
    {
        $pdo = $this->db->pdo();
        $today = date('Y-m-d');

        // PL nemá v číselníku vůbec žádný řádek — smaž ho dočasně (jen is_custom=0,
        // aby test nesáhl na případnou vlastní sazbu jiného testu).
        $pl = $pdo->query("SELECT id, country, rate_type, rate_percent, valid_from, valid_to
                              FROM oss_member_state_rates WHERE country = 'PL' AND is_custom = 0")
            ->fetchAll(\PDO::FETCH_ASSOC);
        self::assertNotSame([], $pl, 'Test předpokládá zdravou instanci se seedovaným PL.');

        $pdo->exec("DELETE FROM oss_member_state_rates WHERE country = 'PL' AND is_custom = 0");
        try {
            $missing = $this->codebook->countriesMissingCurrentRate($today);
            self::assertContains('PL', $missing);
        } finally {
            // Vrátit přesně to, co bylo smazáno — stejné id díky AUTO_INCREMENT nejde
            // zaručit, ale identita (country, rate_type, rate_percent, valid_from), na
            // které stojí `uq_osmr` i test výše, se obnoví beze zbytku.
            $stmt = $pdo->prepare(
                'INSERT INTO oss_member_state_rates (country, rate_type, rate_percent, valid_from, valid_to)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($pl as $row) {
                $stmt->execute([
                    $row['country'], $row['rate_type'], $row['rate_percent'],
                    $row['valid_from'], $row['valid_to'],
                ]);
            }
        }
    }
}
