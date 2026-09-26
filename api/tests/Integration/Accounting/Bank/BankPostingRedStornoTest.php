<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\PostingException;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class BankPostingRedStornoTest extends BankPostingTestCase
{
    public function testManualSplitPreservesRedStornoAndValidatesSignedBankMovement(): void
    {
        $tx = $this->transaction($this->statement(), 80.00);

        $result = $this->service->postManual($this->supplierId, $tx, [
            'lines' => [
                ['account_code' => '221', 'side' => 'debit', 'amount' => 100.00],
                ['account_code' => '221', 'side' => 'debit', 'amount' => 20.00, 'is_red_storno' => true],
                ['account_code' => '395', 'side' => 'credit', 'amount' => 100.00],
                ['account_code' => '395', 'side' => 'credit', 'amount' => 20.00, 'is_red_storno' => true],
            ],
        ], $this->meta());

        $lines = $this->entryLines((int) $result['entry_id']);
        self::assertCount(2, array_filter($lines, static fn (array $line): bool => $line['is_red_storno']));
        self::assertSame(8000, $this->signedBankCents($lines));
        self::assertSame(0, $this->signedBalanceCents($lines));
    }

    public function testManualSplitRejectsRawBankNetThatOnlyMatchesAfterDroppingRedFlag(): void
    {
        $tx = $this->transaction($this->statement(), 80.00);

        try {
            $this->service->postManual($this->supplierId, $tx, [
                'lines' => [
                    ['account_code' => '221', 'side' => 'debit', 'amount' => 100.00],
                    ['account_code' => '221', 'side' => 'credit', 'amount' => 20.00, 'is_red_storno' => true],
                    ['account_code' => '395', 'side' => 'credit', 'amount' => 80.00],
                ],
            ], $this->meta());
            self::fail('Neplatný efekt +120 Kč na 221 nesmí projít jako +80 Kč.');
        } catch (PostingException $e) {
            self::assertSame('validation_failed', $e->errorCode);
            self::assertStringContainsString('neodpovídá částce z výpisu', $e->getMessage());
        }

        self::assertSame(0, $this->entryCountForTx($tx));
    }

    public function testManualSplitRejectsNonBooleanRedStornoFlag(): void
    {
        $tx = $this->transaction($this->statement(), 80.00);

        $this->expectException(PostingException::class);
        $this->expectExceptionMessage('is_red_storno');
        $this->service->postManual($this->supplierId, $tx, [
            'lines' => [
                ['account_code' => '221', 'side' => 'debit', 'amount' => 80.00, 'is_red_storno' => 'false'],
                ['account_code' => '395', 'side' => 'credit', 'amount' => 80.00],
            ],
        ], $this->meta());
    }

    public function testForeignManualSplitUsesSignedEffectsForConversionAndFxTrace(): void
    {
        $this->seedRate('EUR', 24.25);
        $tx = $this->transaction($this->statement(), 80.00, ['currency' => 'EUR']);

        $result = $this->service->postManual($this->supplierId, $tx, [
            'amounts_in_foreign' => true,
            'lines' => [
                ['account_code' => '221', 'side' => 'debit', 'amount' => 100.00],
                ['account_code' => '221', 'side' => 'debit', 'amount' => 20.00, 'is_red_storno' => true],
                ['account_code' => '648', 'side' => 'credit', 'amount' => 90.02],
                ['account_code' => '648', 'side' => 'credit', 'amount' => 89.98],
                ['account_code' => '648', 'side' => 'credit', 'amount' => 100.00, 'is_red_storno' => true],
            ],
        ], $this->meta());

        $lines = $this->entryLines((int) $result['entry_id']);
        $bank = array_values(array_filter(
            $lines,
            static fn (array $line): bool => str_starts_with($line['account_code'], '221'),
        ));
        self::assertCount(2, $bank);
        self::assertSame([20.0, 100.0], array_map(
            static fn (array $line): float => (float) $line['amount_foreign'],
            $bank,
        ));
        self::assertSame([true, false], array_column($bank, 'is_red_storno'));
        self::assertSame(194000, $this->signedBankCents($lines));
        self::assertSame(0, $this->signedBalanceCents($lines));
    }

    public function testLiveEntryWithDifferentRedFlagsIsNotTreatedAsAlreadyPosted(): void
    {
        $client = $this->client('Odběratel red storno');
        $invoice = $this->saleInvoice('FV-RED-IDEMP', $client, 80.00);
        $this->postPredpis('invoice', $invoice, '311', '602', 80.00);
        $tx = $this->transaction($this->statement(), 80.00, [
            'match_status' => 'auto_exact',
            'matched_invoice_id' => $invoice,
        ]);
        $this->invoicePayment($invoice, $tx, 80.00);

        $normalized = $this->service->prepareRepostLines($this->supplierId, $tx, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 80.00],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 80.00],
        ]);
        foreach ($normalized as &$line) {
            $line['is_red_storno'] = true;
        }
        unset($line);

        $entryId = $this->posting->postDocument($this->supplierId, 'bank', $tx, $normalized, [
            'entry_date' => self::YEAR . '-06-15',
            'description' => 'Obnovený historický zápis',
            'posted' => true,
            'posted_by' => $this->userId,
        ]);

        $result = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('posted', $result['action']);
        self::assertNotSame('already_posted', $result['reason'] ?? null);
        self::assertSame($entryId, (int) $result['entry_id']);
        self::assertSame([false, false], array_column($this->entryLines($entryId), 'is_red_storno'));
    }

    /** @return list<array{account_code:string,side:string,amount:string,is_red_storno:bool,currency_code:?string,amount_foreign:?string}> */
    private function entryLines(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount, l.is_red_storno, l.currency_code, l.amount_foreign
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ?
              ORDER BY l.amount, l.line_no, l.id'
        );
        $stmt->execute([$entryId]);
        return array_map(static function (array $line): array {
            $line['is_red_storno'] = (bool) $line['is_red_storno'];
            return $line;
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @param list<array{account_code:string,side:string,amount:string,is_red_storno:bool}> $lines */
    private function signedBankCents(array $lines): int
    {
        return array_sum(array_map(static function (array $line): int {
            if (!str_starts_with($line['account_code'], '221')) {
                return 0;
            }
            $cents = (int) round((float) $line['amount'] * 100);
            return $cents * ($line['side'] === 'debit' ? 1 : -1) * ($line['is_red_storno'] ? -1 : 1);
        }, $lines));
    }

    /** @param list<array{side:string,amount:string,is_red_storno:bool}> $lines */
    private function signedBalanceCents(array $lines): int
    {
        return array_sum(array_map(static function (array $line): int {
            $cents = (int) round((float) $line['amount'] * 100);
            return $cents * ($line['side'] === 'debit' ? 1 : -1) * ($line['is_red_storno'] ? -1 : 1);
        }, $lines));
    }

    private function seedRate(string $currency, float $rate): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([self::YEAR . '-06-15', $currency, $rate]);
    }
}
