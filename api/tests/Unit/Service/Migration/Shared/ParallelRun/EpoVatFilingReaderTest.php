<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared\ParallelRun;

use MyInvoice\Service\Migration\Shared\ParallelRun\EpoVatFilingReader;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunException;
use PHPUnit\Framework\TestCase;

final class EpoVatFilingReaderTest extends TestCase
{
    private const DPH = '<?xml version="1.0" encoding="UTF-8"?>
<Pisemnost nazevSW="Jiný program"><DPHDP3 verzePis="01.02">
  <VetaD dokument="DP3" k_uladis="DPH" rok="2026" mesic="10" dapdph_forma="B"/>
  <VetaP dic="12345679" typ_ds="P"/>
  <Veta1 obrat23="100000" dan23="21000" obrat5="0"/>
  <Veta4 pln23="50000" odp_tuz23_nar="10500"/>
  <Veta5 koef_p20_nov="100"/>
  <Veta6 dan_zocelk="21000" odp_zocelk="10500" dano_da="10500"/>
</DPHDP3></Pisemnost>';

    public function testVatReturnValuesByAttributeWithoutCoefficient(): void
    {
        $read = (new EpoVatFilingReader())->read(self::DPH, 'dphdp3');

        self::assertSame('dphdp3', $read['form']);
        self::assertSame(['year' => 2026, 'month' => 10, 'quarter' => null], $read['period']);
        self::assertSame(100000.0, $read['values']['Veta1.obrat23']);
        self::assertSame(10500.0, $read['values']['Veta4.odp_tuz23_nar']);
        self::assertArrayNotHasKey('Veta5.koef_p20_nov', $read['values'], 'Koeficient v % není částka.');
        self::assertArrayNotHasKey('VetaP.dic', $read['values']);
        self::assertSame('40', EpoVatFilingReader::dphLine('Veta4.odp_tuz23_nar'));
        self::assertSame('64', EpoVatFilingReader::dphLine('Veta6.dano_da'));
    }

    public function testControlStatementRowsDoNotDependOnOrder(): void
    {
        $kh = static fn (string $rows): string => '<Pisemnost><DPHKH1><VetaD rok="2026" mesic="10" khdph_forma="B"/>' . $rows
            . '<VetaC obrat23="100000" pln23="50000"/></DPHKH1></Pisemnost>';
        $a = '<VetaA4 dic_odb="11223341" c_evid_dd="FV26001" dppd="05.10.2026" zakl_dane1="60000" dan1="12600" kod_rezim_pl="0" zdph_44="N"/>'
            . '<VetaA4 dic_odb="11223341" c_evid_dd="FV26002" dppd="06.10.2026" zakl_dane1="40000" dan1="8400" kod_rezim_pl="0" zdph_44="N"/>'
            . '<VetaB2 dic_dod="87654326" c_evid_dd="DF 2026 017" dppd="07.10.2026" zakl_dane1="50000" dan1="10500" pomer="N" zdph_44="N"/>'
            . '<VetaA5 zakl_dane1="1000" dan1="210"/><VetaA5 zakl_dane2="500" dan2="60"/>';
        $b = '<VetaB2 dic_dod="87654326" c_evid_dd="DF2026017" dppd="07.10.2026" zakl_dane1="50000" dan1="10500" pomer="N" zdph_44="N"/>'
            . '<VetaA5 zakl_dane1="1000" dan1="210" zakl_dane2="500" dan2="60"/>'
            . '<VetaA4 dic_odb="11223341" c_evid_dd="FV26002" dppd="06.10.2026" zakl_dane1="40000" dan1="8400" kod_rezim_pl="0" zdph_44="N"/>'
            . '<VetaA4 dic_odb="11223341" c_evid_dd="FV26001" dppd="05.10.2026" zakl_dane1="60000" dan1="12600" kod_rezim_pl="0" zdph_44="N"/>';
        $reader = new EpoVatFilingReader();
        $first = $reader->read($kh($a), 'dphkh1');
        $second = $reader->read($kh($b), 'dphkh1');

        self::assertSame(array_keys($first['rows']), array_keys($second['rows']), 'Pořadí řádků ani mezery v evidenčním čísle nehrají roli.');
        self::assertSame(array_column($first['rows'], 'amount'), array_column($second['rows'], 'amount'));
        self::assertSame($first['values'], $second['values']);
        self::assertSame(72600.0, $first['rows']['A4|11223341|FV26001']['amount']);
        self::assertSame(1770.0, $first['rows']['A5']['amount'], 'Agregované řádky A.5 se sečtou do jednoho.');
        self::assertSame(100000.0, $first['values']['VetaC.obrat23']);
    }

    public function testWrongFormIsRejected(): void
    {
        $this->expectException(ParallelRunException::class);
        $this->expectExceptionMessageMatches('/kontrolní hlášení/u');
        (new EpoVatFilingReader())->read(self::DPH, 'dphkh1');
    }

    public function testBrokenXmlIsRejected(): void
    {
        $this->expectException(ParallelRunException::class);
        (new EpoVatFilingReader())->read('<Pisemnost><DPHDP3>', 'dphdp3');
    }
}
