<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Oss;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Oss\OssDerivationReason;
use MyInvoice\Service\Oss\OssItemDeriver;
use MyInvoice\Service\Oss\OssRateCodebook;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Výchozí nastavení OSS v kartě odběratele (migrace 1298).
 *
 * Karta je DATA pro rozhodnutí, nikdy druhá autorita nad místem plnění. Test zamyká
 * obě strany té hranice:
 *  - `oss_mode = 'never'` smí OSS jedině UBRAT (a hodnota „vždy OSS" neexistuje),
 *  - `oss_default_supply_type` se uplatní jen tam, kde derivace dnes DOHADUJE — tedy
 *    pod měrnou jednotkou položky, která je důkazem z konkrétního řádku.
 *
 * Bez sloupců (instalace bez migrace 1298) se derivace musí chovat přesně jako dřív;
 * na to je poslední test.
 */
final class OssClientDefaultsTest extends TestCase
{
    private PDO $pdo;

    private const SUP_CZ = 1;

    /** Polský spotřebitel bez DIČ, karta beze změn (oss_mode = 'auto'). */
    private const CLI_PL = 1;
    /** Týž odběratel, ale s vypnutým OSS v kartě. */
    private const CLI_PL_NEVER = 2;
    /** Týž odběratel s výchozím typem plnění „zboží". */
    private const CLI_PL_GOODS = 3;
    /** Týž odběratel s výchozím typem plnění „služba". */
    private const CLI_PL_SERVICES = 4;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema(withDefaults: true);
        $this->seed(withDefaults: true);
    }

    // ── Režim: smí ubrat, nesmí přidat ───────────────────────────────────────

    public function testClientWithoutModeStillGetsOss(): void
    {
        $decision = $this->derive(self::CLI_PL, 'kg');

        self::assertTrue($decision->applicable, 'kontrolní vzorek — bez nastavení karty se nic nemění');
    }

    public function testClientMarkedNeverIsExcludedFromOss(): void
    {
        $decision = $this->derive(self::CLI_PL_NEVER, 'kg');

        self::assertFalse($decision->applicable);
        self::assertSame(OssDerivationReason::ClientOssExcluded, $decision->reason);
    }

    /**
     * Vyloučení z OSS NESMÍ otevřít druhou cestu, kterou cizí daň spadne do tuzemského
     * přiznání: číselník členských států 23 % v ČR nepotvrdí, takže se položka odmítne.
     * Tohle je celý důvod, proč je 'never' bezpečná hodnota a 'always' by nebyla.
     */
    public function testExcludedClientWithForeignRateIsRejectedNotSilentlyDomestic(): void
    {
        $decision = $this->derive(self::CLI_PL_NEVER, 'kg');

        self::assertTrue($decision->isRejected(), 'cizí sazba se u vyloučeného odběratele nesmí stát tuzemskou');
        self::assertStringContainsString('23', (string) $decision->rejectionMessage);
    }

    /**
     * Vyloučený odběratel nesmí u TUZEMSKÉ sazby generovat příznak „k ručnímu posouzení":
     * rozpor „přeshraniční B2C za tuzemskou sazbu" je u něj přesně to, co uživatel
     * nastavil, a označit každou jeho fakturu znamená udělat z příznaku šum.
     */
    public function testExcludedClientDoesNotRaiseCrossBorderContradiction(): void
    {
        $decision = $this->derive(self::CLI_PL_NEVER, 'kg', 21.0);

        self::assertFalse($decision->applicable);
        self::assertFalse($decision->needsManualReview());
        self::assertNotContains(OssDerivationReason::DomesticRateOnCrossBorderB2c, $decision->notes);
    }

    /** Kontrola téhož bez vyloučení — rozpor tam být MUSÍ, jinak by test výš nic neměřil. */
    public function testNormalClientStillRaisesCrossBorderContradiction(): void
    {
        $decision = $this->derive(self::CLI_PL, 'kg', 21.0);

        self::assertTrue($decision->needsManualReview());
        self::assertContains(OssDerivationReason::DomesticRateOnCrossBorderB2c, $decision->notes);
    }

    // ── Výchozí typ plnění: pod jednotkou, nad NACE ──────────────────────────

    /**
     * 'ks' je jednotka bez signálu — dřív z toho vypadla dosazená „služba" s varováním.
     * Uživatelská znalost z karty je lepší podklad než dosazení.
     */
    public function testClientDefaultSupplyTypeFillsWhatDeriverWouldGuess(): void
    {
        $decision = $this->derive(self::CLI_PL_GOODS, 'ks');

        self::assertSame('goods', $decision->supplyType);
        self::assertContains(OssDerivationReason::SupplyTypeFromClientDefault, $decision->notes);
        self::assertNotContains(OssDerivationReason::SupplyTypeDefaultServices, $decision->notes);
    }

    /** Bez defaultu v kartě zůstává dosazení „služba" i s varováním. */
    public function testWithoutClientDefaultTheGuessStays(): void
    {
        $decision = $this->derive(self::CLI_PL, 'ks');

        self::assertSame('services', $decision->supplyType);
        self::assertContains(OssDerivationReason::SupplyTypeDefaultServices, $decision->notes);
    }

    /**
     * Měrná jednotka je důkaz z KONKRÉTNÍHO řádku, default je vlastnost karty — jednotka
     * proto vyhrává. Jinak by e-shop, který má v kartě „zboží", vykázal i řádek
     * fakturovaný v hodinách jako zboží (a tím ve špatné sazbě státu spotřeby).
     */
    public function testUnitBeatsClientDefault(): void
    {
        $decision = $this->derive(self::CLI_PL_GOODS, 'hod');

        self::assertSame('services', $decision->supplyType);
        self::assertContains(OssDerivationReason::SupplyTypeFromUnit, $decision->notes);
    }

    public function testClientDefaultServicesIsHonoredToo(): void
    {
        $decision = $this->derive(self::CLI_PL_SERVICES, 'ks');

        self::assertSame('services', $decision->supplyType);
        self::assertContains(OssDerivationReason::SupplyTypeFromClientDefault, $decision->notes);
    }

    // ── Instalace bez migrace 1298 ───────────────────────────────────────────

    public function testWorksOnInstallationWithoutTheMigration(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema(withDefaults: false);
        $this->seed(withDefaults: false);

        $decision = $this->derive(self::CLI_PL, 'ks');

        self::assertTrue($decision->applicable);
        self::assertSame('services', $decision->supplyType);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function derive(int $clientId, string $unit, float $rate = 23.0): \MyInvoice\Service\Oss\OssItemDecision
    {
        $conn = new Connection($this->createStub(Config::class));
        (new \ReflectionClass($conn))->getProperty('pdo')->setValue($conn, $this->pdo);
        $deriver = new OssItemDeriver($conn, new OssRateCodebook($conn));

        return $deriver->derive($clientId > 0 ? self::SUP_CZ : 0, $deriver->clientContext($clientId), $rate, $unit, '2026-07-15', false);
    }

    private function createSchema(bool $withDefaults): void
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
            'CREATE TABLE clients (id INTEGER PRIMARY KEY, country_id INTEGER, dic TEXT'
            . ($withDefaults ? ", oss_mode TEXT DEFAULT 'auto', oss_default_supply_type TEXT" : '')
            . ')'
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
    }

    private function seed(bool $withDefaults): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO countries (id, iso2, is_eu) VALUES (?, ?, ?)');
        foreach ([[1, 'CZ', 1], [2, 'PL', 1]] as $row) {
            $stmt->execute($row);
        }

        $this->pdo->prepare(
            'INSERT INTO supplier (id, country_id, oss_enabled, oss_valid_from, oss_identification_country)
             VALUES (?, 1, 1, ?, ?)'
        )->execute([self::SUP_CZ, '2026-01-01', 'CZ']);

        if ($withDefaults) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO clients (id, country_id, dic, oss_mode, oss_default_supply_type) VALUES (?, 2, NULL, ?, ?)'
            );
            $stmt->execute([self::CLI_PL, 'auto', null]);
            $stmt->execute([self::CLI_PL_NEVER, 'never', null]);
            $stmt->execute([self::CLI_PL_GOODS, 'auto', 'goods']);
            $stmt->execute([self::CLI_PL_SERVICES, 'auto', 'services']);
        } else {
            $this->pdo->prepare('INSERT INTO clients (id, country_id, dic) VALUES (?, 2, NULL)')
                ->execute([self::CLI_PL]);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO oss_member_state_rates (country, rate_type, rate_percent, valid_from, valid_to)
             VALUES (?, ?, ?, ?, NULL)'
        );
        foreach ([
            ['CZ', 'standard', '21.00', '2024-01-01'],
            ['PL', 'standard', '23.00', '2021-07-01'],
        ] as $row) {
            $stmt->execute($row);
        }
    }
}
