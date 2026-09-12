<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Geo;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\CodebookImporter;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Migrace 1828 doplní úplný číselník zemí ISO 3166-1 a nesmí sáhnout na to, co už
 * instalace v číselníku má: id (odkazují na ně klienti a dodavatelé), názvy, is_eu ani
 * země přidané ručně. Novou zemi nevloží, když má tabulka řádek se stejným iso2 nebo iso3.
 *
 * Vše v transakci s rollbackem. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class CountriesIso3166MigrationTest extends TestCase
{
    private const MIGRATION = '1828_countries_iso3166_full.sql';

    private Connection $db;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->db = Bootstrap::buildContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->db->pdo()->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testAddsWorldCountriesAndKeepsWhatTheInstallationHas(): void
    {
        $pdo = $this->db->pdo();

        // Ručně přidaná země s vlastním názvem a bez alpha-3 pod kódem, který migrace nese.
        $twId = $this->customCountry('TW', '', 'Tchajwan (vlastní)', '');
        // Ručně přidaná země pod jiným iso2, ale se stejným alpha-3 jako Fidži.
        $pdo->exec("DELETE FROM countries WHERE iso2 = 'FJ' AND NOT EXISTS (SELECT 1 FROM clients WHERE country_id = countries.id)");
        if ((int) $pdo->query("SELECT COUNT(*) FROM countries WHERE iso2 = 'FJ'")->fetchColumn() > 0) {
            self::markTestSkipped('Fidži je v testovací DB použité u klienta, případ se stejným iso3 nejde připravit.');
        }
        $customId = $this->customCountry('QF', 'FJI', 'Fidži (vlastní kód)', 'Fiji (custom code)');

        $before = $this->rows();
        $euBefore = (int) $pdo->query('SELECT COUNT(*) FROM countries WHERE is_eu = 1')->fetchColumn();

        $this->runMigration();
        $afterFirst = $this->rows();
        $this->runMigration();
        self::assertSame($afterFirst, $this->rows(), 'Opakovaný běh migrace nesmí nic změnit.');

        $after = $afterFirst;
        foreach ($before as $iso2 => $row) {
            self::assertArrayHasKey($iso2, $after);
            self::assertSame($row['id'], $after[$iso2]['id'], "Id země {$iso2} se nesmí změnit.");
            self::assertSame($row['name_cs'], $after[$iso2]['name_cs'], "Český název {$iso2} se nepřepisuje.");
            self::assertSame($row['is_eu'], $after[$iso2]['is_eu'], "is_eu {$iso2} se nemění.");
            if ($row['name_en'] !== '') {
                self::assertSame($row['name_en'], $after[$iso2]['name_en'], "Anglický název {$iso2} se nepřepisuje.");
            }
            if ($row['iso3'] !== '') {
                self::assertSame($row['iso3'], $after[$iso2]['iso3'], "iso3 {$iso2} se nepřepisuje.");
            }
        }

        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM countries WHERE UPPER(iso2) = 'TW'")->fetchColumn());
        self::assertSame($twId, $after['TW']['id']);
        self::assertSame('Tchajwan (vlastní)', $after['TW']['name_cs']);
        self::assertSame('TWN', $after['TW']['iso3'], 'Prázdné iso3 se doplní.');
        self::assertSame('Taiwan', $after['TW']['name_en'], 'Prázdný anglický název se doplní.');

        self::assertArrayNotHasKey('FJ', $after, 'Země se stejným iso3 pod jiným kódem už v číselníku je.');
        self::assertSame($customId, $after['QF']['id']);
        self::assertSame('Fidži (vlastní kód)', $after['QF']['name_cs']);

        foreach (['CN', 'RS', 'RU', 'TW', 'VN', 'ZA', 'MY', 'TH', 'HK'] as $iso2) {
            self::assertArrayHasKey($iso2, $after, "Po migraci chybí {$iso2}.");
            self::assertNotSame('', $after[$iso2]['name_cs'], "{$iso2} nemá český název.");
        }
        self::assertSame('Čína', $after['CN']['name_cs']);
        self::assertSame('Jihoafrická republika', $after['ZA']['name_cs']);

        $expected = $this->migrationCodes();
        self::assertCount(249, $expected, 'Migrace nese všech 249 kódů ISO 3166-1.');
        foreach ($expected as $iso2) {
            if ($iso2 !== 'FJ') {
                self::assertArrayHasKey($iso2, $after, "Po migraci chybí {$iso2}.");
            }
        }
        self::assertSame($euBefore, (int) $pdo->query('SELECT COUNT(*) FROM countries WHERE is_eu = 1')->fetchColumn(),
            'Nové země nejsou členské státy EU.');
    }

    public function testMoneyImporterMapsCountryNamesThroughMatcher(): void
    {
        $this->runMigration();
        $importer = Bootstrap::buildContainer()->get(CodebookImporter::class);
        $fromName = new \ReflectionMethod($importer, 'countryFromName');
        $domestic = new \ReflectionMethod($importer, 'isDomesticName');

        $ids = $this->db->pdo()->query('SELECT UPPER(iso2), id FROM countries')->fetchAll(PDO::FETCH_KEY_PAIR);
        $cases = [
            'Russian' => 'RU', 'MALAYSIA' => 'MY', 'Jihoafrická republik' => 'ZA', 'Slovenská republika' => 'SK',
            'NetherlandNizozemsko' => 'NL', 'Hong Kong' => 'HK', 'SRBSKO' => 'RS', 'De' => 'DE', 'Čína' => 'CN',
        ];
        foreach ($cases as $name => $iso2) {
            self::assertSame((int) $ids[$iso2], $fromName->invoke($importer, $name), $name);
        }

        self::assertNull($fromName->invoke($importer, 'Česká republika'), 'Tuzemsko nechá zemi firmy.');
        self::assertTrue($domestic->invoke($importer, 'Česká republika'));
        self::assertTrue($domestic->invoke($importer, 'ČR'));
        self::assertNull($fromName->invoke($importer, 'Atlantida'));
        self::assertFalse($domestic->invoke($importer, 'Atlantida'), 'Neznámý stát jde do protokolu jako upozornění.');
    }

    private function customCountry(string $iso2, string $iso3, string $nameCs, string $nameEn): int
    {
        $pdo = $this->db->pdo();
        $id = $pdo->prepare('SELECT id FROM countries WHERE iso2 = ?');
        $id->execute([$iso2]);
        $existing = $id->fetchColumn();
        if ($existing !== false) {
            $pdo->prepare('UPDATE countries SET iso3 = ?, name_cs = ?, name_en = ?, is_eu = 0 WHERE id = ?')
                ->execute([$iso3, $nameCs, $nameEn, $existing]);
            return (int) $existing;
        }
        $pdo->prepare('INSERT INTO countries (iso2, iso3, name_cs, name_en, is_eu) VALUES (?, ?, ?, ?, 0)')
            ->execute([$iso2, $iso3, $nameCs, $nameEn]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,array{id:int,iso3:string,name_cs:string,name_en:string,is_eu:int}> */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->db->pdo()->query('SELECT id, iso2, iso3, name_cs, name_en, is_eu FROM countries ORDER BY id')
                     ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[strtoupper((string) $r['iso2'])] = [
                'id' => (int) $r['id'],
                'iso3' => (string) $r['iso3'],
                'name_cs' => (string) $r['name_cs'],
                'name_en' => (string) $r['name_en'],
                'is_eu' => (int) $r['is_eu'],
            ];
        }
        return $rows;
    }

    /** @return list<string> */
    private function migrationCodes(): array
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 4) . '/db/migrations/' . self::MIGRATION);
        preg_match_all("/^\\('([A-Z]{2})','[A-Z]{3}',/m", $sql, $m);
        return $m[1];
    }

    private function runMigration(): void
    {
        $path = dirname(__DIR__, 4) . '/db/migrations/' . self::MIGRATION;
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->db->pdo()->exec($statement);
        }
    }
}
