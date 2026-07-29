<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Accounting\Bank\BankPostingRuleAction;
use MyInvoice\Service\Accounting\PostingException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tenant izolace: pravidla/návrhy tenanta A nezasahují do B; approve cizího návrhu →
 * 404; dry-run vidí jen vlastní tx; detachSource s cizím supplier_id → false. §8.
 */
#[Group('integration')]
final class BankPostingTenantTest extends BankPostingTestCase
{
    public function testRuleAndSuggestionAreTenantScoped(): void
    {
        $other = $this->cloneSupplier('double_entry');
        $ruleId = $this->rule([
            'name' => 'A', 'direction' => 'outgoing', 'counterparty_account' => '778001',
            'amount_min' => 100.00, 'amount_max' => 90000.00,
            'debit_account_code' => '336', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        self::assertNotEmpty($this->ruleRepo->findActive($this->supplierId, 'outgoing'));
        self::assertSame([], $this->ruleRepo->findActive($other, 'outgoing'), 'Cizí tenant pravidlo nevidí.');

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000.00, ['counterparty_account' => '778001']);
        $res = $this->service->handleTransaction($tx, $this->userId);
        $sid = (int) $res['suggestion_id'];
        self::assertNotNull($this->suggestionRepo->find($this->supplierId, $sid));
        self::assertNull($this->suggestionRepo->find($other, $sid), 'Cizí tenant návrh nevidí.');
    }

    public function testApproveForeignSuggestionThrows404(): void
    {
        $ruleId = $this->rule([
            'name' => 'A', 'direction' => 'outgoing', 'counterparty_account' => '778002',
            'amount_min' => 100.00, 'amount_max' => 90000.00,
            'debit_account_code' => '336', 'credit_account_code' => '221', 'mode' => 'suggest',
        ]);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -1000.00, ['counterparty_account' => '778002']);
        $sid = (int) $this->service->handleTransaction($tx, $this->userId)['suggestion_id'];

        $other = $this->cloneSupplier('double_entry');
        try {
            $this->service->approveSuggestion($other, $sid, $this->meta());
            self::fail('Approve cizího návrhu musí selhat.');
        } catch (PostingException $e) {
            self::assertSame('not_found', $e->errorCode);
            self::assertSame(404, $e->httpStatus);
        }
    }

    public function testDetachSourceForeignSupplierReturnsFalse(): void
    {
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleInvoice('FV-T1', $client, 1000.00);
        $this->postPredpis('invoice', $inv, '311', '602', 1000.00);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 1000.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, 1000.00);
        $entry = (int) $this->service->handleTransaction($tx, $this->userId)['entry_id'];

        $other = $this->cloneSupplier('double_entry');
        self::assertFalse($this->journal->detachSource($entry, $other), 'Cizí supplier neodpojí zápis.');
        self::assertTrue($this->journal->detachSource($entry, $this->supplierId), 'Vlastní supplier odpojí.');
    }

    public function testDryRunSeesOnlyOwnTenantTransactions(): void
    {
        // Vlastní tx.
        $stmtA = $this->statement();
        $this->transaction($stmtA, 1000.00, ['counterparty_account' => '778003']);

        // Cizí tenant se stejným kritériem, ale vlastním účtem výpisu → dry-run ho nevidí.
        $other = $this->cloneSupplier('double_entry');
        $this->currencyRow($other, 'CZK', '660066006', '2250');
        $stmtB = $this->statement('660066006', '2250', $other);
        $this->transaction($stmtB, 1000.00, ['counterparty_account' => '778003']);

        $action = $this->container->get(BankPostingRuleAction::class);
        $res = $this->callAction($action, 'dryRun', 'POST', 'accountant', [
            'name' => 'Test', 'direction' => 'incoming', 'counterparty_account' => '778003',
            'debit_account_code' => '221', 'credit_account_code' => '604',
        ]);
        self::assertSame(200, $res['status']);
        self::assertSame(1, $res['body']['matched_count'], 'Dry-run počítá jen vlastní tx tenanta.');
    }
}
