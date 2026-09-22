<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\BankImporter;
use MyInvoice\Service\Migration\Premier\PremierJournal;
use PHPUnit\Framework\TestCase;

/**
 * Údaje bankovního pohybu z řádku deníku PREMIER: VS (`VARIABL` → `VAR_DAL` → `HVAR`),
 * protiúčet `HUCET`, KS `HKS` a SS `HSPEC` bez samých nul, zpráva `HZPR_PRIJ`, `PARTRAN`.
 */
final class PremierBankDetailsTest extends TestCase
{
    /** @param array<string,mixed> $extra */
    private static function details(array $extra): array
    {
        $row = PremierJournal::fromRows([$extra + ['INTER' => 1, 'DATUM' => '2025-10-15', 'DOKLAD' => 'BV', 'CISLO' => '8', 'CASTKA' => 100,
            'MD' => '221001', 'DAL' => '311000', 'POPIS' => 'Úhrada']])->row(1);
        self::assertNotNull($row);
        return BankImporter::details($row);
    }

    public function testVariableSymbolFallsBackToCreditAndHomebankingSymbol(): void
    {
        self::assertSame('250006', self::details(['VARIABL' => '250006', 'VAR_DAL' => '111', 'HVAR' => '999'])['vs']);
        self::assertSame('250005', self::details(['VARIABL' => '', 'VAR_DAL' => '250005', 'HVAR' => '999'])['vs']);
        self::assertSame('777', self::details(['VARIABL' => '0000', 'HVAR' => '777'])['vs']);
        self::assertNull(self::details(['HVAR' => '0'])['vs']);
    }

    public function testCounterpartyAndSymbols(): void
    {
        $d = self::details(['HUCET' => '000019-1000000005/0100', 'HKS' => '0308', 'HSPEC' => '0000000000', 'HZPR_PRIJ' => 'Faktura 250005', 'PARTRAN' => 'SYN-TX-1']);
        self::assertSame(['19-1000000005', '0100', '0308', null, 'Úhrada | Faktura 250005', 'SYN-TX-1'],
            [$d['account'], $d['bank'], $d['ks'], $d['ss'], $d['description'], $d['ref']]);

        $d = self::details(['HUCET' => '000000-0000000000/0000', 'HKS' => '0000', 'HSPEC' => '42', 'HZPR_PRIJ' => 'úhrada']);
        self::assertSame([null, null, null, '42', 'Úhrada', null], [$d['account'], $d['bank'], $d['ks'], $d['ss'], $d['description'], $d['ref']],
            'Nulový účet a KS jsou bez hodnoty, zpráva obsažená v popisu se neopakuje.');

        self::assertSame(['CZ1801000000001000000005', null], array_values(array_intersect_key(self::details(['HUCET' => 'CZ18 0100 0000 0010 0000 0005']), ['account' => 1, 'bank' => 1])));
    }
}
