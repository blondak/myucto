<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\Dbf\DbfTable;
use MyInvoice\Service\Migration\Premier\PremierException;
use MyInvoice\Tests\Fixtures\Premier\DbfWriter;
use PHPUnit\Framework\TestCase;

/** Čtení tabulky Visual FoxPro (`.dbf` + memo `.fpt`) ze zálohy PREMIER. */
final class DbfTableTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_dbf_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testReadsAllFieldTypesAndSkipsDeletedRecords(): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'TEST.DBF';
        DbfWriter::write($path, [
            ['nazev', 'C', 40], ['castka', 'N', 12, 2], ['pocet', 'N', 5], ['datum', 'D'], ['platce', 'L'], ['inter', 'I'], ['poznamka', 'M'],
        ], [
            ['NAZEV' => 'Žluťoučký kůň úpěl ďábelské ódy', 'CASTKA' => 1234.5, 'POCET' => 7, 'DATUM' => '2025-02-28', 'PLATCE' => true, 'INTER' => 123456, 'POZNAMKA' => 'Příliš dlouhá poznámka – řádek 1' . "\r\n" . str_repeat('ř', 100)],
            ['NAZEV' => 'Smazaný', 'CASTKA' => 1, 'POCET' => 1, 'DATUM' => '2025-01-01', 'PLATCE' => true, 'INTER' => 1, '_deleted' => true],
            ['NAZEV' => 'Prázdná pole', 'CASTKA' => null, 'POCET' => null, 'DATUM' => null, 'PLATCE' => false, 'INTER' => -5, 'POZNAMKA' => null],
            ['NAZEV' => 'Druhé memo', 'CASTKA' => -0.01, 'POCET' => 0, 'DATUM' => '2026-12-31', 'INTER' => 0, 'POZNAMKA' => str_repeat('Dlouhá poznámka. ', 1500)],
        ]);
        self::assertFileExists($this->dir . DIRECTORY_SEPARATOR . 'TEST.FPT');

        $table = new DbfTable($path);
        self::assertSame(4, $table->count(), 'Počet záznamů v hlavičce zahrnuje i smazané.');
        self::assertSame(['NAZEV', 'CASTKA', 'POCET', 'DATUM', 'PLATCE', 'INTER', 'POZNAMKA'], $table->fieldNames());
        self::assertTrue($table->hasField('castka'));
        self::assertFalse($table->hasField('neni'));

        $rows = iterator_to_array($table->rows());
        self::assertSame([0, 2, 3], array_keys($rows), 'Smazaný záznam se nevrací, klíče jsou pořadí v tabulce.');

        self::assertSame([
            'NAZEV' => 'Žluťoučký kůň úpěl ďábelské ódy',
            'CASTKA' => 1234.5,
            'POCET' => 7,
            'DATUM' => '2025-02-28',
            'PLATCE' => true,
            'INTER' => 123456,
            'POZNAMKA' => 'Příliš dlouhá poznámka – řádek 1' . "\r\n" . str_repeat('ř', 100),
        ], $rows[0]);
        self::assertSame(['NAZEV' => 'Prázdná pole', 'CASTKA' => null, 'POCET' => null, 'DATUM' => null, 'PLATCE' => false, 'INTER' => -5, 'POZNAMKA' => null], $rows[2]);
        self::assertSame(-0.01, $rows[3]['CASTKA']);
        self::assertSame(0, $rows[3]['POCET']);
        self::assertSame('2026-12-31', $rows[3]['DATUM']);
        self::assertSame(md5(rtrim(str_repeat('Dlouhá poznámka. ', 1500))), md5((string) $rows[3]['POZNAMKA']), 'Dlouhé memo (přes 8 KB) se přečte celé, bez koncových mezer.');
    }

    public function testMemoWithoutFptFileIsNull(): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'MEMO.DBF';
        DbfWriter::write($path, [['text', 'M']], [['TEXT' => 'poznámka']]);
        unlink($this->dir . DIRECTORY_SEPARATOR . 'MEMO.FPT');

        self::assertSame([['TEXT' => null]], iterator_to_array((new DbfTable($path))->rows(), false));
    }

    public function testRejectsTruncatedHeader(): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'BAD.DBF';
        file_put_contents($path, str_repeat("\0", 10));
        $this->expectException(PremierException::class);
        new DbfTable($path);
    }

    public function testRejectsFieldsLongerThanRecord(): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'BAD.DBF';
        DbfWriter::write($path, [['nazev', 'C', 30]], [['NAZEV' => 'x']]);
        $bytes = (string) file_get_contents($path);
        file_put_contents($path, substr($bytes, 0, 10) . pack('v', 5) . substr($bytes, 12));
        try {
            new DbfTable($path);
            self::fail('Poškozená hlavička musí být odmítnuta.');
        } catch (PremierException $e) {
            self::assertSame('dbf_invalid', $e->errorCode);
        }
    }

    public function testMissingFileIsUnreadable(): void
    {
        try {
            new DbfTable($this->dir . DIRECTORY_SEPARATOR . 'NENI.DBF');
            self::fail('Chybějící tabulka musí být odmítnuta.');
        } catch (PremierException $e) {
            self::assertSame('dbf_unreadable', $e->errorCode);
        }
    }
}
