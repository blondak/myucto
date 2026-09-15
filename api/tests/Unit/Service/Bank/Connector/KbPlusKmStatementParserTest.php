<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Service\Bank\Connector\KbPlusKmStatementParser;
use PHPUnit\Framework\TestCase;

final class KbPlusKmStatementParserTest extends TestCase
{
    private const IBAN = 'CZ0401000000191000000005';
    /** 19-1000000005 ve vnitřním formátu KB (N16 N14 N15 N12 N7…N11 N13 N1…N6). */
    private const OWN_INTERNAL = '5000100000000019';
    private const OWN_EDITION = '0000191000000005';
    /** 1000000005 ve vnitřním formátu KB. */
    private const COUNTERPARTY_INTERNAL = '5000100000000000';
    private const COUNTERPARTY_EDITION = '0000001000000005';

    public function testConvertsInternalAccountNumbersAndMergesBusinessDays(): void
    {
        $firstDay = $this->statement(self::OWN_INTERNAL, '120926', 100000, 150000, 0, 50000, '010', [
            $this->movement(self::OWN_INTERNAL, self::COUNTERPARTY_INTERNAL, 50000, '2', '120926'),
        ]);
        $secondDay = $this->statement(self::OWN_INTERNAL, '140926', 150000, 140000, 10000, 0, '011', [
            $this->movement(self::OWN_INTERNAL, self::COUNTERPARTY_INTERNAL, 10000, '1', '140926'),
        ]);

        $result = (new KbPlusKmStatementParser())->parse([$secondDay, $firstDay], self::IBAN, 'CZK', '2026-09-12', '2026-09-14');

        self::assertSame([
            'account_number' => self::IBAN,
            'statement_date' => '2026-09-14',
            'statement_number' => null,
            'prev_balance' => 1000.0,
            'curr_balance' => 1400.0,
            'debit_total' => 100.0,
            'credit_total' => 500.0,
        ], $result['header']);
        self::assertSame([500.0, -100.0], array_column($result['transactions'], 'amount'));
        self::assertSame([self::COUNTERPARTY_EDITION, self::COUNTERPARTY_EDITION], array_column($result['transactions'], 'counterparty_account'));
        self::assertSame(['CZK', 'CZK'], array_column($result['transactions'], 'currency'));
    }

    public function testAcceptsEditionFormatWithoutConvertingAccounts(): void
    {
        $file = $this->statement(self::OWN_EDITION, '140926', 0, 50000, 0, 50000, '001', [
            $this->movement(self::OWN_EDITION, self::COUNTERPARTY_EDITION, 50000, '2', '140926'),
        ]);

        $result = (new KbPlusKmStatementParser())->parse([$file], self::IBAN, 'CZK', '2026-09-14', '2026-09-14');

        self::assertSame(self::COUNTERPARTY_EDITION, $result['transactions'][0]['counterparty_account']);
        self::assertSame('001', $result['header']['statement_number']);
    }

    public function testEmptyPeriodKeepsRequestedDateWithoutBalances(): void
    {
        $result = (new KbPlusKmStatementParser())->parse([], self::IBAN, 'CZK', '2026-09-12', '2026-09-13');

        self::assertSame('2026-09-13', $result['header']['statement_date']);
        self::assertNull($result['header']['curr_balance']);
        self::assertSame([], $result['transactions']);
    }

    public function testRejectsStatementOfAnotherAccount(): void
    {
        $file = $this->statement(self::COUNTERPARTY_EDITION, '140926', 0, 50000, 0, 50000, '001', [
            $this->movement(self::COUNTERPARTY_EDITION, self::OWN_EDITION, 50000, '2', '140926'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('jinému účtu');
        (new KbPlusKmStatementParser())->parse([$file], self::IBAN, 'CZK', '2026-09-14', '2026-09-14');
    }

    public function testRejectsContentThatIsNotKm(): void
    {
        $this->expectException(\RuntimeException::class);
        (new KbPlusKmStatementParser())->parse(['{"status":"READY"}'], self::IBAN, 'CZK', '2026-09-14', '2026-09-14');
    }

    /** @param list<string> $movements */
    private function statement(string $account, string $date, int $previous, int $current, int $debit, int $credit, string $number, array $movements): string
    {
        $header = '074' . $account . str_repeat(' ', 20) . $date . sprintf('%014d', $previous) . '+'
            . sprintf('%014d', $current) . '+' . sprintf('%014d', $debit) . '0' . sprintf('%014d', $credit) . '0'
            . $number . $date . 'CZ040100' . 'MB' . str_repeat(' ', 4);
        return implode("\r\n", [$header, ...$movements]) . "\r\n";
    }

    private function movement(string $account, string $counterparty, int $amount, string $code, string $date): string
    {
        return '075' . $account . $counterparty . sprintf('%013d', $amount) . sprintf('%012d', $amount) . $code
            . sprintf('%010d', 77) . '00' . '0100' . '0308' . str_repeat('0', 10) . '000000'
            . str_pad('SYNTETICKA PLATBA', 20) . '0' . str_repeat(' ', 4) . $date;
    }
}
