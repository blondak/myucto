<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\CsobBbfAdviceParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsobBbfAdviceParserTest extends TestCase
{
    public function testDomesticAdviceMayOmitTransactionSubtype(): void
    {
        $line = substr_replace($this->domestic(), '  ', 18, 2);
        $records = (new CsobBbfAdviceParser())->parse($this->advice([$line]));
        self::assertSame('1234.56', $records[0]['amount']);
        self::assertSame('9876.54', $records[0]['balance']);
    }

    public function testParsesDomesticAdviceAndKeepsGpcCompatibleBankReference(): void
    {
        $raw = $this->advice([$this->domestic()]);
        $records = (new CsobBbfAdviceParser())->parse($raw);

        self::assertCount(1, $records);
        self::assertSame([
            'account_number' => '0000001000000005',
            'bank_code' => '0300',
            'currency' => 'CZK',
            'booked_on' => '2026-09-14',
            'amount' => '1234.56',
            'variable_symbol' => '260100010',
            'constant_symbol' => '308',
            'specific_symbol' => '42',
            'counterparty_account' => '0000002000000018',
            'counterparty_bank' => '0100',
            'counterparty_name' => 'Žluťoučký test s.r.o.',
            'description' => 'Syntetická úhrada | Poznámka plátce',
            'bank_ref' => '12345',
            'balance' => '9876.54',
            'advice_ref' => '20260914000001',
        ], $records[0]);

        $split = (new CsobBbfAdviceParser())->split($raw);
        self::assertCount(1, $split);
        self::assertSame($raw, $split[0]['content']);
        self::assertSame('20260914000001', $split[0]['parsed']['header']['statement_number']);
        self::assertSame('9876.54', $split[0]['parsed']['header']['curr_balance']);
        self::assertSame('1234.56', $split[0]['parsed']['header']['credit_total']);
        self::assertSame('12345', $split[0]['parsed']['transactions'][0]['bank_ref']);
        self::assertSame('2026-09-14', $split[0]['parsed']['transactions'][0]['posted_at']);
    }

    public function testParsesForeignDebitAndUsesAccountCurrency(): void
    {
        $records = (new CsobBbfAdviceParser())->parse($this->advice([$this->foreign()]));

        self::assertCount(1, $records);
        self::assertSame('EUR', $records[0]['currency']);
        self::assertSame('-78.90', $records[0]['amount']);
        self::assertSame('CZ6108000000191000000005', $records[0]['counterparty_account']);
        self::assertSame('0800', $records[0]['counterparty_bank']);
        self::assertSame('Synthetic Foreign Partner Berlin', $records[0]['counterparty_name']);
        self::assertSame('Synthetic foreign payment', $records[0]['description']);
        self::assertSame('54321', $records[0]['bank_ref']);
        self::assertNull($records[0]['balance']);
        self::assertNull($records[0]['variable_symbol']);
    }

    public function testSupportsMultipleTransactionsAndConcatenatedAdvicesWithoutCrossAccountEvidence(): void
    {
        $first = $this->advice([$this->domestic(), $this->domestic([
            'bank_ref' => '0000000000012346',
            'amount' => '0000000000001.00',
            'indicator' => 'D ',
            'balance' => '0000000009875.54',
        ])]);
        $second = $this->advice([$this->foreign(['own_account' => '0000002000000018'])], '20260914000002');
        $raw = $first . $second;

        $parser = new CsobBbfAdviceParser();
        self::assertCount(3, $parser->parse($raw));
        $split = $parser->split($raw);
        self::assertCount(2, $split);
        self::assertSame($first, $split[0]['content']);
        self::assertSame($second, $split[1]['content']);
        self::assertSame('0000001000000005', $split[0]['parsed']['header']['account_number']);
        self::assertSame('0000002000000018', $split[1]['parsed']['header']['account_number']);
        self::assertSame('1.00', $split[0]['parsed']['header']['debit_total']);
        self::assertSame('-1.00', $split[0]['parsed']['transactions'][1]['amount']);
    }

    public function testWindows1250AndUtf8InputsProduceUtf8TextAtFixedByteOffsets(): void
    {
        $windows1250 = $this->advice([$this->domestic()]);
        $utf8 = iconv('Windows-1250', 'UTF-8', $windows1250);
        self::assertNotFalse($utf8);

        $parser = new CsobBbfAdviceParser();
        self::assertSame($parser->parse($utf8), $parser->parse($windows1250));
        self::assertSame('Žluťoučký test s.r.o.', $parser->parse($windows1250)[0]['counterparty_name']);
        self::assertSame($windows1250, $parser->split($windows1250)[0]['content']);
    }

    public function testReversalDirectionsDetermineSignedAmounts(): void
    {
        $raw = $this->advice([
            $this->domestic(['bank_ref' => '1', 'indicator' => 'RC', 'amount' => '-000000000001,00']),
            $this->domestic(['bank_ref' => '2', 'indicator' => 'RD', 'amount' => '0000000000002,00', 'balance' => '0000000009878.54']),
        ]);
        $records = (new CsobBbfAdviceParser())->parse($raw);

        self::assertSame('-1.00', $records[0]['amount']);
        self::assertSame('2.00', $records[1]['amount']);
    }

    public function testAllowsDocumentedOmissionOfTrailingUnusedBlankColumns(): void
    {
        $full = $this->advice([$this->domestic()])
            . $this->advice([$this->foreign()], '20260914000002');
        $short = implode("\r\n", array_map('rtrim', explode("\r\n", $full)));

        $records = (new CsobBbfAdviceParser())->parse($short);
        self::assertCount(2, $records);
        self::assertSame('12345', $records[0]['bank_ref']);
        self::assertSame('54321', $records[1]['bank_ref']);
    }

    public function testAllowsDocumentedZeroForeignFeesWithoutCurrency(): void
    {
        $raw = $this->advice([$this->foreign([
            'local_fee' => '0000000000000.00',
            'local_currency' => '',
            'foreign_fee' => '0000000000000.00',
            'foreign_currency' => '',
        ])]);

        self::assertSame('-78.90', (new CsobBbfAdviceParser())->parse($raw)[0]['amount']);
    }

    public function testEndBalanceIncludesFollowingForeignMovementInAccountCurrency(): void
    {
        $raw = $this->advice([
            $this->domestic(),
            $this->foreign([
                'account_amount' => '0000000000010.00',
                'account_currency' => 'CZK',
                'bank_ref' => '0000000000000000000000054322',
            ]),
        ]);

        $parsed = (new CsobBbfAdviceParser())->split($raw)[0]['parsed'];
        self::assertSame('9866.54', $parsed['header']['curr_balance']);
        self::assertNull($parsed['transactions'][1]['balance']);
    }

    public function testForeignBicDoesNotOverflowNationalBankCode(): void
    {
        $parser = new CsobBbfAdviceParser();
        $nonCzech = $parser->parse($this->advice([$this->foreign([
            'counterparty_account' => 'DE00123456789012345678',
            'bic' => 'TESTDEFFXXX',
        ])]))[0];
        self::assertSame('DE00123456789012345678', $nonCzech['counterparty_account']);
        self::assertNull($nonCzech['counterparty_bank']);

        $invalidCzech = $parser->parse($this->advice([$this->foreign([
            'counterparty_account' => 'CZ0008000000191000000005',
        ])]))[0];
        self::assertSame('CZ0008000000191000000005', $invalidCzech['counterparty_account']);
        self::assertNull($invalidCzech['counterparty_bank']);
    }

    public function testAcceptsLargestDatabaseAmount(): void
    {
        $record = (new CsobBbfAdviceParser())->parse($this->advice([$this->domestic([
            'amount' => '0999999999999.99',
        ])]))[0];
        self::assertSame('999999999999.99', $record['amount']);
    }

    #[DataProvider('invalidFiles')]
    public function testRejectsMalformedOrAmbiguousEvidence(string $raw): void
    {
        try {
            (new CsobBbfAdviceParser())->split($raw);
            self::fail('Malformed BBF advice was accepted.');
        } catch (BankConnectorException $e) {
            self::assertSame(BankConnectorException::INVALID_RESPONSE, $e->errorCode);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function invalidFiles(): iterable
    {
        $test = new self('synthetic');
        $valid = $test->advice([$test->domestic()]);

        yield 'empty' => [''];
        yield 'bare carriage return' => [str_replace("\r\n", "\r", $valid)];
        yield 'truncated transaction' => [substr_replace($valid, substr($test->domestic(), 0, 547), 33 + 2, 548)];
        yield 'missing lock' => [substr($valid, 0, -54)];
        yield 'invalid booking date' => [str_replace('20260914', '20260231', $valid)];
        yield 'invalid service identifier' => ['X' . substr($valid, 1)];
        yield 'unknown record' => [str_replace('ADVMUL 02', 'UNKNOWN 02', $valid)];
        yield 'duplicate advice identity' => [$valid . $valid];
        yield 'mixed accounts in one advice' => [$test->advice([
            $test->domestic(),
            $test->domestic(['own_account' => '0000002000000018', 'bank_ref' => '2']),
        ])];
        yield 'mixed currencies in one advice' => [$test->advice([
            $test->domestic(),
            $test->domestic(['currency' => 'EUR', 'bank_ref' => '2']),
        ])];
        yield 'duplicate bank reference' => [$test->advice([$test->domestic(), $test->domestic()])];
        yield 'invalid amount' => [str_replace('0000000001234,56', '000000000123X,56', $valid)];
        yield 'amount exceeds database precision' => [$test->advice([$test->domestic([
            'amount' => '1000000000000.00',
        ])])];
        yield 'signed foreign amount' => [$test->advice([$test->foreign(['account_amount' => '-000000000078.90'])])];
        yield 'nonzero foreign fee without currency' => [$test->advice([$test->foreign([
            'local_fee' => '0000000000001.00',
            'local_currency' => '',
        ])])];
        yield 'control byte' => [substr_replace($valid, "\x00", 100, 1)];
    }

    /** @param list<string> $transactions */
    private function advice(array $transactions, string $adviceRef = '20260914000001'): string
    {
        $header = 'T777777  HEADER 0001.0000BBCSOB ';
        $advice = 'T777777  ADVMUL 01' . $adviceRef . ' ';
        $lock = 'T777777  LOCK   99            ' . '2609141200001' . '        1';
        return implode("\r\n", [$header, $advice, ...$transactions, $lock]) . "\r\n";
    }

    /** @param array<string,string> $replace */
    private function domestic(array $replace = []): string
    {
        $values = array_replace([
            'own_account' => '0000001000000005',
            'bank_ref' => '0000000000012345',
            'amount' => '0000000001234,56',
            'indicator' => 'C ',
            'balance' => '0000000009876.54',
            'currency' => 'CZK',
        ], $replace);
        $line = str_repeat(' ', 548);
        $line = $this->field($line, 1, 7, 'T777777');
        $line = $this->field($line, 10, 6, 'ADVMUL');
        $line = $this->field($line, 17, 2, '02');
        $line = $this->field($line, 19, 2, '11');
        $line = $this->field($line, 21, 22, '2026091400000000000001');
        $line = $this->field($line, 43, 3, '100');
        $line = $this->field($line, 46, 4, '0300');
        $line = $this->field($line, 50, 14, '10000001');
        $line = $this->field($line, 64, 35, 'Synthetic Owner');
        $line = $this->field($line, 99, 34, $values['own_account']);
        $line = $this->field($line, 149, 16, $values['bank_ref']);
        $line = $this->field($line, 165, 8, '20260914');
        $line = $this->field($line, 173, 8, '20260914');
        $line = $this->field($line, 189, 2, $values['indicator']);
        $line = $this->field($line, 191, 16, $values['amount']);
        $line = $this->field($line, 207, 3, $values['currency']);
        $line = $this->field($line, 210, 16, $values['balance']);
        $line = $this->field($line, 226, 1, 'C');
        $line = $this->field($line, 227, 11, '0100');
        $line = $this->field($line, 238, 34, '0000002000000018');
        $line = $this->field($line, 272, 35, 'Žluťoučký test s.r.o.');
        $line = $this->field($line, 307, 4, '0308');
        $line = $this->field($line, 311, 10, '0260100010');
        $line = $this->field($line, 321, 10, '0000000042');
        $line = $this->field($line, 351, 35, 'Syntetická úhrada');
        return $this->field($line, 491, 35, 'Poznámka plátce');
    }

    /** @param array<string,string> $replace */
    private function foreign(array $replace = []): string
    {
        $values = array_replace([
            'own_account' => '0000001000000005',
            'account_amount' => '0000000000078.90',
            'account_currency' => 'EUR',
            'bank_ref' => '0000000000000000000000054321',
            'counterparty_account' => 'CZ6108000000191000000005',
            'bic' => 'TESTCZPPXXX',
            'local_fee' => '',
            'local_currency' => '',
            'foreign_fee' => '',
            'foreign_currency' => '',
        ], $replace);
        $line = str_repeat(' ', 928);
        $line = $this->field($line, 1, 7, 'T777777');
        $line = $this->field($line, 10, 6, 'ADVMUZ');
        $line = $this->field($line, 17, 2, '02');
        $line = $this->field($line, 19, 3, 'DBE');
        $line = $this->field($line, 22, 14, '10000001');
        $line = $this->field($line, 36, 16, 'CLIENT-REF-1');
        $line = $this->field($line, 52, 28, $values['bank_ref']);
        $line = $this->field($line, 80, 3, '090');
        $line = $this->field($line, 83, 34, $values['own_account']);
        $line = $this->field($line, 117, 35, 'Synthetic Foreign Partner');
        $line = $this->field($line, 152, 35, 'Berlin');
        $line = $this->field($line, 257, 35, $values['counterparty_account']);
        $line = $this->field($line, 292, 35, 'Synthetic Test Bank');
        $line = $this->field($line, 432, 35, 'Synthetic foreign payment');
        $line = $this->field($line, 572, 16, '0000000000100.00');
        $line = $this->field($line, 588, 3, 'USD');
        $line = $this->field($line, 591, 16, $values['account_amount']);
        $line = $this->field($line, 607, 3, $values['account_currency']);
        $line = $this->field($line, 610, 12, '001.2674271');
        $line = $this->field($line, 622, 16, $values['local_fee']);
        $line = $this->field($line, 638, 3, $values['local_currency']);
        $line = $this->field($line, 641, 16, $values['foreign_fee']);
        $line = $this->field($line, 657, 3, $values['foreign_currency']);
        $line = $this->field($line, 679, 8, '20260914');
        $line = $this->field($line, 687, 8, '20260914');
        $line = $this->field($line, 878, 3, 'SHA');
        return $this->field($line, 881, 11, $values['bic']);
    }

    private function field(string $line, int $position, int $length, string $value): string
    {
        self::assertLessThanOrEqual($length, mb_strlen($value, 'UTF-8'));
        $value = iconv('UTF-8', 'Windows-1250', $value);
        self::assertNotFalse($value);
        $value = str_pad($value, $length);
        return substr_replace($line, $value, $position - 1, $length);
    }
}
