<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Report\VatLedgerService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * issue #9 — období odpočtu se nesmí lišit podle toho, jestli uživatel doklad po AI
 * vytěžení otevřel a uložil.
 *
 * `received_at_source='manual'` znamená „účetní datum přijetí vědomě zadala" a jen v tom
 * případě smí datum přijetí posunout období odpočtu (§ 73 odst. 1 písm. a ZDPH,
 * {@see VatLedgerService::purchaseClaimDateExpr()}). Editor ale posílá `received_at`
 * v KAŽDÉM uložení, takže původní podmínka `array_key_exists('received_at', $body)`
 * překlopila na 'manual' i pouhé přeuložení beze změny pole — a otisk dne, kdy doklad
 * vytěžila AI, tím tiše převzal řízení období DPH. Dva doklady se stejnými daty tak
 * skončily v různých měsících podle toho, který z nich někdo otevřel.
 *
 * Data jsou syntetická (rok 2096), vše v transakci s rollbackem.
 */
#[Group('integration')]
final class ReceivedAtSourceOnUpdateTest extends TestCase
{
    /** Doklad s DUZP na konci června, ale vystavený až v červenci — vzor z hlášení. */
    private const TAX_DATE   = '2096-06-30';
    private const ISSUE_DATE = '2096-07-02';
    private const DUE_DATE   = '2096-07-02';
    /** Den, kdy doklad vytěžila AI — otisk importu, ne skutečné převzetí dokladu. */
    private const SCAN_DATE  = '2096-08-10';

    private Connection $db;
    private CreatePurchaseInvoiceAction $create;
    private UpdatePurchaseInvoiceAction $update;
    private VatLedgerService $ledger;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db     = $c->get(Connection::class);
            $this->create = $c->get(CreatePurchaseInvoiceAction::class);
            $this->update = $c->get(UpdatePurchaseInvoiceAction::class);
            $this->ledger = $c->get(VatLedgerService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates WHERE rate_percent > 0 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId             = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $this->currencyId = (int) ($pdo->query(
            "SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND is_active = 1
              ORDER BY (code = 'CZK') DESC, is_default DESC, id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->currencyId === 0) {
            $this->markTestSkipped('Dodavatel nemá aktivní měnu.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        // Dodavatel BEZ DIČ — CreatePurchaseInvoiceAction jinak sahá na CRPDPH (síť).
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_vendor, is_vat_payer)
             VALUES (?, "TEST received_at_source dodavatel (PHPUnit)", "Testovaci 1", "Praha", "11000", ?,
                     "received-at-vendor@example.test", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * BEZ OPRAVY PADÁ. Přeuložení AI vytěženého dokladu, u kterého uživatel na datum
     * přijetí vůbec nesáhl, ho označilo za ručně zadané a odsunulo odpočet do měsíce
     * skenování — zatímco jeho neotevřené dvojče zůstalo v červenci.
     */
    public function testResaveWithUnchangedReceivedAtKeepsImportSourceAndPeriod(): void
    {
        $id = $this->createExtractedInvoice();

        self::assertSame('import', $this->receivedAtSource($id));
        self::assertSame(self::ISSUE_DATE, $this->claimDate($id),
            'Výchozí stav: odpočet dle GREATEST(DUZP, vystavení) = datum vystavení.');

        // Uživatel doklad otevřel, opravil částku/popis a uložil — datum přijetí nechal být.
        $body = $this->payload();
        $body['note_above_items'] = 'Ruční úprava po vytěžení (PHPUnit).';
        $res = $this->put($id, $body);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        self::assertSame('import', $this->receivedAtSource($id),
            'Přeuložení beze změny data přijetí není vědomé zadání účetní.');
        self::assertSame(self::ISSUE_DATE, $this->claimDate($id),
            'Ručně upravený doklad musí spadnout do STEJNÉHO období jako neupravený.');
    }

    /** Skutečná změna data přijetí je vědomý úkon → 'manual' a období se řídí jím (§ 73/1/a). */
    public function testChangingReceivedAtMarksItManualAndMovesPeriod(): void
    {
        $id = $this->createExtractedInvoice();

        $body = $this->payload();
        $body['received_at'] = '2096-08-20';
        $res = $this->put($id, $body);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        self::assertSame('manual', $this->receivedAtSource($id));
        self::assertSame('2096-08-20', $this->claimDate($id),
            'Vědomě zadané datum přijetí je pozdější než DUZP i vystavení → rozhoduje ono.');
    }

    /**
     * Opačný směr: jednou vědomě zadané datum přijetí se přeuložením formuláře nesmí
     * degradovat zpět na otisk importu — jinak by odpočet uskočil do dřívějšího období.
     */
    public function testResaveDoesNotDowngradeManualSource(): void
    {
        $id = $this->createExtractedInvoice();

        $first = $this->payload();
        $first['received_at'] = '2096-08-20';
        self::assertSame(200, $this->put($id, $first)['status']);
        self::assertSame('manual', $this->receivedAtSource($id));

        $second = $this->payload();
        $second['received_at'] = '2096-08-20';
        $second['note_above_items'] = 'Druhá úprava beze změny data (PHPUnit).';
        self::assertSame(200, $this->put($id, $second)['status']);

        self::assertSame('manual', $this->receivedAtSource($id), 'Vědomá volba se nesmí ztratit.');
        self::assertSame('2096-08-20', $this->claimDate($id));
    }

    /**
     * Datum přijetí DŘÍVE než vystavení (druhé pozorování z hlášení): odpočet zůstává
     * u data vystavení. Doklad nelze mít k dispozici dřív, než vůbec vznikl, takže
     * § 73/1/a ho do měsíce DUZP nepustí — a `claim_basis` to musí umět říct.
     */
    public function testReceivedAtBeforeIssueDateFallsBackToIssueDate(): void
    {
        $id = $this->createExtractedInvoice();

        $body = $this->payload();
        $body['received_at'] = self::TAX_DATE;   // uživatel si ho posunul na DUZP
        self::assertSame(200, $this->put($id, $body)['status']);

        self::assertSame('manual', $this->receivedAtSource($id));
        self::assertSame(self::ISSUE_DATE, $this->claimDate($id),
            'Datum přijetí před vystavením nemůže odpočet stáhnout do měsíce DUZP.');
        self::assertSame('issue_date', $this->claimBasis($id),
            'Uživatel musí dostat vysvětlení, že rozhoduje datum vystavení.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Doklad ve stavu „právě vytěžený AI": datum přijetí = den skenování, zdroj 'import'.
     * Vytváří se přes akci (aby prošel běžnou cestou) a stav importu se dorovná v DB,
     * protože ruční POST z formuláře je z definice 'manual'.
     */
    private function createExtractedInvoice(): int
    {
        $created = self::decode(($this->create)($this->request('POST', $this->payload()), new Psr7Response()));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));
        $id = (int) $created['body']['id'];

        $this->db->pdo()
            ->prepare("UPDATE purchase_invoices SET received_at = ?, received_at_source = 'import' WHERE id = ?")
            ->execute([self::SCAN_DATE, $id]);

        return $id;
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => 'RECV-' . bin2hex(random_bytes(3)),
            'document_kind'         => 'invoice',
            'issue_date'            => self::ISSUE_DATE,
            'tax_date'              => self::TAX_DATE,
            'due_date'              => self::DUE_DATE,
            'received_at'           => self::SCAN_DATE,
            'currency_id'           => $this->currencyId,
            'reverse_charge'        => false,
            'prices_include_vat'    => false,
            'items'                 => [[
                'description'            => 'Dodávka (PHPUnit)',
                'quantity'               => 1,
                'unit'                   => 'ks',
                'unit_price_without_vat' => 1000.0,
                'vat_rate_id'            => $this->vatRateId,
            ]],
        ];
    }

    private function receivedAtSource(int $id): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT received_at_source FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);

        return (string) $stmt->fetchColumn();
    }

    /** Období odpočtu tak, jak ho vidí sdílený výraz VatLedgerService (SSOT). */
    private function claimDate(int $id): ?string
    {
        return $this->ledger->purchaseClaimInfo($this->supplierId, [$id])[$id]['claim_date'] ?? null;
    }

    /** Důvod zařazení z kanonického řádku (drafty včetně — doklad z akce je 'draft'). */
    private function claimBasis(int $id): ?string
    {
        foreach ($this->ledger->rows($this->supplierId, '2096-01-01', '2096-12-31', includeDrafts: true) as $r) {
            if (($r['source'] ?? '') === 'purchase' && (int) $r['invoice_id'] === $id) {
                return $r['claim_basis'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function put(int $id, array $body): array
    {
        return self::decode(
            ($this->update)($this->request('PUT', $body), new Psr7Response(), ['id' => (string) $id])
        );
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/purchase-invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
