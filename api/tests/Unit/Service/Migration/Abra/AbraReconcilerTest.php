<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Migration\Abra;

require_once dirname(__DIR__, 5) . '/src/Service/Migration/Abra/AbraSource.php';
require_once dirname(__DIR__, 5) . '/src/Service/Migration/Abra/AbraReconciler.php';

use MyInvoice\Service\Migration\Abra\AbraReconciler;
use PHPUnit\Framework\TestCase;

final class AbraReconcilerTest extends TestCase
{
    public function testJournalMatchesMovementControlByAccount(): void
    {
        $result = (new AbraReconciler())->reconcile([
            ['debit' => '311', 'credit' => '602', 'amount' => 1000.0, 'is_red_storno' => false],
            ['debit' => '311', 'credit' => '343', 'amount' => 210.0, 'is_red_storno' => false],
        ], [
            ['ucet' => 'code:311', 'sumTuzMd' => 1210, 'sumTuzDal' => 0],
            ['ucet' => 'code:602', 'sumTuzMd' => 0, 'sumTuzDal' => 1000],
            ['ucet' => 'code:343', 'sumTuzMd' => 0, 'sumTuzDal' => 210],
        ], []);

        self::assertTrue($result['complete']);
        self::assertTrue($result['ok']);
        self::assertSame('pohyb-na-uctech', $result['source']);
        self::assertSame([], $result['differences']);
    }

    public function testRedStornoUsesSignedAccountingEffect(): void
    {
        $result = (new AbraReconciler())->reconcile([
            ['debit' => '311', 'credit' => '602', 'amount' => 100.0, 'is_red_storno' => true],
        ], [
            ['ucet' => 'code:311', 'sumTuzMd' => -100, 'sumTuzDal' => 0],
            ['ucet' => 'code:602', 'sumTuzMd' => 0, 'sumTuzDal' => -100],
        ], []);

        self::assertTrue($result['ok']);
    }

    public function testMovementControlCanResolveAccountsThroughJournalIdentity(): void
    {
        $result = (new AbraReconciler())->reconcile([
            ['source_key' => '501', 'debit' => '221', 'credit' => '311', 'amount' => 250.0, 'is_red_storno' => false],
        ], [
            ['id' => 9901, 'idUcetniDenik' => 501, 'sumTuzMd' => 250, 'sumTuzDal' => 250],
        ], []);

        self::assertTrue($result['ok']);
        self::assertSame(2, $result['accounts']);
    }

    public function testEqualNetButDifferentGrossTurnoversFail(): void
    {
        $result = (new AbraReconciler())->reconcile([
            ['debit' => '221', 'credit' => '311', 'amount' => 100.0, 'is_red_storno' => false],
            ['debit' => '311', 'credit' => '221', 'amount' => 100.0, 'is_red_storno' => false],
        ], [
            ['ucet' => 'code:221', 'sumTuzMd' => 120, 'sumTuzDal' => 120],
            ['ucet' => 'code:311', 'sumTuzMd' => 80, 'sumTuzDal' => 80],
        ], []);

        self::assertFalse($result['ok']);
        self::assertCount(4, $result['differences']);
        self::assertSame(['debit', 'credit'], array_values(array_unique(array_column($result['differences'], 'side'))));
    }

    public function testBalanceControlSumsAllAvailableMonthlySlots(): void
    {
        $result = (new AbraReconciler())->reconcile([
            ['debit' => '311', 'credit' => '602', 'amount' => 200.0, 'is_red_storno' => false],
        ], [], [
            ['ucet' => 'code:311', 'pocatekMD' => 100, 'pocatekDal' => 0,
                'obratMd01' => 50, 'obratMd13' => 150, 'obratDal01' => 0],
            ['ucet' => 'code:602', 'pocatekMD' => 0, 'pocatekDal' => 100,
                'obratMd01' => 0, 'obratDal01' => 50, 'obratDal23' => 150],
        ]);

        self::assertTrue($result['ok']);
        self::assertSame('stav-uctu', $result['source']);
    }

    public function testMovementControlTakesPrecedenceOverBalanceSnapshot(): void
    {
        $result = (new AbraReconciler())->reconcile([
            ['debit' => '221', 'credit' => '311', 'amount' => 10.0, 'is_red_storno' => false],
        ], [
            ['ucet' => 'code:221', 'sumTuzMd' => 10, 'sumTuzDal' => 0],
            ['ucet' => 'code:311', 'sumTuzMd' => 0, 'sumTuzDal' => 10],
        ], [
            ['ucet' => 'code:221', 'obratMd01' => 999, 'obratDal01' => 0],
        ]);

        self::assertTrue($result['ok']);
        self::assertSame('pohyb-na-uctech', $result['source']);
    }

    public function testDifferenceAndMissingControlCannotClaimSuccess(): void
    {
        $different = (new AbraReconciler())->reconcile([
            ['debit' => '221', 'credit' => '311', 'amount' => 100.0, 'is_red_storno' => false],
        ], [
            ['ucet' => 'code:221', 'sumTuzMd' => 99, 'sumTuzDal' => 0],
            ['ucet' => 'code:311', 'sumTuzMd' => 0, 'sumTuzDal' => 100],
        ], []);
        self::assertFalse($different['ok']);
        self::assertSame(1.0, $different['differences'][0]['difference']);

        $missing = (new AbraReconciler())->reconcile([], [], []);
        self::assertFalse($missing['complete']);
        self::assertFalse($missing['ok']);
        self::assertSame(['source_trial_balance_missing'], $missing['warnings']);
    }
}
