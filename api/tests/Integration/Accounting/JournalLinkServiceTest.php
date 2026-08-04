<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalRelatedAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\JournalLinkService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Graf vazeb doklad ↔ úhrada promítnutý do deníku ({@see JournalLinkService}).
 *
 * Dědí z BankPostingTestCase kvůli fixturám (výpis, transakce, faktura, párování) —
 * jsou to přesně ty tabulky, nad kterými graf běží, a duplikovat je znovu by znamenalo
 * dvě verze téhož setupu, které se rozejdou.
 *
 * TĚŽIŠTĚ TESTU:
 *  1. SYMETRIE — z účtování banky se musí dát na účtování dokladu a zpátky. Vazba
 *     jen jedním směrem je horší než žádná: účetní se z ní nedostane zpět.
 *  2. DEDUPLIKACE — jedna bankovní úhrada vydané faktury je zapsaná ve TŘECH
 *     tabulkách (invoice_payments, payment_matches, legacy matched_invoice_id).
 *     Bez deduplikace panel ukáže tutéž platbu třikrát.
 *  3. SHODA ODZNAKU S PANELEM — hasRelatedMap() (odznak v seznamu) a related()
 *     (obsah panelu) jsou dvě různé SQL cesty. Když se rozejdou, seznam lže.
 *  4. Bezpečnost — syntetická source_id uzávěrkových zápisů a cizí tenant.
 *
 * DB běží v transakci (rollback v tearDown).
 */
#[Group('integration')]
final class JournalLinkServiceTest extends BankPostingTestCase
{
    private JournalLinkService $links;
    private JournalRelatedAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->links  = $this->container->get(JournalLinkService::class);
        $this->action = $this->container->get(JournalRelatedAction::class);
    }

    public function testInvoiceAndBankEntriesSeeEachOther(): void
    {
        $clientId  = $this->client('Odběratel JL');
        $invoiceId = $this->saleInvoice('JL0001', $clientId, 1000.0);
        $txId      = $this->transaction($this->statement(), 1000.0, ['variable_symbol' => 'JL0001']);
        $this->invoicePayment($invoiceId, $txId, 1000.0);

        $invoiceEntry = $this->postPredpis('invoice', $invoiceId, '311', '604', 1000.0);
        $bankEntry    = $this->postPredpis('bank', $txId, '221', '311', 1000.0);

        $fromInvoice = $this->related($invoiceEntry);
        self::assertCount(1, $fromInvoice['items'], 'Faktura vidí právě jednu úhradu.');
        self::assertSame('bank', $fromInvoice['items'][0]['source_type']);
        self::assertSame('payment', $fromInvoice['items'][0]['relation']);
        self::assertSame($txId, $fromInvoice['items'][0]['source_id']);
        self::assertSame($bankEntry, $fromInvoice['items'][0]['entry_id'], 'Musí nést ZAÚČTOVÁNÍ úhrady, ne jen doklad.');
        self::assertTrue($fromInvoice['items'][0]['entry_posted']);

        $fromBank = $this->related($bankEntry);
        self::assertCount(1, $fromBank['items'], 'Banka vidí právě jeden hrazený doklad.');
        self::assertSame('invoice', $fromBank['items'][0]['source_type']);
        self::assertSame('document', $fromBank['items'][0]['relation']);
        self::assertSame($invoiceId, $fromBank['items'][0]['source_id']);
        self::assertSame($invoiceEntry, $fromBank['items'][0]['entry_id'], 'Zpětná hrana chybí — z banky se nedostaneš na doklad.');
        self::assertIsArray($fromBank['items'][0]['route']);
        self::assertSame('invoice-detail', $fromBank['items'][0]['route']['name']);
    }

    public function testDuplicatePaymentEdgesYieldSingleItem(): void
    {
        $clientId  = $this->client('Odběratel JL dedup');
        $invoiceId = $this->saleInvoice('JL0002', $clientId, 500.0);
        // Tatáž platba zapsaná všemi třemi cestami, které v DB reálně koexistují.
        $txId = $this->transaction($this->statement(), 500.0, [
            'variable_symbol'    => 'JL0002',
            'matched_invoice_id' => $invoiceId,
            'match_status'       => 'auto_exact',
        ]);
        $this->invoicePayment($invoiceId, $txId, 500.0);
        $this->invoicePaymentMatch($txId, $invoiceId, 500.0);

        $invoiceEntry = $this->postPredpis('invoice', $invoiceId, '311', '604', 500.0);
        $bankEntry    = $this->postPredpis('bank', $txId, '221', '311', 500.0);

        $fromInvoice = $this->related($invoiceEntry);
        self::assertCount(1, $fromInvoice['items'], 'Trojitá evidence téže platby nesmí dát tři položky.');
        self::assertSame($bankEntry, $fromInvoice['items'][0]['entry_id']);

        $fromBank = $this->related($bankEntry);
        self::assertCount(1, $fromBank['items'], 'Trojitá evidence téže platby nesmí dát tři položky.');
        self::assertSame($invoiceEntry, $fromBank['items'][0]['entry_id']);
    }

    public function testPartialPaymentReportsAllocatedAmountSeparately(): void
    {
        $clientId  = $this->client('Odběratel JL splátka');
        $invoiceId = $this->saleInvoice('JL0003', $clientId, 1000.0);
        // Jedna transakce na 3 000 kryje víc dokladů; na tuhle fakturu jde 400.
        $txId = $this->transaction($this->statement(), 3000.0);
        $this->invoicePayment($invoiceId, $txId, 400.0);

        $invoiceEntry = $this->postPredpis('invoice', $invoiceId, '311', '604', 1000.0);
        $item = $this->related($invoiceEntry)['items'][0];

        self::assertSame(3000.0, (float) $item['amount'], 'amount = celá transakce.');
        self::assertSame(400.0, (float) $item['allocated_amount'], 'allocated = co z ní připadlo na doklad.');
    }

    public function testUnpostedCashPaymentIsListedWithoutEntry(): void
    {
        $clientId  = $this->client('Odběratel JL pokladna');
        $invoiceId = $this->saleInvoice('JL0004', $clientId, 250.0);
        $cashDocId = $this->cashDocument($invoiceId, 250.0);

        $invoiceEntry = $this->postPredpis('invoice', $invoiceId, '311', '604', 250.0);
        $items = $this->related($invoiceEntry)['items'];

        self::assertCount(1, $items);
        self::assertSame('cash', $items[0]['source_type']);
        self::assertSame($cashDocId, $items[0]['source_id']);
        // Nezaúčtovaná úhrada je sama o sobě nález (saldo nesedí s deníkem) —
        // panel ji musí ukázat, ne zamlčet kvůli chybějícímu zápisu.
        self::assertNull($items[0]['entry_id']);
        self::assertFalse($items[0]['entry_posted']);
    }

    public function testBadgeAgreesWithPanelForWholePage(): void
    {
        $clientId  = $this->client('Odběratel JL odznak');
        $invoiceId = $this->saleInvoice('JL0005', $clientId, 700.0);
        $txId      = $this->transaction($this->statement(), 700.0);
        $this->invoicePayment($invoiceId, $txId, 700.0);

        $linkedInvoice = $this->postPredpis('invoice', $invoiceId, '311', '604', 700.0);
        $linkedBank    = $this->postPredpis('bank', $txId, '221', '311', 700.0);
        // Kontrolní zápisy BEZ vazby — odznak u nich svítit nesmí.
        $lonelyInvoice = $this->postPredpis('invoice', $this->saleInvoice('JL0006', $clientId, 100.0), '311', '604', 100.0);
        $lonelyBank    = $this->postPredpis('bank', $this->transaction($this->statement(), 100.0), '221', '648', 100.0);
        $closing       = $this->postPredpis('closing', ClosingSourceId::stockClosing($this->periodId), '221', '648', 1.0);

        $page = [];
        foreach ([$linkedInvoice, $linkedBank, $lonelyInvoice, $lonelyBank, $closing] as $id) {
            $page[] = $this->journal->find($id, $this->supplierId);
        }
        $map = $this->links->hasRelatedMap($this->supplierId, $page);

        foreach ($page as $entry) {
            $entryId  = (int) $entry['id'];
            $hasPanel = $this->related($entryId)['items'] !== [];
            self::assertSame(
                $hasPanel,
                isset($map[$entryId]),
                "Odznak a panel se u zápisu #{$entryId} rozcházejí ({$entry['source_type']})."
            );
        }
        self::assertArrayHasKey($linkedInvoice, $map);
        self::assertArrayHasKey($linkedBank, $map);
        self::assertArrayNotHasKey($lonelyInvoice, $map);
        self::assertArrayNotHasKey($lonelyBank, $map);
        self::assertArrayNotHasKey($closing, $map);
    }

    public function testSyntheticSourceIdIsNeverResolved(): void
    {
        // Kdyby se syntetický klíč použil jako id dokladu, resolver by nabídl
        // NÁHODNOU CIZÍ fakturu jako „protějšek" uzávěrkového zápisu.
        foreach ([
            ['closing', ClosingSourceId::stockClosing($this->periodId)],
            ['fx_revaluation', ClosingSourceId::fxSaldo($this->periodId)],
            ['invoice', ClosingSourceId::STOCK_SLOT_BASE + 7],
        ] as [$type, $sourceId]) {
            $related = $this->links->related($this->supplierId, [
                'id' => 0, 'source_type' => $type, 'source_id' => $sourceId,
            ]);
            self::assertSame([], $related['items'], "{$type}/{$sourceId} nesmí mít protějšky.");
        }
    }

    public function testForeignTenantSeesNothing(): void
    {
        $clientId  = $this->client('Odběratel JL cizí');
        $invoiceId = $this->saleInvoice('JL0007', $clientId, 900.0);
        $txId      = $this->transaction($this->statement(), 900.0);
        $this->invoicePayment($invoiceId, $txId, 900.0);
        $this->postPredpis('bank', $txId, '221', '311', 900.0);

        $related = $this->links->related($this->supplierId + 99999, [
            'id' => 0, 'source_type' => 'invoice', 'source_id' => $invoiceId,
        ]);
        self::assertSame([], $related['items'], 'Vazby cizího tenanta nesmí prosáknout.');
    }

    public function testEndpointIsKeyedByEntryIdAndScopedByTenant(): void
    {
        $clientId  = $this->client('Odběratel JL endpoint');
        $invoiceId = $this->saleInvoice('JL0008', $clientId, 300.0);
        $txId      = $this->transaction($this->statement(), 300.0);
        $this->invoicePayment($invoiceId, $txId, 300.0);
        $bankEntry    = $this->postPredpis('bank', $txId, '221', '311', 300.0);
        $invoiceEntry = $this->postPredpis('invoice', $invoiceId, '311', '604', 300.0);

        $res = $this->invoke(['id' => (string) $bankEntry]);
        self::assertSame(200, $res['status'], 'Panel je readonly+.');
        self::assertSame($bankEntry, $res['body']['entry_id']);
        self::assertSame($invoiceEntry, $res['body']['items'][0]['entry_id']);
        self::assertFalse($res['body']['truncated']);

        $missing = $this->invoke(['id' => '999999999']);
        self::assertSame(404, $missing['status'], 'Neexistující zápis → 404, ne prázdný seznam.');
        self::assertSame('not_found', $missing['body']['error']['code']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{items:list<array<string,mixed>>, truncated:bool} */
    private function related(int $entryId): array
    {
        $entry = $this->journal->find($entryId, $this->supplierId);
        self::assertIsArray($entry, "Zápis #{$entryId} nenalezen.");
        return $this->links->related($this->supplierId, $entry);
    }

    /** payment_matches pro VYDANOU fakturu (base třída umí jen přijatou). */
    private function invoicePaymentMatch(int $txId, int $invoiceId, float $amount): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, invoice_id, amount, match_type)
             VALUES (?, ?, ?, ?, "manual")'
        )->execute([$this->supplierId, $txId, $invoiceId, $amount]);
    }

    /** Pokladní doklad hradící vydanou fakturu; ZÁMĚRNĚ bez zaúčtování. */
    private function cashDocument(int $invoiceId, float $amount): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO cash_registers (supplier_id, name, currency_code, account_code)
             VALUES (?, ?, "CZK", "211")'
        )->execute([$this->supplierId, 'Pokladna JL ' . uniqid()]);
        $registerId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date,
                 description, total_amount, currency_code, invoice_id, status)
             VALUES (?, ?, "in", "invoice_payment", ?, ?, "Úhrada faktury", ?, "CZK", ?, "draft")'
        )->execute([
            $this->supplierId, $registerId, 'PPD-JL-' . uniqid(),
            self::YEAR . '-06-16', $amount, $invoiceId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param  array<string,string> $args
     * @return array{status:int, body:array<string,mixed>}
     */
    private function invoke(array $args): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'readonly']);

        $resp = $this->action->__invoke($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
