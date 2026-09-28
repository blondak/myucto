<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Migration\Abra;

use MyInvoice\Service\Migration\Abra\AbraBankBalancePlanner;
use PHPUnit\Framework\TestCase;

final class AbraBankBalancePlannerTest extends TestCase
{
    public function testOpeningRequiresPostedMovementReconciliationAndMonthlyBalanceIncludesAllMovements(): void
    {
        $accounts = [[
            'id' => 12, 'kod' => 'SYN-BANK', 'mena' => 'code:CZK', 'primUcet' => 'code:221100',
            'buc' => '1000000005', 'nazev' => 'Syntetický účet',
        ]];
        $states = [[
            'ucet' => 'code:221100', 'mena' => 'code:CZK',
            'pocatekMD' => '100.00', 'pocatekDal' => '0.00',
            'zustatekMD' => '130.00', 'zustatekDal' => '0.00',
        ]];
        $rows = [
            ['id' => 1, 'banka@ref' => '/c/demo/bankovni-ucet/12.json', 'datUcto' => '2026-01-10',
                'mena' => 'code:CZK', 'sumCelkem' => '50.00', 'typPohybuK' => 'typPohybu.prijem', 'zuctovano' => true],
            ['id' => 2, 'banka@ref' => '/c/demo/bankovni-ucet/12.json', 'datUcto' => '2026-02-10',
                'mena' => 'code:CZK', 'sumCelkem' => '20.00', 'typPohybuK' => 'typPohybu.vydej', 'zuctovano' => true],
            ['id' => 3, 'banka@ref' => '/c/demo/bankovni-ucet/12.json', 'datUcto' => '2026-02-11',
                'mena' => 'code:CZK', 'sumCelkem' => '10.00', 'typPohybuK' => 'typPohybu.prijem', 'zuctovano' => false],
        ];

        self::assertSame(['12|CZK' => 10000], AbraBankBalancePlanner::verifiedOpenings($accounts, $states, $rows));
        self::assertSame([
            ['prev' => 10000, 'curr' => 15000, 'credit' => 5000, 'debit' => 0],
            ['prev' => 15000, 'curr' => 14000, 'credit' => 1000, 'debit' => 2000],
        ], AbraBankBalancePlanner::monthly(10000, [
            ['credit' => 5000, 'debit' => 0],
            ['credit' => 1000, 'debit' => 2000],
        ]));
    }

    public function testSharedAccountingAccountCannotAnchorIndividualBankBalance(): void
    {
        $accounts = [
            ['id' => 12, 'kod' => 'A', 'mena' => 'code:CZK', 'primUcet' => 'code:221100'],
            ['id' => 13, 'kod' => 'B', 'mena' => 'code:CZK', 'primUcet' => 'code:221100'],
        ];
        $states = [['ucet' => 'code:221100', 'mena' => 'code:CZK',
            'pocatekMD' => '100.00', 'pocatekDal' => '0.00', 'zustatekMD' => '110.00', 'zustatekDal' => '0.00']];
        $rows = [['id' => 1, 'banka@ref' => '/c/demo/bankovni-ucet/12.json', 'datUcto' => '2026-01-10',
            'mena' => 'code:CZK', 'sumCelkem' => '10.00', 'typPohybuK' => 'typPohybu.prijem', 'zuctovano' => true]];

        self::assertSame([], AbraBankBalancePlanner::verifiedOpenings($accounts, $states, $rows));
    }
}
