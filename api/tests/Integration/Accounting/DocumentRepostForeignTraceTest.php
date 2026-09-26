<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Service\Accounting\DocumentRepostService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Přeúčtování dokladu z dialogu posílá jen účet, stranu a částku. Cizoměnová stopa
 * saldokontního řádku (321 v EUR) se musí převzít z opravovaného zápisu — dřív ji
 * nový zápis po stornu ztratil (321.100 currency NULL), takže přecenění a kurzové
 * rozdíly úhrady přestaly doklad vidět jako eurový.
 */
#[Group('integration')]
final class DocumentRepostForeignTraceTest extends BankPostingTestCase
{
    private DocumentRepostService $repost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repost = $this->container->get(DocumentRepostService::class);
    }

    /** Eurová PF: MD 518 1 000 / D 321 1 000 (EUR 40,00, kurz 25). Vrací [pf, entry]. */
    private function eurPurchase(string $tag): array
    {
        $pf = $this->purchaseInvoice('PF-EUR-' . $tag, $this->client('Dodavatel ' . $tag), 1000.00);
        $map = $this->accounts->codeToIdMap($this->supplierId);
        $entry = $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $this->periodId,
            'entry_date'  => self::YEAR . '-06-10',
            'document_no' => 'PF-EUR-' . $tag,
            'description' => 'Předpis',
            'source_type' => 'purchase_invoice',
            'source_id'   => $pf,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], [
            ['account_id' => $map['518']['id'], 'side' => 'debit', 'amount' => 1000.00],
            ['account_id' => $map['321']['id'], 'side' => 'credit', 'amount' => 1000.00,
                'currency_code' => 'EUR', 'fx_rate' => 25.0, 'amount_foreign' => 40.00],
        ]);
        return [$pf, $entry];
    }

    /** @return array{currency_code:?string, amount_foreign:?string, amount:string} */
    private function payableLine(int $entryId): array
    {
        return $this->db->pdo()->query(
            "SELECT l.currency_code, l.amount_foreign, l.amount FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = {$entryId} AND a.account_code LIKE '321%'"
        )->fetch(\PDO::FETCH_ASSOC);
    }

    public function testReplaceKeepsForeignTraceOfUnchangedPayable(): void
    {
        [$pf, $entry] = $this->eurPurchase('REPLACE');

        $r = $this->repost->repost($this->supplierId, 'purchase_invoice', $pf, [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 1000.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1000.00],
        ], ['user_id' => $this->userId]);

        self::assertSame('replace', $r['strategy']);
        $line = $this->payableLine($r['entry_id']);
        self::assertSame('EUR', $line['currency_code']);
        self::assertEqualsWithDelta(40.00, (float) $line['amount_foreign'], 0.001);
    }

    public function testReverseAndNewEntryKeepsForeignTrace(): void
    {
        [$pf, $entry] = $this->eurPurchase('REVERSE');
        $this->posting->reverse($this->supplierId, $entry, [
            'entry_date' => self::YEAR . '-06-10', 'user_id' => $this->userId, 'posted_by' => $this->userId,
        ]);

        $r = $this->repost->repost($this->supplierId, 'purchase_invoice', $pf, [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 1000.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1000.00],
        ], ['user_id' => $this->userId]);

        self::assertSame('reverse', $r['strategy']);
        self::assertNotSame($entry, $r['entry_id'], 'oprava jde novým zápisem');
        $line = $this->payableLine($r['entry_id']);
        self::assertSame('EUR', $line['currency_code'], 'nový zápis převzal měnu závazku');
        self::assertEqualsWithDelta(40.00, (float) $line['amount_foreign'], 0.001);
    }

    /** Změněná částka závazku: cizí částka se přepočte poměrem, kurz zůstává. */
    public function testChangedPayableAmountRecomputesForeignAmount(): void
    {
        [$pf] = $this->eurPurchase('POMER');

        $r = $this->repost->repost($this->supplierId, 'purchase_invoice', $pf, [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 1250.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 1250.00],
        ], ['user_id' => $this->userId]);

        $line = $this->payableLine($r['entry_id']);
        self::assertSame('EUR', $line['currency_code']);
        self::assertEqualsWithDelta(50.00, (float) $line['amount_foreign'], 0.001);
    }
}
