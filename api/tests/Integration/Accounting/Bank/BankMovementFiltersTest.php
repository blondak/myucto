<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Bank\UnmatchedBankExportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class BankMovementFiltersTest extends BankPostingTestCase
{
    public function testAmountAndPostingFiltersApplyAcrossPagination(): void
    {
        $statement = $this->statement();
        $wanted = $this->transaction($statement, -1234.50);
        $other = $this->transaction($statement, -123450.00);
        $filters = ['scope' => 'all', 'account' => self::ACCOUNT, 'year' => self::YEAR, 'q' => '1 234,50'];
        $result = $this->suggestionRepo->paginateUnposted($this->supplierId, 1, 0, $filters);
        self::assertSame([$wanted], array_column($result['items'], 'id'));
        self::assertNotContains($other, array_column($result['items'], 'id'));
        $this->postPredpis('bank', $wanted, '311', '221', 1234.50);
        self::assertSame(1, $this->suggestionRepo->paginateUnposted($this->supplierId, 0, 0, $filters + ['posting_status' => 'posted'])['total']);
        self::assertSame(0, $this->suggestionRepo->paginateUnposted($this->supplierId, 0, 0, $filters + ['posting_status' => 'unposted'])['total']);
    }

    public function testExportDoesNotDuplicateInlineOwnBankCode(): void
    {
        $statement = $this->statement(self::ACCOUNT . '/' . self::BANK_CODE);
        $this->transaction($statement, -10.00, ['description' => 'Inline account synthetic']);
        $service = new UnmatchedBankExportService($this->db);
        $file = $service->buildAll($this->supplierId, ['year' => self::YEAR, 'q' => 'Inline account synthetic']);
        self::assertSame(1, $file['count']);
        self::assertSame(self::ACCOUNT . '/' . self::BANK_CODE, $service->preview($this->supplierId, $statement)['account']);
        $path = tempnam(sys_get_temp_dir(), 'bank_account_test_');
        file_put_contents($path, $file['bytes']);
        try {
            $book = IOFactory::load($path);
            self::assertSame(self::ACCOUNT . '/' . self::BANK_CODE, $book->getActiveSheet()->getCell('A6')->getValue());
            $book->disconnectWorksheets();
        } finally { @unlink($path); }
    }

    public function testLoanRepaymentShowsCreditAccountAsCounterAccount(): void
    {
        $tx = $this->transaction($this->statement(), -1500.00);
        $this->postPredpis('bank', $tx, '231', '221', 1500.00);
        $info = $this->service->transactionPostingInfo($this->supplierId, [$tx]);
        self::assertSame(['231'], $info[$tx]['counter_account_codes']);
    }

    public function testAllAccountExportIncludesUnloadedRowsAndJournalNotes(): void
    {
        $statement = $this->statement();
        $first = $this->transaction($statement, 1234.50, ['description' => '=synthetic formula', 'counterparty_name' => 'Syntetická protistrana', 'counterparty_account' => '1000000005', 'counterparty_bank' => '0100']);
        $entry = $this->postPredpis('bank', $first, '221', '311', 1234.50);
        $this->db->pdo()->prepare('INSERT INTO journal_entry_notes (supplier_id, entry_id, body, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$this->supplierId, $entry, 'Syntetická poznámka', $this->userId]);
        for ($index = 0; $index < 501; $index++) $this->transaction($statement, -10.00);
        $this->transaction($statement, 20.00, ['match_status' => 'ignored']);
        $foreign = $this->statement('1000000005', '0100', $this->otherSupplierId());
        $this->transaction($foreign, -10.00);
        $service = new UnmatchedBankExportService($this->db);
        $file = $service->buildAll($this->supplierId, ['year' => self::YEAR, 'account' => self::ACCOUNT, 'sort' => 'amount', 'direction' => 'desc']);
        self::assertSame(502, $file['count']);
        $path = tempnam(sys_get_temp_dir(), 'bank_export_test_');
        file_put_contents($path, $file['bytes']);
        try {
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            self::assertSame('Datum platby', $sheet->getCell('B5')->getValue());
            self::assertSame(self::ACCOUNT . '/' . self::BANK_CODE, $sheet->getCell('A6')->getValue());
            self::assertSame('=synthetic formula', $sheet->getCell('C6')->getValue());
            self::assertSame('s', $sheet->getCell('C6')->getDataType());
            self::assertSame('Protistrana', $sheet->getCell('D5')->getValue());
            self::assertSame('Účet protistrany', $sheet->getCell('E5')->getValue());
            self::assertSame('Syntetická protistrana', $sheet->getCell('D6')->getValue());
            self::assertSame('1000000005/0100', $sheet->getCell('E6')->getValue());
            self::assertSame(1234.50, (float) $sheet->getCell('F6')->getValue());
            self::assertSame('Syntetická poznámka', $sheet->getCell('H6')->getValue());
            self::assertSame(-10.00, (float) $sheet->getCell('F507')->getValue());
            self::assertNull($sheet->getCell('F508')->getValue());
            $book->disconnectWorksheets();
        } finally { @unlink($path); }
    }
}
