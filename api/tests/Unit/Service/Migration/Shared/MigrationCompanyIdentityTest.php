<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use MyInvoice\Service\Migration\Shared\FiledDppoFiling;
use MyInvoice\Service\Migration\Shared\MigrationCompanyIdentity;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use PHPUnit\Framework\TestCase;

/**
 * Identita firmy zakládané dávkovým převodem: hlavička podaného DPPO, výběr posledního
 * podání za rok a skládání údajů ze zálohy, podání a ARES.
 */
final class MigrationCompanyIdentityTest extends TestCase
{
    private static function xml(string $ic, string $forma = 'B', string $from = '01.01.2024', string $to = '31.12.2024', string $extraD = '', string $extraP = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Pisemnost><DPPDP9 verzePis="04.01">'
            . '<VetaD dokument="DP9" k_uladis="DPP" dapdpp_forma="' . $forma . '" zdobd_od="' . $from . '" zdobd_do="' . $to . '" ' . $extraD . '/>'
            . '<VetaP rod_c="' . $ic . '" dic="' . $ic . '" zkrobchjm="Vzorová firma s.r.o." ulice="Ukázková" c_pop="12" c_orient="3a" naz_obce="Brno" psc="602 00" ' . $extraP . '/>'
            . '<VetaO kc_ii_10="1000"/></DPPDP9></Pisemnost>';
    }

    public function testFilingHeader(): void
    {
        $f = FiledDppoFiling::parse(self::xml('12345679', 'D', '01.01.2024', '31.12.2024', 'c_nace="41200" kat_uj="S" audit="A" d_zjist="15.03.2025"'));
        self::assertSame('12345679', $f->ic);
        self::assertSame('Vzorová firma s.r.o.', $f->name);
        self::assertSame(['street' => 'Ukázková 12/3a', 'city' => 'Brno', 'zip' => '60200'], $f->address);
        self::assertSame('41200', $f->nace);
        self::assertSame('small', $f->category);
        self::assertTrue($f->audit);
        self::assertSame('2025-03-15', $f->discoveredOn);
        self::assertSame(2024, $f->year());

        $plain = FiledDppoFiling::parse(self::xml('12345679', 'B', '01.01.2024', '31.12.2024', 'kat_uj="V" audit="N" d_zjist="15.03.2025"'));
        self::assertSame('full', $plain->category, 'Střední a velká účetní jednotka = plný rozsah výkazů.');
        self::assertFalse($plain->audit);
        self::assertSame('', $plain->discoveredOn, 'Datum zjištění nese jen dodatečné přiznání.');
        self::assertNull(FiledDppoFiling::parse(self::xml('12345679'))->category);

        $this->expectException(TaxReturnException::class);
        FiledDppoFiling::parse('<Pisemnost><DPHDP3/></Pisemnost>');
    }

    public function testLatestFilingPerYearAndFirstPeriod(): void
    {
        $entry = static fn (string $xml, string $file): array => ['filing' => FiledDppoFiling::parse($xml), 'file' => $file];
        $entries = [
            $entry(self::xml('12345679', 'B', '01.01.2024', '31.12.2024'), 'a-radne-2024.xml'),
            $entry(self::xml('12345679', 'D', '01.01.2024', '31.12.2024', 'd_zjist="01.06.2025"'), 'b-dodatecne-2024-cerven.xml'),
            $entry(self::xml('12345679', 'D', '01.01.2024', '31.12.2024', 'd_zjist="01.03.2025"'), 'c-dodatecne-2024-brezen.xml'),
            $entry(self::xml('12345679', 'O', '01.01.2025', '31.12.2025'), 'd-opravne-2025.xml'),
            $entry(self::xml('12345679', 'B', '01.01.2025', '31.12.2025'), 'e-radne-2025.xml'),
            $entry(self::xml('12345679', 'B', '15.04.2023', '31.12.2023'), 'f-vznik-2023.xml'),
            $entry(self::xml('87654326', 'D', '01.01.2024', '31.12.2024', 'd_zjist="01.12.2025"'), 'g-cizi.xml'),
        ];

        $perYear = FiledDppoFiling::latestPerYear($entries, '0012345679', static fn (array $e): string => $e['file']);
        self::assertSame([2023, 2024, 2025], array_keys($perYear));
        self::assertSame('b-dodatecne-2024-cerven.xml', $perYear[2024]['file'], 'Dodatečné s pozdějším datem zjištění přebíjí.');
        self::assertSame('d-opravne-2025.xml', $perYear[2025]['file'], 'Opravné přebíjí řádné.');
        self::assertSame('2023-04-15', FiledDppoFiling::firstPeriodStart($perYear), 'Firma vzniklá v roce: první období od vzniku.');
        unset($perYear[2023]);
        self::assertNull(FiledDppoFiling::firstPeriodStart($perYear));
    }

    public function testIdentityPrefersBackupThenFilingThenAres(): void
    {
        $filing = FiledDppoFiling::parse(self::xml('12345679', 'B', '01.01.2024', '31.12.2024', 'c_nace="41200" kat_uj="M" audit="N"'));
        $ares = ['company_name' => 'Z ARES s.r.o.', 'street' => 'Aresova 1', 'city' => 'Praha', 'zip' => '110 00', 'dic' => 'CZ12345679', 'cz_nace_code' => '62010'];

        $fromBackup = MigrationCompanyIdentity::merge('12345679',
            ['name' => 'Ze zálohy s.r.o.', 'dic' => 'cz 12345679', 'street' => 'Jiná 5', 'city' => 'Ostrava', 'zip' => '702 00'],
            $filing, $ares, true, null);
        self::assertSame('Ze zálohy s.r.o.', $fromBackup->name);
        self::assertSame(['street' => 'Jiná 5', 'city' => 'Ostrava', 'zip' => '70200'], $fromBackup->address);
        self::assertSame('CZ12345679', $fromBackup->dic);
        self::assertStringContainsString('liší od posledního podaného přiznání', $fromBackup->notes[0]);
        self::assertSame('41200', $fromBackup->nace, 'NACE z podání (převažující činnost), ne z ARES.');
        self::assertSame('micro', $fromBackup->category);
        self::assertFalse($fromBackup->audit);
        self::assertSame('po', $fromBackup->taxpayerType);
        self::assertTrue($fromBackup->vatPayer);
        self::assertSame('registry', $fromBackup->vatSource);
        $input = $fromBackup->supplierInput();
        self::assertTrue($input['is_vat_payer']);
        self::assertSame('po', $input['taxpayer_type']);

        $fromFiling = MigrationCompanyIdentity::merge('12345679', [], $filing, $ares, null, false);
        self::assertSame('Vzorová firma s.r.o.', $fromFiling->name);
        self::assertSame('Brno', $fromFiling->address['city']);
        self::assertFalse($fromFiling->vatPayer);
        self::assertSame('source_journal', $fromFiling->vatSource);
        self::assertStringContainsString('neplátce', implode(' ', $fromFiling->notes));

        $fromAres = MigrationCompanyIdentity::merge('12345679', [], null, $ares, null, null);
        self::assertSame('Z ARES s.r.o.', $fromAres->name);
        self::assertSame('Praha', $fromAres->address['city']);
        self::assertSame('62010', $fromAres->nace);
        self::assertNull($fromAres->taxpayerType, 'Bez podaného DPPO rozhodne o typu poplatníka ARES po založení.');
        self::assertNull($fromAres->vatPayer);
        self::assertArrayNotHasKey('is_vat_payer', $fromAres->supplierInput(), 'Neznámé plátcovství nechá rozhodnout registr.');

        $unknown = MigrationCompanyIdentity::merge('12345679', [], null, null, null, null);
        self::assertSame('Firma IČO 12345679', $unknown->name);
        self::assertCount(2, $unknown->notes);
    }
}
