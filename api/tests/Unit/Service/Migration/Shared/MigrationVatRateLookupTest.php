<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Shared\MigrationVatRateLookup;
use MyInvoice\Service\Vat\VatRateResolver;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Párování sazby převodů (Money S3, POHODA, PREMIER, Stereo NX) - dřív čtyři kopie téhož
 * dotazu. Poslední test drží důvod, proč převody zatím nejdou přes {@see VatRateResolver}.
 */
final class MigrationVatRateLookupTest extends TestCase
{
    private PDO $pdo;
    private Connection $db;

    protected function setUp(): void
    {
        $this->pdo = new \Pdo\Sqlite('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE vat_rates (id INTEGER PRIMARY KEY, code TEXT, country TEXT,
            rate_percent DECIMAL(5,2), is_reverse_charge INT DEFAULT 0, is_default INT DEFAULT 0,
            valid_from TEXT NULL, valid_to TEXT NULL, display_order INT DEFAULT 0)');
        $this->db = new Connection(new Config([]));
        (new \ReflectionProperty($this->db, 'pdo'))->setValue($this->db, $this->pdo);
    }

    /** @param array<string,mixed> $row */
    private function rate(array $row): void
    {
        $row += ['is_reverse_charge' => 0, 'is_default' => 0, 'valid_from' => null, 'valid_to' => null, 'display_order' => 0];
        $this->pdo->prepare('INSERT INTO vat_rates (id, code, country, rate_percent, is_reverse_charge, is_default, valid_from, valid_to, display_order)
            VALUES (:id, :code, :country, :rate_percent, :is_reverse_charge, :is_default, :valid_from, :valid_to, :display_order)')->execute($row);
    }

    public function testValidRowWinsThenDefaultThenLowestId(): void
    {
        $this->rate(['id' => 1, 'code' => 'CZ-21-OLD', 'country' => 'CZ', 'rate_percent' => 21, 'valid_to' => '2012-12-31', 'is_default' => 1]);
        $this->rate(['id' => 2, 'code' => 'CZ-21-A', 'country' => 'CZ', 'rate_percent' => 21, 'valid_from' => '2013-01-01']);
        $this->rate(['id' => 3, 'code' => 'CZ-21-B', 'country' => 'CZ', 'rate_percent' => 21, 'valid_from' => '2013-01-01', 'is_default' => 1]);
        $this->rate(['id' => 4, 'code' => 'CZ-RC', 'country' => 'CZ', 'rate_percent' => 21, 'is_reverse_charge' => 1, 'is_default' => 1]);
        $this->rate(['id' => 5, 'code' => 'SK-21', 'country' => 'SK', 'rate_percent' => 21, 'is_default' => 1]);

        $lookup = new MigrationVatRateLookup($this->db);
        self::assertSame(3, $lookup->find(21.0, '2020-06-30'));
        self::assertSame(1, $lookup->find(21.0, '2010-06-30'));
    }

    public function testOutOfValidityRowIsUsedWhenNoRowIsValid(): void
    {
        // Stock instalace: CZ-21 platí od 2024, historický doklad z 2019 ho stejně dostane.
        $this->rate(['id' => 7, 'code' => 'CZ-21', 'country' => 'CZ', 'rate_percent' => 21, 'valid_from' => '2024-01-01', 'is_default' => 1]);
        self::assertSame(7, (new MigrationVatRateLookup($this->db))->find(21.0, '2019-05-01'));
    }

    public function testNullValidFromCountsAsValid(): void
    {
        $this->rate(['id' => 1, 'code' => 'CZ-21-NULL', 'country' => 'CZ', 'rate_percent' => 21]);
        $this->rate(['id' => 2, 'code' => 'CZ-21', 'country' => 'CZ', 'rate_percent' => 21, 'valid_from' => '2024-01-01', 'is_default' => 1]);
        self::assertSame(1, (new MigrationVatRateLookup($this->db))->find(21.0, '2020-01-01'));
    }

    public function testMissingRateIsNullAndItIsRemembered(): void
    {
        $lookup = new MigrationVatRateLookup($this->db);
        self::assertNull($lookup->find(15.0, '2020-01-01'));
        // Převod do vat_rates nezapisuje; výsledek (i „nenalezeno") se drží v paměti instance.
        $this->rate(['id' => 9, 'code' => 'CZ-15', 'country' => 'CZ', 'rate_percent' => 15]);
        self::assertNull($lookup->find(15.0, '2020-01-01'));
        self::assertSame(9, (new MigrationVatRateLookup($this->db))->find(15.0, '2020-01-01'));
    }

    public function testRatePercentIsComparedRoundedToTwoDecimals(): void
    {
        $this->rate(['id' => 3, 'code' => 'CZ-12', 'country' => 'CZ', 'rate_percent' => 12]);
        self::assertSame(3, (new MigrationVatRateLookup($this->db))->find(12.001, '2024-01-01'));
    }

    /**
     * Charakterizační test: řádek bez `valid_from` bere převod jako platný, VatRateResolver
     * ho odsune do kroku 2 a vybere jiný řádek. Přechod na resolver by tak změnil
     * `vat_rate_id` historických dokladů - proto se nedělá v rámci čistého refaktoru.
     */
    public function testDiffersFromVatRateResolverOnRowWithoutValidFrom(): void
    {
        $this->rate(['id' => 1, 'code' => 'CZ-21-NULL', 'country' => 'CZ', 'rate_percent' => 21]);
        $this->rate(['id' => 2, 'code' => 'CZ-21', 'country' => 'CZ', 'rate_percent' => 21, 'valid_from' => '2024-01-01', 'is_default' => 1]);

        self::assertSame(1, (new MigrationVatRateLookup($this->db))->find(21.0, '2020-01-01'));
        self::assertSame(2, (new VatRateResolver($this->db))->resolve('CZ', 21.0, '2020-01-01')->id);
    }
}
