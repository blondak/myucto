<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\Client\GetClientAction;
use MyInvoice\Action\PurchaseInvoice\LinkAdvancePurchaseInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Propojení přijaté zálohy (advance) s finální fakturou + dopad na náklady.
 *
 * Pokrývá:
 *   - advanceCandidates: nespárované zálohy téhož dodavatele
 *   - linkAdvance: nastaví FK + advance_paid_amount, kandidát zmizí, find() vrátí
 *     linked_advance / settled_by
 *   - UNIQUE: jednu zálohu nelze navázat na dvě finální faktury
 *   - validace: jiný dodavatel / link na ne-advance → výjimka
 *   - unlinkAdvance: vazba pryč, kandidát se vrátí
 *   - findAdvanceByReference + suggestAdvanceLink (AI návrh)
 *
 * Izolováno v roce 2099 pod existujícím supplierem, vše uklizeno v tearDown.
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class PurchaseAdvanceLinkTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private PurchaseInvoiceRepository $repo;
    private GetClientAction $getClientAction;
    private LinkAdvancePurchaseInvoiceAction $linkAction;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;

    /** @var int[] */
    private array $vendorIds = [];
    /** @var int[] */
    private array $piIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db        = $container->get(Connection::class);
            $this->repo      = $container->get(PurchaseInvoiceRepository::class);
            $this->getClientAction = $container->get(GetClientAction::class);
            $this->linkAction = $container->get(LinkAdvancePurchaseInvoiceAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        // Deníkové hlavičky se musí smazat VÝSLOVNĚ: test neběží v transakci a na
        // journal_entries.source_id není FK, takže smazání faktury je tu nechá jako
        // sirotky. Nasbíralo se jich takhle 13, než si toho všiml integrity cron.
        // Maže se podle vlastních dokladů, ne podle uložených ID — přežije to i pád
        // testu uprostřed vkládání.
        foreach ($this->piIds as $id) {
            $pdo->prepare(
                "DELETE l FROM journal_entry_lines l
                   JOIN journal_entries e ON e.id = l.entry_id
                  WHERE e.supplier_id = ? AND e.source_type = 'purchase_invoice' AND e.source_id = ?"
            )->execute([$this->supplierId, $id]);
            $pdo->prepare(
                "DELETE FROM journal_entries
                  WHERE supplier_id = ? AND source_type = 'purchase_invoice' AND source_id = ?"
            )->execute([$this->supplierId, $id]);
        }
        // FK advance_purchase_invoice_id je ON DELETE SET NULL → pořadí mazání nevadí.
        foreach ($this->piIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->vendorIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testLinkUnlinkAndCandidates(): void
    {
        $vendor = $this->vendor('Dodavatel A', 'CZ10000001');
        $advance = $this->purchase($vendor, 'advance', 'ZAL-1', 'paid', 5000.0, $this->d(10));
        $final   = $this->purchase($vendor, 'invoice', 'FAK-1', 'received', 20000.0, $this->d(20));

        // Kandidát: záloha je k dispozici k propojení
        $cands = $this->repo->advanceCandidates($final, $this->supplierId);
        self::assertCount(1, $cands, 'advanceCandidates: nespárovaná záloha téhož dodavatele');
        self::assertSame($advance, $cands[0]['id']);

        // Propojení: nastaví FK + advance_paid_amount (finální mělo 0)
        $this->repo->linkAdvance($final, $advance, $this->supplierId);
        $finalRow = $this->repo->find($final, $this->supplierId);
        self::assertSame($advance, $finalRow['advance_purchase_invoice_id']);
        self::assertNotNull($finalRow['linked_advance']);
        self::assertSame($advance, $finalRow['linked_advance']['id']);
        self::assertEqualsWithDelta(5000.0, (float) $finalRow['advance_paid_amount'], 0.01,
            'advance_paid_amount doplněn z totalu zálohy');

        // Reverzní pohled: záloha ví, kdo ji vyúčtovává
        $advRow = $this->repo->find($advance, $this->supplierId);
        self::assertNotNull($advRow['settled_by']);
        self::assertSame($final, $advRow['settled_by']['id']);

        // Po propojení už záloha není kandidátem
        self::assertCount(0, $this->repo->advanceCandidates($final, $this->supplierId));

        // Unlink → kandidát se vrátí
        $this->repo->unlinkAdvance($final, $this->supplierId);
        self::assertNull($this->repo->find($final, $this->supplierId)['advance_purchase_invoice_id']);
        self::assertCount(1, $this->repo->advanceCandidates($final, $this->supplierId));
    }

    /**
     * Zaúčtovaný doklad nelze odpojit od zálohy — zúčtování zálohy je součástí
     * účetního zápisu (PostingService::appendAdvanceSettlementPurchase → 321/314).
     * Po rozpojení by v deníku zůstaly řádky odkazující na neexistující vazbu.
     *
     * Zrcadlo guardu na vydané větvi (InvoiceRepository::unlinkAdvance), kde je
     * chráněný finál s odpočtovými řádky § 37a. Přijatá větev byla holý UPDATE.
     */
    public function testUnlinkRefusedForPostedInvoice(): void
    {
        $vendor = $this->vendor('Dodavatel zauctovany', 'CZ10000009');
        $advance = $this->purchase($vendor, 'advance', 'ZAL-P', 'paid', 5000.0, $this->d(10));
        $final   = $this->purchase($vendor, 'invoice', 'FAK-P', 'received', 20000.0, $this->d(20));
        $this->repo->linkAdvance($final, $advance, $this->supplierId);

        // Aktivní zaúčtovaný zápis dokladu (stačí hlavička — guard se dívá jen na ni).
        $pdo = $this->db->pdo();
        $periodId = (int) $pdo->query(
            "SELECT id FROM accounting_periods WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn();
        if ($periodId === 0) {
            self::markTestSkipped('Bez účetního období nelze založit zápis.');
        }
        $pdo->prepare(
            "INSERT INTO journal_entries
                (supplier_id, period_id, entry_date, description, source_type, source_id, posted_at)
             VALUES (?, ?, ?, 'Test zaúčtování', 'purchase_invoice', ?, NOW())"
        )->execute([$this->supplierId, $periodId, $this->d(20), $final]);

        try {
            $this->repo->unlinkAdvance($final, $this->supplierId);
            self::fail('Rozpojení zaúčtované faktury musí selhat.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Zaúčtovanou fakturu nelze odpojit', $e->getMessage());
        }

        self::assertSame(
            $advance,
            $this->repo->find($final, $this->supplierId)['advance_purchase_invoice_id'],
            'Vazba musí zůstat nedotčená.',
        );
    }

    /**
     * Druh VÁZANÉHO DDKP je neměnný. Odejít z `tax_document` je u dokladu, který patří
     * k zálohové faktuře, jednosměrka do rozbitého stavu: doklad by ztratil výjimky
     * (mimo příkaz k úhradě, mimo náklady, vlastní větev v PostingService) a vznikl by
     * fantomový závazek v plné výši už zaplacené zálohy.
     *
     * Hlídají se OBĚ cesty — rychlá změna ze seznamu i uložení z editoru; ta druhá
     * navíc musí SELHAT HLASITĚ, ne tiše vrátit starý druh (jinak uživatel v editoru
     * pořád vidí typ, který právě přepnul).
     *
     * Samostatný DDKP bez vazeb naopak přepnout JDE — je to typicky jen špatná AI
     * klasifikace obyčejné faktury ({@see PurchaseTaxDocumentKindChangeTest}).
     */
    public function testLinkedTaxDocumentKindIsImmutable(): void
    {
        $vendor  = $this->vendor('Dodavatel DDKP', 'CZ10000010');
        $advance = $this->purchase($vendor, 'advance', 'ZAL-DDKP-1', 'received', 2100.0, $this->d(10));
        $ddkp    = $this->purchase($vendor, 'tax_document', 'DDKP-1', 'received', 2100.0, $this->d(15));
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ?')
            ->execute([$advance, $ddkp]);

        // Cesta A: explicitní změna druhu → odmítnutí s hláškou.
        $err = $this->repo->updateDocumentKind($ddkp, $this->supplierId, 'invoice');
        self::assertNotNull($err, 'Překlopení vázaného DDKP musí být odmítnuto.');
        self::assertStringContainsString('zálohov', $err);
        self::assertSame('tax_document', $this->repo->find($ddkp, $this->supplierId)['document_kind']);

        // Cesta B: uložení z editoru → výjimka, druh zůstává.
        try {
            $this->repo->updateDraft($ddkp, [
                'vendor_id'              => $vendor,
                'vendor_invoice_number'  => 'DDKP-1',
                'document_kind'          => 'invoice',
                'issue_date'             => $this->d(15),
                'due_date'               => $this->d(15),
                'received_at'            => $this->d(15),
                'currency_id'            => $this->currencyId,
            ], $this->supplierId);
            self::fail('Uložení vázaného DDKP jako faktury musí selhat.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('zálohov', $e->getMessage());
        }
        self::assertSame(
            'tax_document',
            $this->repo->find($ddkp, $this->supplierId)['document_kind'],
            'Uložení z editoru nesmí druh vázaného DDKP přepsat.',
        );
    }

    public function testCandidatesOrderedByClosestAmount(): void
    {
        $vendor = $this->vendor('Dodavatel G', 'CZ10000007');
        $final  = $this->purchase($vendor, 'invoice', 'FAK-7', 'received', 20000.0, $this->d(20));
        // Dvě zálohy: jedna malá (5000), jedna ~ ve výši faktury (19900) → ta má být první.
        $small = $this->purchase($vendor, 'advance', 'ZAL-7S', 'received', 5000.0, $this->d(10));
        $close = $this->purchase($vendor, 'advance', 'ZAL-7C', 'received', 19900.0, $this->d(11));

        $cands = $this->repo->advanceCandidates($final, $this->supplierId);
        self::assertCount(2, $cands);
        self::assertSame($close, $cands[0]['id'], 'Nejbližší částka (19900 ≈ 20000) musí být první');
        self::assertSame($small, $cands[1]['id']);
    }

    public function testUniquePreventsDoubleLink(): void
    {
        $vendor = $this->vendor('Dodavatel B', 'CZ10000002');
        $advance = $this->purchase($vendor, 'advance', 'ZAL-2', 'received', 3000.0, $this->d(10));
        $final1  = $this->purchase($vendor, 'invoice', 'FAK-2A', 'received', 10000.0, $this->d(20));
        $final2  = $this->purchase($vendor, 'invoice', 'FAK-2B', 'received', 12000.0, $this->d(21));

        $this->repo->linkAdvance($final1, $advance, $this->supplierId);
        $this->expectException(\Throwable::class); // UNIQUE uq_pi_advance_link
        $this->repo->linkAdvance($final2, $advance, $this->supplierId);
    }

    public function testLinkValidations(): void
    {
        $vendorA = $this->vendor('Dodavatel C', 'CZ10000003');
        $vendorB = $this->vendor('Dodavatel D', 'CZ10000004');
        $advanceA = $this->purchase($vendorA, 'advance', 'ZAL-3', 'received', 1000.0, $this->d(10));
        $finalB   = $this->purchase($vendorB, 'invoice', 'FAK-3', 'received', 5000.0, $this->d(20));

        // Jiný dodavatel → výjimka
        try {
            $this->repo->linkAdvance($finalB, $advanceA, $this->supplierId);
            self::fail('Očekávána výjimka — jiný dodavatel');
        } catch (\Throwable $e) {
            self::assertStringContainsStringIgnoringCase('dodavatel', $e->getMessage());
        }

        // Link na ne-advance → výjimka
        $finalA = $this->purchase($vendorA, 'invoice', 'FAK-3A', 'received', 5000.0, $this->d(21));
        $this->expectException(\Throwable::class);
        $this->repo->linkAdvance($finalA, $finalB, $this->supplierId); // finalB není advance
    }

    /**
     * N-013: varování `advance_has_tax_document` musí přijít i z link-advance.
     *
     * Uživatel, který nejdřív založí vyúčtovací fakturu v plné výši a AŽ POTOM na ni
     * naváže zálohu s DDKP, prošel dřív bez varování — create/update už dávno proběhly
     * a link-advance vracel holý payload. DPH z DDKP (§ 28) přitom zůstane odečtené
     * podruhé přes konečnou fakturu, takže tohle je poslední místo, kde na to lze
     * upozornit.
     */
    public function testLinkAdvanceWarnsWhenAdvanceHasTaxDocument(): void
    {
        $vendor  = $this->vendor('Dodavatel DDKP-Link', 'CZ10000011');
        $advance = $this->purchase($vendor, 'advance', 'ZAL-DDKP', 'received', 12100.0, $this->d(10));
        $final   = $this->purchase($vendor, 'invoice', 'FAK-DDKP', 'received', 24200.0, $this->d(20));

        $body = $this->linkAdvance($final, $advance);
        self::assertArrayNotHasKey('_warnings', $body, 'Bez DDKP se nesmí varovat.');

        // K záloze vznikne DDKP (§28) → dvojí odpočet DPH hrozí.
        $ddkp = $this->purchase($vendor, 'tax_document', 'DDKP-L1', 'received', 2100.0, $this->d(11));
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ?')
            ->execute([$advance, $ddkp]);

        $this->repo->unlinkAdvance($final, $this->supplierId);
        $body = $this->linkAdvance($final, $advance);
        self::assertSame(['advance_has_tax_document'], $body['_warnings'] ?? null);

        // Stornované DDKP už dvojí odpočet nezpůsobí → varování musí zmizet.
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'cancelled' WHERE id = ?")->execute([$ddkp]);
        $this->repo->unlinkAdvance($final, $this->supplierId);
        $body = $this->linkAdvance($final, $advance);
        self::assertArrayNotHasKey('_warnings', $body, 'Stornované DDKP se nezapočítává.');
    }

    /** Zavolá POST /purchase-invoices/{id}/link-advance a vrátí dekódovaná data. */
    private function linkAdvance(int $finalId, int $advanceId): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/purchase-invoices/' . $finalId . '/link-advance')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withParsedBody(['advance_id' => $advanceId]);

        $resp = ($this->linkAction)($req, new Psr7Response(), ['id' => (string) $finalId]);
        $raw  = (string) $resp->getBody();
        self::assertSame(200, $resp->getStatusCode(), 'link-advance selhalo: ' . $raw);

        $body = json_decode($raw, true);
        // Json::ok posílá payload BEZ obálky — kdyby se to změnilo, test by tiše
        // aserotoval nad prázdným polem, takže tvar rovnou ověříme.
        self::assertIsArray($body);
        self::assertSame($finalId, (int) ($body['id'] ?? 0), 'Odpověď musí nést payload faktury.');

        return $body;
    }

    public function testLinkUsesOnlyPaidAmountAndCapsItAtFinalTotal(): void
    {
        $vendor = $this->vendor('Dodavatel J', 'CZ10000010');
        $unpaid = $this->purchase($vendor, 'advance', 'ZAL-UNPAID', 'received', 5000.0, $this->d(10));
        $finalUnpaid = $this->purchase($vendor, 'invoice', 'FAK-UNPAID', 'received', 20000.0, $this->d(20));
        $this->repo->linkAdvance($finalUnpaid, $unpaid, $this->supplierId);
        self::assertEqualsWithDelta(
            0.0,
            (float) $this->repo->find($finalUnpaid, $this->supplierId)['advance_paid_amount'],
            0.01,
            'Neuhrazená záloha nesmí snížit závazek finální faktury.',
        );

        $partial = $this->purchase($vendor, 'advance', 'ZAL-PARTIAL', 'received', 5000.0, $this->d(12));
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET paid_amount_invoice_ccy = 1500 WHERE id = ?'
        )->execute([$partial]);
        $finalPartial = $this->purchase($vendor, 'invoice', 'FAK-PARTIAL', 'received', 20000.0, $this->d(22));
        $this->repo->linkAdvance($finalPartial, $partial, $this->supplierId);
        self::assertEqualsWithDelta(
            1500.0,
            (float) $this->repo->find($finalPartial, $this->supplierId)['advance_paid_amount'],
            0.01,
            'Částečně uhrazená záloha smí snížit závazek jen o skutečně uhrazenou částku.',
        );

        $paid = $this->purchase($vendor, 'advance', 'ZAL-PAID-CAP', 'paid', 30000.0, $this->d(11));
        $finalPaid = $this->purchase($vendor, 'invoice', 'FAK-PAID-CAP', 'received', 20000.0, $this->d(21));
        $this->repo->linkAdvance($finalPaid, $paid, $this->supplierId);
        $row = $this->repo->find($finalPaid, $this->supplierId);
        self::assertEqualsWithDelta(20000.0, (float) $row['advance_paid_amount'], 0.01);
        self::assertEqualsWithDelta(0.0, (float) $row['amount_to_pay'], 0.01);
    }

    public function testLinkRejectsCancelledOrDifferentCurrencyAdvance(): void
    {
        $vendor = $this->vendor('Dodavatel K', 'CZ10000011');
        $final = $this->purchase($vendor, 'invoice', 'FAK-GUARD', 'received', 10000.0, $this->d(20));
        $cancelled = $this->purchase($vendor, 'advance', 'ZAL-CANCELLED', 'cancelled', 2000.0, $this->d(10));
        try {
            $this->repo->linkAdvance($final, $cancelled, $this->supplierId);
            self::fail('Stornovanou zálohu nesmí jít propojit.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('stornovanou', $e->getMessage());
        }

        $foreignCurrency = $this->db->pdo()->query(
            "SELECT id FROM currencies WHERE id <> {$this->currencyId} ORDER BY id LIMIT 1"
        )->fetchColumn();
        if ($foreignCurrency === false) {
            $this->markTestSkipped('Chybí druhá měna pro validační test.');
        }
        $foreignAdvance = $this->purchase(
            $vendor,
            'advance',
            'ZAL-FX',
            'paid',
            2000.0,
            $this->d(11),
            (int) $foreignCurrency,
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/stejné měně/');
        $this->repo->linkAdvance($final, $foreignAdvance, $this->supplierId);
    }

    public function testFindByReferenceAndSuggest(): void
    {
        $vendor = $this->vendor('Dodavatel E', 'CZ10000005');
        $advance = $this->purchase($vendor, 'advance', 'ZAL 2099 007', 'received', 2000.0, $this->d(10));
        $final   = $this->purchase($vendor, 'invoice', 'FAK-5', 'received', 8000.0, $this->d(20));

        // Reference bez mezer musí najít zálohu (porovnání bez whitespace)
        $found = $this->repo->findAdvanceByReference($this->supplierId, $vendor, 'ZAL2099007');
        self::assertSame($advance, $found);

        // Uloží AI návrh (suggest & confirm) — vazba se neaplikuje
        $this->repo->suggestAdvanceLink($final, $advance, $this->supplierId);
        $finalRow = $this->repo->find($final, $this->supplierId);
        self::assertNull($finalRow['advance_purchase_invoice_id'], 'návrh neaplikuje vazbu');
        self::assertNotNull($finalRow['advance_link_suggestion']);
        self::assertSame($advance, $finalRow['advance_link_suggestion']['id']);
    }

    /**
     * Detail klienta (GetClientAction) — agregace nákladů `costs_by_year` NESMÍ
     * dvojitě započítat spárované/zaplacené zálohy (to byl bug v sumacích i grafech
     * u dodavatele). Accrual sémantika shodná s CRM (migrace 0065):
     *   - řádná faktura (received)               → náklad
     *   - spárovaná záloha                       → vyloučena (nese ji finální faktura)
     *   - zaplacená nespárovaná záloha           → vyloučena (prepayment)
     *   - nezaplacená nespárovaná záloha         → započítána (očekávaný náklad)
     */
    public function testClientDetailCostsExcludePairedAndPaidAdvance(): void
    {
        $vendor = $this->vendor('Dodavatel H', 'CZ10000008');
        $final  = $this->purchase($vendor, 'invoice', 'CD-FAK',     'received', 20000.0, $this->d(10));
        $paired = $this->purchase($vendor, 'advance', 'CD-ZAL-P',   'received',  5000.0, $this->d(9));
        $this->repo->linkAdvance($final, $paired, $this->supplierId);                       // → vyloučena
        $this->purchase($vendor, 'advance', 'CD-ZAL-PAID', 'paid',     7000.0, $this->d(8)); // → vyloučena
        $this->purchase($vendor, 'advance', 'CD-ZAL-OPEN', 'received', 3000.0, $this->d(7)); // → započítána

        $detail = $this->clientDetail($vendor);
        $czk = array_values(array_filter(
            $detail['costs_by_year'] ?? [],
            static fn ($r) => (int) $r['year'] === self::YEAR && $r['currency'] === 'CZK'
        ));
        self::assertCount(1, $czk, 'jeden CZK řádek nákladů pro rok 2099');
        self::assertEqualsWithDelta(23000.0, (float) $czk[0]['total'], 0.01,
            'náklady = řádná faktura 20000 + nezaplacená nespárovaná záloha 3000; '
            . 'spárovaná (5000) a zaplacená (7000) záloha musí být vyloučené');
        self::assertSame(2, (int) $czk[0]['count'], 'do počtu jdou jen 2 doklady (faktura + otevřená záloha)');
    }

    /**
     * Seznam přijatých faktur — měsíční mezisoučet v hlavičce (totals_per_currency)
     * NESMÍ dvojitě počítat spárované/zaplacené zálohy. Řádky se i tak všechny zobrazí.
     */
    public function testListMonthHeaderTotalsExcludeSettledAndPaidAdvance(): void
    {
        $vendor = $this->vendor('Dodavatel I', 'CZ10000009');
        $final  = $this->purchase($vendor, 'invoice', 'LH-FAK',     'received', 20000.0, $this->d(10));
        $paired = $this->purchase($vendor, 'advance', 'LH-ZAL-P',   'received',  5000.0, $this->d(9));
        $this->repo->linkAdvance($final, $paired, $this->supplierId);                       // → vyloučena
        $this->purchase($vendor, 'advance', 'LH-ZAL-PAID', 'paid',     7000.0, $this->d(8)); // → vyloučena
        $this->purchase($vendor, 'advance', 'LH-ZAL-OPEN', 'received', 3000.0, $this->d(7)); // → započítána

        $res = $this->repo->listGroupedByMonth(
            ['supplier_id' => $this->supplierId, 'vendor_id' => $vendor, 'year' => self::YEAR]
        );
        $group = null;
        foreach ($res['data'] as $g) {
            if ($g['month'] === sprintf('%04d-06', self::YEAR)) { $group = $g; break; }
        }
        self::assertNotNull($group, 'měsíční skupina 2099-06 existuje');
        self::assertSame(4, $group['count'], 'všechny 4 doklady jsou v seznamu zobrazené');

        $czk = null;
        foreach ($group['totals_per_currency'] as $tc) {
            if ($tc['currency'] === 'CZK') { $czk = $tc; break; }
        }
        self::assertNotNull($czk, 'CZK mezisoučet existuje');
        self::assertEqualsWithDelta(23000.0, (float) $czk['with_vat'], 0.01,
            'mezisoučet = faktura 20000 + otevřená záloha 3000; spárovaná (5000) a zaplacená (7000) vyloučeny');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Zavolá GetClientAction a vrátí dekódované tělo (Json::ok zapisuje data napřímo). */
    private function clientDetail(int $clientId): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/clients/' . $clientId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
        $resp = ($this->getClientAction)($req, new Psr7Response(), ['id' => (string) $clientId]);
        $resp->getBody()->rewind();
        return json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function d(int $day): string
    {
        return sprintf('%04d-06-%02d', self::YEAR, $day);
    }

    private function vendor(string $name, string $dic): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "v@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->vendorIds[] = $id;
        return $id;
    }

    /**
     * Konečnou fakturu lze vyúčtovat i SAMOSTATNÝM daňovým dokladem k platbě.
     *
     * U nákupu placeného kartou žádná zálohová faktura nevzniká — prodejce vystaví rovnou
     * „daňový doklad ke dni přijaté úplaty" (§ 28/8 ZDPH) a fakturu na zboží pošle až
     * s dodáním. Dokud šlo propojit jen `advance`, neměl uživatel konečnou fakturu čím
     * spárovat: 314 zůstalo nevypořádané a nic nedrželo informaci, že DPH už byla uplatněna.
     */
    public function testStandaloneTaxDocumentCanSettleFinalInvoice(): void
    {
        $vendor = $this->vendor('Dodavatel Karta', 'CZ10000021');
        // DDKP BEZ vazby na zálohovou fakturu = doklad o platbě kartou.
        $ddkp  = $this->purchase($vendor, 'tax_document', 'DDKP-SOLO', 'received', 36863.01, $this->d(10));
        $final = $this->purchase($vendor, 'invoice', 'FAK-SOLO', 'received', 36863.01, $this->d(20));

        $this->repo->linkAdvance($final, $ddkp, $this->supplierId);

        self::assertSame($ddkp, (int) $this->repo->find($final, $this->supplierId)['advance_purchase_invoice_id']);
        // A na samotném DDKP musí být vidět, kdo ho vyúčtoval.
        self::assertSame($final, (int) ($this->repo->find($ddkp, $this->supplierId)['settled_by']['id'] ?? 0));
    }

    /** Nabídne se i v kandidátech — jinak by ho uživatel musel hledat ručně. */
    public function testStandaloneTaxDocumentAppearsAmongCandidates(): void
    {
        $vendor = $this->vendor('Dodavatel Karta2', 'CZ10000022');
        $ddkp  = $this->purchase($vendor, 'tax_document', 'DDKP-SOLO2', 'received', 5000.0, $this->d(10));
        $final = $this->purchase($vendor, 'invoice', 'FAK-SOLO2', 'received', 5000.0, $this->d(20));

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->repo->advanceCandidates($final, $this->supplierId));

        self::assertContains($ddkp, $ids);
    }

    /**
     * DDKP navázaný na zálohovou fakturu se přímo propojit NESMÍ.
     *
     * Vyúčtuje se přes tu zálohu; kdyby šel i napřímo, dala by se tatáž záloha vyúčtovat
     * dvakrát — jednou přes zálohovou fakturu a jednou přes její DDKP.
     */
    public function testTaxDocumentBelongingToAnAdvanceCannotBeLinkedDirectly(): void
    {
        $vendor  = $this->vendor('Dodavatel Karta3', 'CZ10000023');
        $advance = $this->purchase($vendor, 'advance', 'ZAL-SOLO3', 'received', 12100.0, $this->d(5));
        $ddkp    = $this->purchase($vendor, 'tax_document', 'DDKP-CHILD', 'received', 2100.0, $this->d(6));
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ?')
            ->execute([$advance, $ddkp]);
        $final = $this->purchase($vendor, 'invoice', 'FAK-SOLO3', 'received', 12100.0, $this->d(20));

        $this->expectException(\Throwable::class);
        $this->repo->linkAdvance($final, $ddkp, $this->supplierId);
    }

    /**
     * Konečná faktura navázaná na samostatný DDKP musí varovat před dvojím odpočtem.
     *
     * DDKP odpočet uplatňuje SÁM (343/314), takže vyúčtovací faktura smí nárokovat jen
     * doplatkovou daň. Varování `advance_has_tax_document` se dosud hledalo jen jako DÍTĚ
     * zálohy — u samostatného DDKP tedy nikdy nepřišlo.
     */
    public function testStandaloneTaxDocumentTriggersDoubleDeductionWarning(): void
    {
        $vendor = $this->vendor('Dodavatel Karta4', 'CZ10000024');
        $ddkp  = $this->purchase($vendor, 'tax_document', 'DDKP-SOLO4', 'received', 36863.01, $this->d(10));
        $final = $this->purchase($vendor, 'invoice', 'FAK-SOLO4', 'received', 36863.01, $this->d(20));

        $body = $this->linkAdvance($final, $ddkp);

        self::assertArrayHasKey('_warnings', $body, 'Uživatel musí vědět, že DPH už je uplatněná.');
    }

    /** Vloží přijatou fakturu (vat=0 → without==with, ať je daň z příjmů deterministická). */
    private function purchase(
        int $vendorId,
        string $kind,
        string $number,
        string $status,
        float $total,
        string $date,
        ?int $currencyId = null,
    ): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, is_fixed_asset,
                 vat_deduction, vat_deduction_percent, tax_deductible, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, "{}", ?, 0, ?, ?, 0, "full", 100, 1, ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $kind, $date, $date, $date, $date,
            $currencyId ?? $this->currencyId, $total, $total, $status, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->piIds[] = $id;
        return $id;
    }
}
