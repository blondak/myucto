<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\StatementMapper;
use PHPUnit\Framework\TestCase;

/**
 * Unit testy StatementMapper::noCompensationPrefixes (N1, audit 2026-07 review D2)
 * — čistá třída bez DB. Guard proti budoucí migraci, která by přidala nepárový
 * saldový prefix (jen 'debit' nebo jen 'credit' bez protistrany se stejným
 * account_prefix) — bez páru by D2 split v LedgerReportRepository vyrobil stranu,
 * kterou StatementMapper::map() nikdy nenamapuje na žádný řádek výkazu, a rozvaha
 * by se tiše rozvážila. Fail-loud místo tichého selhání.
 */
final class StatementMapperTest extends TestCase
{
    private StatementMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new StatementMapper();
    }

    public function testPairedDebitCreditPrefixesArePermitted(): void
    {
        $map = [
            self::mapRow('P.C.II.4.', '221', 'gross', 'credit'),
            self::mapRow('C.IV.2.',   '221', 'gross', 'debit'),
            self::mapRow('C.I.1.',    '111', 'gross', 'any'),
        ];

        $codes = $this->mapper->noCompensationPrefixes($map);

        self::assertSame(['221'], $codes, 'Jen účty se saldovou podmínkou (ne "any") jsou split kandidáti.');
    }

    public function testUnpairedDebitOnlyPrefixThrowsLoudly(): void
    {
        $map = [
            self::mapRow('C.II.2.4.3.', '341', 'gross', 'debit'),
            // chybí protistrana s balance_condition='credit' pro '341'
        ];

        $this->expectException(ReportException::class);
        $this->expectExceptionMessage('341');
        $this->mapper->noCompensationPrefixes($map);
    }

    public function testUnpairedCreditOnlyPrefixThrowsLoudly(): void
    {
        $map = [
            self::mapRow('P.C.II.8.5.', '346', 'gross', 'credit'),
            // chybí protistrana s balance_condition='debit' pro '346'
        ];

        try {
            $this->mapper->noCompensationPrefixes($map);
            self::fail('Očekávána ReportException pro nepárový prefix.');
        } catch (ReportException $e) {
            self::assertSame('unpaired_balance_condition_prefix', $e->errorCode);
            self::assertStringContainsString('346', $e->getMessage());
        }
    }

    public function testMultiplePairedPrefixesAllReturned(): void
    {
        $map = [
            self::mapRow('C.II.2.4.3.', '341', 'gross', 'debit'),
            self::mapRow('P.C.II.8.5.', '341', 'gross', 'credit'),
            self::mapRow('C.II.2.4.3.', '343', 'gross', 'debit'),
            self::mapRow('P.C.II.8.5.', '343', 'gross', 'credit'),
        ];

        $codes = $this->mapper->noCompensationPrefixes($map);

        sort($codes);
        self::assertSame(['341', '343'], $codes);
    }

    public function testUnmappedNonZeroBalanceIsReported(): void
    {
        $map = [self::mapRow('I.', '602', 'gross', 'any')];
        $balances = [
            ['account_id' => 1, 'code' => '602', 'name' => 'Služby', 'account_type' => 'revenue', 'md' => 0.0, 'd' => 100.0],
            ['account_id' => 2, 'code' => '699', 'name' => 'Vlastní výnos', 'account_type' => 'revenue', 'md' => 0.0, 'd' => 50.0],
            ['account_id' => 3, 'code' => '598', 'name' => 'Nulový', 'account_type' => 'expense', 'md' => 10.0, 'd' => 10.0],
        ];

        self::assertSame([
            ['account_id' => 2, 'account_code' => '699', 'name' => 'Vlastní výnos', 'balance' => -50.0],
        ], $this->mapper->unmappedBalances($map, $balances));
    }

    public function testAnalyticPrefixesReturnsOnlyLongerThanSyntheticCodes(): void
    {
        $map = [
            self::mapRow('C.II.2.1.', '311', 'gross', 'any'),
            self::mapRow('C.II.1.1.', '311D', 'gross', 'any'),
            self::mapRow('E.3.', '559P', 'gross', 'any'),
        ];

        self::assertSame(['311D', '559P'], $this->mapper->analyticPrefixes($map));
    }

    /**
     * @return array{row_code:string, account_prefix:string, target:string, balance_condition:string, sign:int}
     */
    private static function mapRow(string $rowCode, string $prefix, string $target, string $condition): array
    {
        return [
            'row_code'          => $rowCode,
            'account_prefix'    => $prefix,
            'target'            => $target,
            'balance_condition' => $condition,
            'sign'              => 1,
        ];
    }
}
