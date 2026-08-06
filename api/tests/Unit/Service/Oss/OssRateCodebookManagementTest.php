<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Oss;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Oss\OssRateCodebook;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Správa číselníku sazeb členských států (OSS-9) a odlišení „chybí migrace" od
 * „chybí stát".
 *
 * Testuje se proti in-memory SQLite (stejný vzor jako {@see OssItemDeriverTest}) —
 * schéma tabulky je tu záměrně postavené ručně, aby šlo NEMÍT ji vůbec a NEMÍT nad ní
 * překryvné sloupce migrace 1296. Přesně ty dva stavy totiž rozhodují o hláškách, které
 * uživatele buď pošlou spustit migrace, nebo hledat neexistující chybu na dokladu.
 */
final class OssRateCodebookManagementTest extends TestCase
{
    private PDO $pdo;

    /** Ostrý seed migrace 1152 nese `is_custom = 0` — takový řádek je nedotknutelný. */
    private int $seedAt20 = 0;
    private int $seedAt10 = 0;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Chybějící migrace ≠ chybějící stát
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * BEZ OPRAVY PADÁ: `isAvailable()` vrátilo false → `ratesFor()` prázdno → uživatel
     * dostal u KAŽDÉ země hlášku „stát není v číselníku sazeb členských států". To je
     * nepravda o Německu i o všech ostatních a posílá hledat chybu na dokladu místo
     * v instalaci.
     */
    public function testMissingTableBlamesTheMigrationNotTheCountry(): void
    {
        $codebook = $this->codebook(withTable: false);

        $warning = $codebook->checkRate('DE', 19.0, 'standard', '2026-03-15');

        self::assertNotNull($warning);
        self::assertStringContainsString('migrace 1152', $warning);
        self::assertStringNotContainsString('stát není v číselníku', $warning,
            'Chybějící tabulka se nesmí vydávat za chybějící stát.');
    }

    /** Existující tabulka bez daného státu má naopak hlásit stát, ne migraci. */
    public function testMissingCountryStillBlamesTheCountry(): void
    {
        $codebook = $this->codebook(withTable: true);

        $warning = $codebook->checkRate('XX', 21.0, 'standard', '2026-03-15');

        self::assertNotNull($warning);
        self::assertStringContainsString('stát není v číselníku', $warning);
        self::assertStringNotContainsString('migrace 1152', $warning);
    }

    /** Bez migrace 1296 se číselník čte dál, jen ho nejde spravovat. */
    public function testWithoutManagementMigrationReadingStillWorksButWritingIsRefused(): void
    {
        $codebook = $this->codebook(withTable: true, withOverlay: false);

        self::assertTrue($codebook->isAvailable());
        self::assertFalse($codebook->isManageable());
        self::assertNull($codebook->checkRate('AT', 20.0, 'standard', '2026-03-15'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/migrace 1296/');
        $codebook->createCustom([
            'country' => 'AT', 'rate_type' => 'second_reduced',
            'rate_percent' => 13.0, 'valid_from' => '2026-01-01',
        ], 1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Doplnění vlastní sazby
    // ─────────────────────────────────────────────────────────────────────────

    /** Doplněná sazba se hned použije při ověření dokladu — o to celé jde. */
    public function testCustomRateIsImmediatelyUsedForVerification(): void
    {
        $codebook = $this->codebook(withTable: true);

        self::assertNotNull($codebook->checkRate('AT', 13.0, 'second_reduced', '2026-03-15'),
            'Bez doplnění se 13 % v Rakousku ověřit nedá.');

        $codebook->createCustom([
            'country' => 'AT', 'rate_type' => 'second_reduced',
            'rate_percent' => 13.0, 'valid_from' => '2021-07-01', 'note' => 'kultura, ubytování',
        ], 7);

        self::assertNull($codebook->checkRate('AT', 13.0, 'second_reduced', '2026-03-15'));
    }

    public function testCustomRateIsMarkedAsCustom(): void
    {
        $codebook = $this->codebook(withTable: true);
        $id = $codebook->createCustom([
            'country' => 'at', 'rate_type' => 'parking',
            'rate_percent' => 13.0, 'valid_from' => '2026-01-01',
        ], 7);

        $row = $codebook->find($id);

        self::assertNotNull($row);
        self::assertSame(1, (int) $row['is_custom'], 'Uživatelský řádek musí být poznat.');
        self::assertSame('AT', (string) $row['country'], 'ISO2 se normalizuje na velká písmena.');
    }

    public function testDuplicateCustomRateIsRejected(): void
    {
        $codebook = $this->codebook(withTable: true);
        $data = ['country' => 'AT', 'rate_type' => 'second_reduced',
                 'rate_percent' => 13.0, 'valid_from' => '2026-01-01'];
        $codebook->createCustom($data, 7);

        $this->expectException(\RuntimeException::class);
        $codebook->createCustom($data, 7);
    }

    public function testInvalidRateTypeIsRejected(): void
    {
        $codebook = $this->codebook(withTable: true);

        $this->expectException(\InvalidArgumentException::class);
        $codebook->createCustom([
            'country' => 'AT', 'rate_type' => 'super_reduced',
            'rate_percent' => 5.0, 'valid_from' => '2026-01-01',
        ], 7);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ochrana seedu
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Identita seedovaného řádku je čtveřice (country, rate_type, rate_percent,
     * valid_from) — na ní stojí `WHERE NOT EXISTS` v migraci. Kdyby ji uživatel přepsal,
     * další běh migrace by seed vložil ZNOVU vedle uživatelovy verze a k témuž datu by
     * platily dvě sazby téhož typu.
     */
    public function testSeededRowCannotBeEdited(): void
    {
        $codebook = $this->codebook(withTable: true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Seedovaný řádek/');
        $codebook->update($this->seedAt20, ['rate_percent' => 21.0], 7);
    }

    /** Mazání seedu by jen předstíralo účinek — příští migrace řádek vrátí. */
    public function testSeededRowCannotBeDeleted(): void
    {
        $codebook = $this->codebook(withTable: true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Vyřaďte/');
        $codebook->delete($this->seedAt20);
    }

    /**
     * Legitimní cesta u zastaralého seedu: zkrátit mu platnost a doplnit novou sazbu.
     * Seedovaná data zůstanou nedotčená, takže je migrace pořád pozná jako svá.
     */
    public function testShorteningSeedValidityRetiresTheOldRateWithoutTouchingSeedData(): void
    {
        $codebook = $this->codebook(withTable: true);

        $codebook->update($this->seedAt20, ['valid_to_override' => '2026-12-31'], 7);
        $codebook->createCustom([
            'country' => 'AT', 'rate_type' => 'standard',
            'rate_percent' => 21.0, 'valid_from' => '2027-01-01',
        ], 7);

        self::assertNull($codebook->checkRate('AT', 20.0, 'standard', '2026-06-15'),
            'Do konce platnosti stará sazba pořád platí.');
        self::assertNotNull($codebook->checkRate('AT', 20.0, 'standard', '2027-06-15'),
            'Po zkrácení platnosti už stará sazba platit nesmí.');
        self::assertNull($codebook->checkRate('AT', 21.0, 'standard', '2027-06-15'));

        $seed = $codebook->find($this->seedAt20);
        self::assertSame('20', (string) (int) $seed['rate_percent']);
        self::assertSame('2021-07-01', (string) $seed['valid_from']);
        self::assertNull($seed['valid_to'], 'Vlastní sloupec seedu se nesmí přepsat — jen překryv.');
    }

    /** Vyřazený řádek se neověřuje vůbec, ať platnost říká cokoli. */
    public function testDisabledRowDropsOutOfVerification(): void
    {
        $codebook = $this->codebook(withTable: true);
        self::assertNull($codebook->checkRate('AT', 10.0, 'reduced', '2026-06-15'));

        $codebook->update($this->seedAt10, ['disabled' => true], 7);

        self::assertNotNull($codebook->checkRate('AT', 10.0, 'reduced', '2026-06-15'));

        $codebook->update($this->seedAt10, ['disabled' => false], 7);

        self::assertNull($codebook->checkRate('AT', 10.0, 'reduced', '2026-06-15'),
            'Vyřazení musí jít vrátit — jinak by šlo číselník rozbít neopravitelně.');
    }

    public function testEndOfValidityBeforeItsStartIsRejected(): void
    {
        $codebook = $this->codebook(withTable: true);

        $this->expectException(\InvalidArgumentException::class);
        $codebook->update($this->seedAt20, ['valid_to_override' => '2020-01-01'], 7);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Vlastní řádek
    // ─────────────────────────────────────────────────────────────────────────

    public function testCustomRowCanBeEditedAndDeleted(): void
    {
        $codebook = $this->codebook(withTable: true);
        $id = $codebook->createCustom([
            'country' => 'AT', 'rate_type' => 'second_reduced',
            'rate_percent' => 13.0, 'valid_from' => '2026-01-01',
        ], 7);

        $codebook->update($id, ['rate_percent' => 14.0, 'note' => 'oprava'], 7);
        self::assertNull($codebook->checkRate('AT', 14.0, 'second_reduced', '2026-06-15'));

        $codebook->delete($id);
        self::assertNull($codebook->find($id));
    }

    /** Přehled pro UI musí říct, co je seed, co uživatelovo a co je vyřazené. */
    public function testListExposesOriginAndOverlay(): void
    {
        $codebook = $this->codebook(withTable: true);
        $codebook->update($this->seedAt20, ['valid_to_override' => '2026-12-31'], 7);
        $custom = $codebook->createCustom([
            'country' => 'AT', 'rate_type' => 'standard',
            'rate_percent' => 21.0, 'valid_from' => '2027-01-01',
        ], 7);

        $byId = [];
        foreach ($codebook->listAll('AT') as $row) {
            $byId[$row['id']] = $row;
        }

        self::assertFalse($byId[$this->seedAt20]['is_custom']);
        self::assertSame('2026-12-31', $byId[$this->seedAt20]['effective_valid_to']);
        self::assertTrue($byId[$custom]['is_custom']);
        self::assertFalse($byId[$custom]['disabled']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function codebook(bool $withTable, bool $withOverlay = true): OssRateCodebook
    {
        if ($withTable) {
            $this->createTable($withOverlay);
            $this->seed();
        }
        $conn = new Connection($this->createStub(Config::class));
        (new \ReflectionClass($conn))->getProperty('pdo')->setValue($conn, $this->pdo);

        return new OssRateCodebook($conn);
    }

    private function createTable(bool $withOverlay): void
    {
        $overlay = $withOverlay
            ? ', valid_to_override TEXT NULL, disabled_at TEXT NULL, created_by INTEGER NULL,
                 updated_at TEXT NULL, updated_by INTEGER NULL'
            : '';
        $this->pdo->exec(
            "CREATE TABLE oss_member_state_rates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                country TEXT NOT NULL,
                rate_type TEXT NOT NULL,
                rate_percent REAL NOT NULL,
                valid_from TEXT NOT NULL,
                valid_to TEXT NULL,
                is_custom INTEGER NOT NULL DEFAULT 0,
                note TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP{$overlay},
                UNIQUE (country, rate_type, rate_percent, valid_from)
            )"
        );
    }

    /** Výřez seedu migrace 1152 — Rakousko 20 / 10 od spuštění OSS. */
    private function seed(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO oss_member_state_rates (country, rate_type, rate_percent, valid_from, is_custom)
             VALUES (?, ?, ?, ?, 0)'
        );
        $stmt->execute(['AT', 'standard', 20.00, '2021-07-01']);
        $this->seedAt20 = (int) $this->pdo->lastInsertId();
        $stmt->execute(['AT', 'reduced', 10.00, '2021-07-01']);
        $this->seedAt10 = (int) $this->pdo->lastInsertId();
    }
}
