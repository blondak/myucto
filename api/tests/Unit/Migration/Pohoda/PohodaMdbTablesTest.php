<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaMdbTables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PohodaMdbTablesTest extends TestCase
{
    private const ROOT = '<pohodaMdbAccounting formatVersion="1" ico="12345678" year="2026" programVersion="test">';
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'pohoda_mdb_unit_');
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    public function testReadsScalarsAndMissingTablesWithoutLosingZeroOrWhitespace(): void
    {
        file_put_contents($this->path, self::ROOT . '<table name="FA"><row><ID>1</ID><X nil="true"/><Zero>0</Zero><Empty/><Text> A &amp; B </Text></row><row><ID>2</ID></row></table><table name="AD"/><table name="BN" missing="true"/></pohodaMdbAccounting>');
        $source = new PohodaMdbTables($this->path);
        self::assertSame('12345678', $source->ico);
        self::assertSame(2026, $source->year);
        self::assertSame('test', $source->version);
        self::assertTrue($source->has('FA'));
        self::assertTrue($source->has('AD'));
        self::assertFalse($source->has('BN'));
        self::assertFalse($source->has('Absent'));
        $expected = [['ID' => '1', 'Zero' => '0', 'Empty' => '', 'Text' => ' A & B '], ['ID' => '2']];
        self::assertSame($expected, iterator_to_array($source->rows('FA')));
        self::assertSame($expected, iterator_to_array($source->rows('FA')));
        self::assertSame([], iterator_to_array($source->rows('AD')));
        self::assertSame([], iterator_to_array($source->rows('BN')));
    }

    #[DataProvider('invalidDocuments')]
    public function testRejectsInvalidDocumentDuringConstruction(string $xml): void
    {
        file_put_contents($this->path, $xml);
        $this->expectException(PohodaException::class);
        new PohodaMdbTables($this->path);
    }

    public function testSkipsOtherTablesWithoutLosingFollowingTableOrLastRow(): void
    {
        file_put_contents($this->path, self::ROOT . '<table name="First"><row><ID>1</ID></row></table><table name="Empty"/><table name="Middle"><row><ID>2</ID></row><row><ID>3</ID></row></table><table name="Last"><row><ID>4</ID></row></table></pohodaMdbAccounting>');
        $source = new PohodaMdbTables($this->path);
        self::assertSame([['ID' => '2'], ['ID' => '3']], iterator_to_array($source->rows('Middle')));
        self::assertSame([['ID' => '4']], iterator_to_array($source->rows('Last')));
        self::assertSame([['ID' => '1']], iterator_to_array($source->rows('First')));
        self::assertSame([], iterator_to_array($source->rows('Absent')));
    }

    public function testRejectsFileReplacementEvenWithSameSizeAndMetadata(): void
    {
        $xml = self::ROOT . '<table name="FA"><row><ID>1</ID></row></table></pohodaMdbAccounting>';
        file_put_contents($this->path, $xml);
        $source = new PohodaMdbTables($this->path);
        file_put_contents($this->path, str_replace('<ID>1</ID>', '<ID>2</ID>', $xml));
        $this->expectException(PohodaException::class);
        $this->expectExceptionMessage('během čtení změnilo');
        iterator_to_array($source->rows('FA'));
    }

    public static function invalidDocuments(): iterable
    {
        $end = '</pohodaMdbAccounting>';
        yield 'truncated' => [self::ROOT . '<table name="FA"><row><ID>1</ID></row>'];
        yield 'empty' => [''];
        yield 'wrong version' => [str_replace('formatVersion="1"', 'formatVersion="2"', self::ROOT) . $end];
        yield 'invalid company ID' => [str_replace('12345678', 'bad', self::ROOT) . $end];
        yield 'old year' => [str_replace('2026', '1989', self::ROOT) . $end];
        yield 'future year' => [str_replace('2026', (string) ((int) date('Y') + 2), self::ROOT) . $end];
        yield 'missing metadata' => ['<pohodaMdbAccounting formatVersion="1"/>'];
        yield 'duplicate tables' => [self::ROOT . '<table name="FA"/><table name="FA"/>' . $end];
        yield 'duplicate nil column' => [self::ROOT . '<table name="FA"><row><ID nil="true"/><ID>1</ID></row></table>' . $end];
        yield 'invalid column after valid first table' => [self::ROOT . '<table name="FA"><row><ID>1</ID></row></table><table name="AD"><row><ID>2</ID><ID>3</ID></row></table>' . $end];
        yield 'nested column' => [self::ROOT . '<table name="FA"><row><ID><X>1</X></ID></row></table>' . $end];
        yield 'missing with rows' => [self::ROOT . '<table name="FA" missing="true"><row/></table>' . $end];
        yield 'nil with text' => [self::ROOT . '<table name="FA"><row><ID nil="true">1</ID></row></table>' . $end];
        yield 'stray content' => [self::ROOT . 'private payload' . $end];
        yield 'namespace' => [str_replace('formatVersion=', 'xmlns="urn:wrong" formatVersion=', self::ROOT) . $end];
        yield 'DTD' => ['<!DOCTYPE pohodaMdbAccounting>' . self::ROOT . $end];
        yield 'external entity' => ['<!DOCTYPE pohodaMdbAccounting [<!ENTITY x SYSTEM "file:///not-a-real-file">]>' . self::ROOT . '<table name="FA"><row><ID>&x;</ID></row></table>' . $end];
        yield 'DTD behind comment' => ['<!--' . str_repeat('x', 100000) . '--><!DOCTYPE pohodaMdbAccounting>' . self::ROOT . $end];
        yield 'too many tables' => [self::ROOT . implode('', array_map(fn ($n) => '<table name="T' . $n . '"/>', range(1, 65))) . $end];
        yield 'oversized field' => [self::ROOT . '<table name="FA"><row><ID>' . str_repeat('x', 1048577) . '</ID></row></table>' . $end];
        yield 'too many rows' => [self::ROOT . '<table name="FA">' . str_repeat('<row/>', 1000001) . '</table>' . $end];
    }
}
