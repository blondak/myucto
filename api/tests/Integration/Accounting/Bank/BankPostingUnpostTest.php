<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\PostingException;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Zrušení zaúčtování: reverse + detachSource (uvolní unique slot), re-post po
 * detachi, unpost v zavřeném období selže a párování/zápis zůstává. §8.
 */
#[Group('integration')]
final class BankPostingUnpostTest extends BankPostingTestCase
{
    /** @return array{tx:int, entry:int} */
    private function postMatchedFv(string $vs, float $amount = 1000.00): array
    {
        $client = $this->client('Odběratel s.r.o.');
        $inv = $this->saleInvoice('FV-' . $vs, $client, $amount);
        $this->postPredpis('invoice', $inv, '311', '602', $amount);
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, $amount, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $tx, $amount);
        $res = $this->service->handleTransaction($tx, $this->userId);
        return ['tx' => $tx, 'entry' => (int) $res['entry_id']];
    }

    public function testUnpostReversesAndDetaches(): void
    {
        ['tx' => $tx, 'entry' => $entry] = $this->postMatchedFv('U1');

        $reversalId = $this->service->unpost($this->supplierId, $tx, ['entry_date' => self::YEAR . '-06-20', 'user_id' => $this->userId]);
        self::assertGreaterThan(0, $reversalId);
        $reversal = $this->journal->find($reversalId, $this->supplierId);
        self::assertSame(self::YEAR . '-06-15', $reversal['entry_date'], 'Storno zůstává v období původního zápisu.');

        // Slot ('bank', tx) je volný — findBySource už zápis nevrací (source_id=NULL).
        self::assertNull($this->journal->findBySource($this->supplierId, 'bank', $tx));
        // Původní zápis existuje (detached, reversed) — audit pár zůstává.
        $orig = $this->journal->find($entry, $this->supplierId);
        self::assertNull($orig['source_id'], 'Originál odpojen (source_id=NULL).');
        self::assertNotNull($orig['reversed_by'], 'Originál stornován.');
        self::assertNotNull($this->suggestionRepo->pendingForTx($this->supplierId, $tx), 'Stornovaná kontace se vrátí do fronty.');
    }

    public function testRematchAfterUnpostCreatesNewEntry(): void
    {
        ['tx' => $tx, 'entry' => $entry] = $this->postMatchedFv('U2');
        $this->service->unpost($this->supplierId, $tx, ['entry_date' => self::YEAR . '-06-20', 'user_id' => $this->userId]);

        // Re-match → nový zápis projde (unique slot volný).
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action']);
        self::assertNotSame($entry, (int) $res['entry_id'], 'Nový zápis, ne původní odpojený.');
        self::assertNotNull($this->journal->findBySource($this->supplierId, 'bank', $tx));
    }

    public function testJournalDeleteRemovesBankEntryAndRequeuesTransactionWithoutReversal(): void
    {
        ['tx' => $tx, 'entry' => $entry] = $this->postMatchedFv('DELETE');
        $action = $this->container->get(JournalAction::class);
        $request = (new ServerRequestFactory())
            ->createServerRequest('DELETE', '/api/accounting/journal/' . $entry)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant']);

        $response = $action->delete($request, new Psr7Response(), ['id' => (string) $entry]);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->journal->find($entry, $this->supplierId));
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM journal_entries WHERE reversed_by = {$entry}"
        )->fetchColumn(), 'Přímé smazání nesmí vytvořit protizápis.');
        self::assertNotNull(
            $this->suggestionRepo->pendingForTx($this->supplierId, $tx),
            'Smazané bankovní zaúčtování se vrátí do fronty.',
        );
        self::assertSame(1, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM bank_posting_suggestions
              WHERE supplier_id = {$this->supplierId} AND bank_transaction_id = {$tx}
                AND status = 'superseded' AND note = 'deleted_by_user'"
        )->fetchColumn());
        self::assertSame(1, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM activity_log
              WHERE supplier_id = {$this->supplierId}
                AND action = 'accounting.entry_deleted' AND entity_id = {$entry}"
        )->fetchColumn());
    }

    public function testUnpostedQueueIncludesTransactionWithoutSuggestionAndExcludesPostedOrIgnored(): void
    {
        $statement = $this->statement();
        $unposted = $this->transaction($statement, -420.00, ['match_status' => 'unmatched']);
        $ignored = $this->transaction($statement, -421.00, ['match_status' => 'ignored']);
        ['tx' => $posted] = $this->postMatchedFv('QUEUE');

        $result = $this->suggestionRepo->paginateUnposted($this->supplierId, 100, 0);
        $ids = array_column($result['items'], 'id');

        self::assertContains($unposted, $ids, 'Pohyb bez návrhu kontace musí být ve frontě.');
        self::assertNotContains($ignored, $ids, 'Ignorované pohyby se neúčtují.');
        self::assertNotContains($posted, $ids, 'Pohyb s aktivním zápisem už není nezaúčtovaný.');
        $row = array_values(array_filter(
            $result['items'],
            static fn (array $item): bool => $item['id'] === $unposted,
        ))[0];
        self::assertNull($row['posting'], 'Pohyb bez návrhu musí zůstat ručně zaúčtovatelný.');
    }

    public function testUnpostInClosedPeriodFailsAndKeepsEntry(): void
    {
        ['tx' => $tx, 'entry' => $entry] = $this->postMatchedFv('U3');
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        try {
            $this->service->unpost($this->supplierId, $tx, ['entry_date' => self::YEAR . '-06-20', 'user_id' => $this->userId]);
            self::fail('Unpost v zavřeném období musí selhat.');
        } catch (PostingException $e) {
            self::assertSame('period_closed', $e->errorCode);
        }
        // Zápis zůstává navázaný a nestornovaný (nic se nerozpojilo).
        $stillThere = $this->journal->findBySource($this->supplierId, 'bank', $tx);
        self::assertNotNull($stillThere);
        self::assertSame($entry, (int) $stillThere['id']);
        self::assertNull($stillThere['reversed_by'] ?? null);
    }

    public function testUnpostWithoutEntryThrows404(): void
    {
        $stmt = $this->statement();
        $tx = $this->transaction($stmt, 500.00, ['match_status' => 'unmatched']);
        $this->expectException(PostingException::class);
        $this->service->unpost($this->supplierId, $tx, ['entry_date' => self::YEAR . '-06-20', 'user_id' => $this->userId]);
    }

    /** #52: „Všechny pohyby" musí umět zobrazit spárovanou fakturu/dodavatele stejně jako detail výpisu. */
    public function testUnpostedAllScopeIncludesMatchedInvoiceAndPurchaseDetails(): void
    {
        $stmt = $this->statement();

        $client = $this->client('Odběratel matched s.r.o.');
        $inv = $this->saleInvoice('MATCH1', $client, 500.00);
        $incoming = $this->transaction($stmt, 500.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $inv]);
        $this->invoicePayment($inv, $incoming, 500.00);

        $vendor = $this->client('Dodavatel matched s.r.o.');
        $pi = $this->purchaseInvoice('PF-MATCH1', $vendor, 300.00);
        $outgoing = $this->transaction($stmt, -300.00, ['match_status' => 'manual']);
        $this->paymentMatch($outgoing, $pi, 300.00);

        $result = $this->suggestionRepo->paginateUnposted($this->supplierId, 100, 0, ['scope' => 'all']);
        $byId = [];
        foreach ($result['items'] as $item) {
            $byId[$item['id']] = $item;
        }

        self::assertSame('MATCH1', $byId[$incoming]['matched_varsymbol']);
        self::assertSame('Odběratel matched s.r.o.', $byId[$incoming]['matched_client_name']);
        self::assertSame(500.00, $byId[$incoming]['matched_invoice_amount']);
        self::assertCount(1, $byId[$incoming]['matched_invoices']);
        self::assertSame($inv, $byId[$incoming]['matched_invoices'][0]['invoice_id']);

        self::assertSame($pi, $byId[$outgoing]['matched_purchase_invoice_id']);
        self::assertSame('PF-MATCH1', $byId[$outgoing]['matched_purchase_ref']);
        self::assertSame('Dodavatel matched s.r.o.', $byId[$outgoing]['matched_vendor_name']);
    }

    public function testUnpostInSoftLockedPeriodFailsAndKeepsEntry(): void
    {
        ['tx' => $tx, 'entry' => $entry] = $this->postMatchedFv('U4');
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until=VALUES(locked_until)'
        )->execute([$this->supplierId, self::YEAR . '-06-30']);

        try {
            $this->service->unpost($this->supplierId, $tx, ['user_id' => $this->userId]);
            self::fail('Unpost v měkce zamčeném období musí selhat.');
        } catch (PostingException $e) {
            self::assertSame('period_closed', $e->errorCode);
        }

        $stillThere = $this->journal->findBySource($this->supplierId, 'bank', $tx);
        self::assertSame($entry, (int) ($stillThere['id'] ?? 0));
        self::assertNull($stillThere['reversed_by'] ?? null);
    }
}
