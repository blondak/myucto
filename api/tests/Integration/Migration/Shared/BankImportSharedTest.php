<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration\Shared;

use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Shared\BankAccountRegistrar;
use MyInvoice\Service\Migration\Shared\BankStatementImportWriter;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class BankImportSharedTest extends SharedMigrationDbTestCase
{
    public function testWriterCreatesStatementTransactionsAndBalances(): void
    {
        $supplier = $this->supplier();
        $writer = new BankStatementImportWriter($this->db, 'konektor');

        $statement = $writer->createStatement($supplier, 'stmt|B1|2025|3', 'B1 3/2025 dlouhý štítek výpisu', '1000000005', '0100', 'CZK', '2025-03-10', null);
        $row = $this->row('SELECT source, file_name, file_hash, account_number, bank_code, currency, statement_number, transaction_count FROM bank_statements WHERE id = ?', [$statement]);
        self::assertSame([
            'source' => 'import',
            'file_name' => 'konektor-B1_3_2025_dlouh_____t__tek_v__pisu.import',
            'file_hash' => hash('sha256', 'konektor|' . $supplier . '|stmt|B1|2025|3'),
            'account_number' => '1000000005',
            'bank_code' => '0100',
            'currency' => 'CZK',
            'statement_number' => 'B1 3/2025 dlouhý ští',
            'transaction_count' => 0,
        ], array_merge($row, ['transaction_count' => (int) $row['transaction_count']]));

        $tx = $writer->insertTransaction($supplier, $statement, 'tx|1', [
            'source_ref' => 'B1 3 #1', 'posted_at' => '2025-03-12', 'amount' => '-150.50', 'currency' => 'CZK',
            'variable_symbol' => '2025001', 'bank_ref' => 'REF1',
        ]);
        $t = $this->row('SELECT statement_id, amount, variable_symbol, constant_symbol, counterparty_name, bank_ref, import_fingerprint, source FROM bank_transactions WHERE id = ?', [$tx]);
        self::assertSame([(string) $statement, '-150.50', '2025001', null, null, 'REF1', hash('sha256', 'konektor|' . $supplier . '|tx|1'), 'statement'],
            [(string) $t['statement_id'], (string) $t['amount'], $t['variable_symbol'], $t['constant_symbol'], $t['counterparty_name'], $t['bank_ref'], $t['import_fingerprint'], $t['source']]);

        $writer->touchStatement($supplier, $statement, 1, '2025-03-12');
        $writer->setBalancesInCents($supplier, $statement, 100000, 84950, 0, 15050);
        $s = $this->row('SELECT transaction_count, statement_date, prev_balance, curr_balance, credit_total, debit_total FROM bank_statements WHERE id = ?', [$statement]);
        self::assertSame(['1', '2025-03-12', '1000.00', '849.50', '0.00', '150.50'], array_map('strval', array_values($s)));

        $writer->setBalances($supplier, $statement, '1.00', '2.00', '1.00', '0.00', 7);
        self::assertSame('7', (string) $this->row('SELECT transaction_count FROM bank_statements WHERE id = ?', [$statement])['transaction_count']);
    }

    public function testRegistrarRegistersAndLinksCompanyAccountsOnce(): void
    {
        $supplier = $this->supplier();
        $registrar = new BankAccountRegistrar($this->db, $this->container->get(SupplierBankAccountRepository::class));
        $p = new ImportProtocol('import');
        $accounts = [
            'KB' => ['number' => '8800000005', 'bank' => '0100', 'iban' => '', 'currency' => 'CZK', 'label' => 'Hlavní účet', 'suffix' => '001'],
            'EUR' => ['number' => '', 'bank' => '', 'iban' => '', 'currency' => 'EUR', 'label' => 'Bez čísla', 'suffix' => null],
        ];

        $registered = $registrar->register($supplier, $accounts, $p, 'bank');
        self::assertSame(['KB'], array_keys($registered), 'účet bez čísla i IBANu se neeviduje');
        self::assertSame('001', (string) $this->row('SELECT analytic_suffix FROM supplier_bank_accounts WHERE id = ?', [$registered['KB']])['analytic_suffix']);

        $registrar->fillCurrencyAccount($supplier, 'CZK', '8800000005', '0100', null);
        $registrar->linkCompanyAccounts($supplier, $accounts, ['KB', 'EUR'], $registered, $p, 'bank', true);
        $currency = $this->row("SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' AND account_number = '8800000005'", [$supplier]);
        self::assertNotSame([], $currency, 'výchozí měna dostala číslo účtu');
        self::assertSame((string) $currency['id'], (string) $this->row('SELECT currency_id FROM supplier_bank_accounts WHERE id = ?', [$registered['KB']])['currency_id']);
        self::assertSame(1, count($this->rows('SELECT id FROM currencies WHERE supplier_id = ?', [$supplier])), 'účet už na měně je, nový řádek nevznikne');

        $second = ['FIO' => ['number' => '8800000013', 'bank' => '2010', 'iban' => '', 'currency' => 'CZK', 'label' => 'Fio', 'suffix' => null]];
        $registrar->linkCompanyAccounts($supplier, $second, ['FIO'], $registrar->register($supplier, $second), $p, 'bank', false);
        $added = $this->row("SELECT label, symbol, name_cs, is_default FROM currencies WHERE supplier_id = ? AND account_number = '8800000013'", [$supplier]);
        self::assertSame(['Fio', 'Kč', 'Česká koruna', '0'], array_map('strval', array_values($added)));
    }
}
