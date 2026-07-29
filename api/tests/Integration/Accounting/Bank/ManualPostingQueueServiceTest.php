<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\ManualPostingQueueService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Featura H (REAL_data_followup_UX.md) — agregace fronty ručního doúčtování.
 * Ověřuje, že se sbírají všechny zdroje s jejich důvody a že se vylučují položky,
 * které do fronty NEPATŘÍ (email_notice avízo, ignorovaný pohyb, vyřešený
 * document_request a nově i pohyby s čekajícím návrhem kontace — ty patří do Automatu).
 */
#[Group('integration')]
final class ManualPostingQueueServiceTest extends BankPostingTestCase
{
    private ManualPostingQueueService $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->container->get(ManualPostingQueueService::class);
    }

    public function testAggregatesAcrossSourcesWithReasons(): void
    {
        // 1) Pohyb s pending návrhem — do fronty NEPATŘÍ (schvaluje se v Automatu).
        $stId = $this->statement();
        $txPending = $this->transaction($stId, -500.0, ['counterparty_name' => 'OSSZ']);
        $this->suggestionRepo->createIfNoPending([
            'supplier_id' => $this->supplierId, 'bank_transaction_id' => $txPending, 'rule_id' => null,
            'source' => 'learned', 'debit_account_code' => '502', 'credit_account_code' => '221',
            'amount' => 500.0, 'status' => 'pending',
        ]);

        // 2) Pohyb s needs_input návrhem — také jen v Automatu.
        $txNeeds = $this->transaction($stId, -1000.0, ['counterparty_name' => 'Klient s.r.o.']);
        $this->suggestionRepo->createIfNoPending([
            'supplier_id' => $this->supplierId, 'bank_transaction_id' => $txNeeds, 'rule_id' => null,
            'source' => 'payment_match', 'debit_account_code' => '221', 'credit_account_code' => '311',
            'amount' => 1000.0, 'status' => 'needs_input', 'note' => 'already_paid_verify',
        ]);

        // 3) Genuinely žádný návrh, CZK → reason „no_rule".
        $txNoRule = $this->transaction($stId, -150.0, ['counterparty_name' => 'Drobnost s.r.o.']);

        // 4) Genuinely žádný návrh, cizí měna → reason „fx_not_supported".
        $txFx = $this->transaction($stId, -80.0, ['counterparty_name' => 'Foreign Ltd', 'currency' => 'EUR']);

        // 5) E-mailové avízo — NESMÍ se ve frontě objevit vůbec.
        $this->transaction($stId, -30.0, ['source' => 'email_notice', 'counterparty_name' => 'Avízo s.r.o.']);

        // 6) Ignorovaný pohyb — NESMÍ se objevit.
        $this->transaction($stId, -20.0, ['match_status' => 'ignored', 'counterparty_name' => 'Ignorovaný']);

        // 7) Nezaúčtovaná přijatá faktura.
        $vendor = $this->client('Dodavatel XY');
        $piId = $this->purchaseInvoice('FP-2099-01', $vendor, 2500.0);

        // 8) Nezaúčtovaná vydaná faktura.
        $customer = $this->client('Klient AB');
        $invId = $this->saleInvoice('2099001', $customer, 3300.0);

        // 9) Vyžádaný chybějící doklad (status requested).
        $this->db->pdo()->prepare(
            'INSERT INTO document_requests (supplier_id, description, amount, context_date, status, created_by)
             VALUES (?, ?, ?, ?, "requested", ?)'
        )->execute([$this->supplierId, 'Chybí faktura za nákup materiálu', 999.0, self::YEAR . '-06-16', $this->userId]);

        // 10) Vyřešený document_request — NESMÍ se objevit.
        $this->db->pdo()->prepare(
            'INSERT INTO document_requests (supplier_id, description, status, created_by, resolved_at)
             VALUES (?, "Už vyřešeno", "resolved", ?, NOW())'
        )->execute([$this->supplierId, $this->userId]);

        $result = $this->queue->queue($this->supplierId);

        $byId = [];
        foreach ($result['items'] as $item) {
            $byId[$item['id']] = $item;
        }

        $ids = array_column($result['items'], 'id');
        self::assertContains('bank_no_suggestion:' . $txNoRule, $ids);
        self::assertContains('bank_no_suggestion:' . $txFx, $ids);
        self::assertContains('purchase_invoice:' . $piId, $ids);
        self::assertContains('sales_invoice:' . $invId, $ids);

        // Čekající návrh není „k doúčtování" — automatika svou práci odvedla, čeká se
        // na schválení v Automatu. Pohyb s návrhem proto ve frontě není v žádné podobě.
        self::assertNotContains('bank_no_suggestion:' . $txPending, $ids);
        self::assertNotContains('bank_no_suggestion:' . $txNeeds, $ids);
        foreach ($result['items'] as $item) {
            self::assertNotSame('bank_suggestion', $item['type']);
        }
        self::assertArrayNotHasKey('bank_suggestion', $result['counts']['by_type']);

        self::assertSame('no_rule', $byId['bank_no_suggestion:' . $txNoRule]['reason']);
        self::assertSame('fx_not_supported', $byId['bank_no_suggestion:' . $txFx]['reason']);
        self::assertSame('document_not_posted', $byId['purchase_invoice:' . $piId]['reason']);
        self::assertSame('document_not_posted', $byId['sales_invoice:' . $invId]['reason']);

        $requestItems = array_values(array_filter($result['items'], static fn (array $i): bool => $i['type'] === 'document_request'));
        self::assertCount(1, $requestItems);
        self::assertSame('Chybí faktura za nákup materiálu', $requestItems[0]['reason_detail']);

        // Avízo a ignorovaný pohyb se nikde neobjeví.
        foreach ($result['items'] as $item) {
            self::assertNotSame('Avízo s.r.o.', $item['counterparty']);
            self::assertNotSame('Ignorovaný', $item['counterparty']);
        }

        self::assertGreaterThanOrEqual(2, $result['counts']['by_type']['bank_no_suggestion']);
        self::assertSame(1, $result['counts']['by_type']['purchase_invoice']);
        self::assertSame(1, $result['counts']['by_type']['sales_invoice']);
        self::assertSame(1, $result['counts']['by_type']['document_request']);
    }

    public function testTypeAndReasonFiltersNarrowResult(): void
    {
        $stId = $this->statement();
        $tx = $this->transaction($stId, -150.0, ['counterparty_name' => 'Drobnost s.r.o.']);
        // ostatní supplier nesmí prosakovat.

        $result = $this->queue->queue($this->supplierId, ['type' => 'bank_no_suggestion']);
        foreach ($result['items'] as $item) {
            self::assertSame('bank_no_suggestion', $item['type']);
        }
        self::assertContains('bank_no_suggestion:' . $tx, array_column($result['items'], 'id'));

        $filteredOut = $this->queue->queue($this->supplierId, ['type' => 'document_request', 'reason' => 'document_missing']);
        foreach ($filteredOut['items'] as $item) {
            self::assertSame('document_request', $item['type']);
            self::assertSame('document_missing', $item['reason']);
        }
    }

    public function testSuggestionInAnyStateKeepsTransactionOutOfQueue(): void
    {
        $stId = $this->statement();
        $tx = $this->transaction($stId, -500.0, ['counterparty_name' => 'Snoozed s.r.o.']);
        $created = $this->suggestionRepo->createIfNoPending([
            'supplier_id' => $this->supplierId, 'bank_transaction_id' => $tx, 'rule_id' => null,
            'source' => 'learned', 'debit_account_code' => '502', 'credit_account_code' => '221',
            'amount' => 500.0, 'status' => 'pending',
        ]);
        $this->suggestionRepo->snooze($this->supplierId, $created['id'], self::YEAR . '-12-31 23:59:59', 'later', $this->userId);

        $ids = array_column($this->queue->queue($this->supplierId)['items'], 'id');
        self::assertNotContains('bank_suggestion:' . $created['id'], $ids);
        self::assertNotContains('bank_no_suggestion:' . $tx, $ids);
    }

}
