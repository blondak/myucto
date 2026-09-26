<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Repository\JournalEntryDocumentLinkRepository;
use MyInvoice\Repository\SaldoRepository;
use MyInvoice\Support\Sql\LinkedManualSettlementSql;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class LinkedManualSettlementRedStornoTest extends BankPostingTestCase
{
    public static function documents(): array
    {
        return [
            'receivable' => ['invoice', '311', 'debit', 'credit'],
            'payable' => ['purchase_invoice', '321', 'credit', 'debit'],
        ];
    }

    #[DataProvider('documents')]
    public function testLinkedRedStornoSettlesDocumentAndClosingReconciliation(string $type, string $account, string $redSide, string $settleSide): void
    {
        $partner = $this->client('Syntetický partner ručního vyrovnání');
        if ($type === 'invoice') {
            $doc = $this->saleInvoice('LINK-RED', $partner, 100.0);
            $this->postPredpis($type, $doc, '311', '602', 100.0);
            $this->db->pdo()->prepare('UPDATE invoices SET status = "paid", paid_at = ? WHERE id = ? AND supplier_id = ?')
                ->execute([self::YEAR . '-06-15', $doc, $this->supplierId]);
        } else {
            $doc = $this->purchaseInvoice('LINK-RED', $partner, 100.0);
            $this->postPredpis($type, $doc, '518', '321', 100.0);
            $this->db->pdo()->prepare('UPDATE purchase_invoices SET status = "paid" WHERE id = ? AND supplier_id = ?')
                ->execute([$doc, $this->supplierId]);
        }
        $entry = $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => $account, 'side' => $redSide, 'amount' => 100.0, 'is_red_storno' => true],
            ['account_code' => '395', 'side' => $settleSide, 'amount' => 100.0, 'is_red_storno' => true],
        ], ['entry_date' => self::YEAR . '-06-15', 'description' => 'Ruční vyrovnání', 'posted_by' => $this->userId]);
        $this->container->get(JournalEntryDocumentLinkRepository::class)->add($entry, $this->supplierId, $type, $doc, null, $this->userId);
        $asOf = self::YEAR . '-12-31';
        $stmt = $this->db->pdo()->prepare(LinkedManualSettlementSql::sql($type, $settleSide, LinkedManualSettlementSql::accountPrefixPredicate($account)));
        $stmt->execute([$this->supplierId, $asOf, $asOf]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows);
        self::assertSame($doc, (int) $rows[0]['doc_id']);
        self::assertEqualsWithDelta(100.0, (float) $rows[0]['settled'], 0.001);
        $closing = $this->container->get(ClosingRepository::class);
        self::assertEqualsWithDelta(0.0, $closing->accountBalance($this->supplierId, $account, $asOf), 0.001);
        $saldo = $this->container->get(SaldoRepository::class);
        $resolved = $saldo->resolveAccount($this->supplierId, $account);
        self::assertNotNull($resolved);
        self::assertSame([], $saldo->openItems($this->supplierId, (int) $resolved['id'], $asOf, $account, null, $partner));
        $issues = $type === 'invoice'
            ? $closing->paidInvoicesOpenSaldo($this->supplierId, $asOf)
            : $closing->paidPurchasesOpenSaldo($this->supplierId, $asOf);
        self::assertSame([], array_values(array_filter($issues, static fn (array $row): bool => $row['id'] === $doc)));
    }
}
