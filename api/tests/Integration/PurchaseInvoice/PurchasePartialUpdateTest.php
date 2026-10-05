<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Action\PurchaseInvoice\PaymentQrAction;
use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceExchangeRateAction;
use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceExpenseKindsAction;
use MyInvoice\Action\PurchaseInvoice\SetPurchaseInvoiceProjectAction;
use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\SupplierPaymentQrSettingsRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Service\Import\LlmGatewayInterface;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payment\BankAccountParser;
use MyInvoice\Service\Pdf\PdfImageExtractor;
use MyInvoice\Service\Qr\QrPaymentGenerator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Issue #113 — update endpointy přijaté faktury: vynechaný klíč = ponech uloženou
 * hodnotu, explicitní null = vymaž. Dřív částečné tělo tiše nulovalo DUZP, datum
 * přijetí, režim odpočtu, platební účet, kurz i zakázku.
 *
 * Vše v transakci s rollbackem; data jsou syntetická.
 */
#[Group('integration')]
final class PurchasePartialUpdateTest extends TestCase
{
    private const ISSUE_DATE = '2096-04-10';

    private ContainerInterface $c;
    private Connection $db;
    private CreatePurchaseInvoiceAction $create;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private bool $inTx = false;
    private ?string $tmpDir = null;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->c      = Bootstrap::buildContainer();
            $this->db     = $this->c->get(Connection::class);
            $this->create = $this->c->get(CreatePurchaseInvoiceAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
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

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_vendor, is_vat_payer)
             VALUES (?, "TEST partial update dodavatel (PHPUnit)", "Testovaci 1", "Praha", "11000", ?,
                     "partial-update-vendor@example.test", "cs", ?, 1, 1)'
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
        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
    }

    // ── PUT /purchase-invoices/{id} ─────────────────────────────────────────

    public function testPutWithPartialBodyKeepsStoredHeader(): void
    {
        $this->assertPartialPutKeepsHeader([]);
    }

    /** I tělo s dřív povinnými klíči nesmí vynulovat zbytek hlavičky. */
    public function testPutWithRequiredKeysOnlyKeepsStoredHeader(): void
    {
        $this->assertPartialPutKeepsHeader(['issue_date' => self::ISSUE_DATE, 'due_date' => '2096-05-10']);
    }

    /** @param array<string,mixed> $extra */
    private function assertPartialPutKeepsHeader(array $extra): void
    {
        $id = $this->createInvoice();
        $this->seedHeader($id);
        $before = $this->row($id);
        if ($extra !== []) {
            $extra += ['vendor_id' => $this->vendorId, 'vendor_invoice_number' => $before['vendor_invoice_number']];
        }

        $res = $this->call(UpdatePurchaseInvoiceAction::class, 'PUT', $id, $extra + ['note_below_items' => 'Jen spodní poznámka']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        $after = $this->row($id);
        self::assertSame('Jen spodní poznámka', $after['note_below_items']);
        foreach ([
            'vendor_id', 'vendor_invoice_number', 'issue_date', 'due_date', 'currency_id',
            'tax_date', 'delivery_date', 'received_at', 'prices_include_vat', 'language',
            'note_above_items', 'advance_paid_amount', 'vat_deduction', 'vat_deduction_percent',
            'tax_deductible', 'reverse_charge', 'is_fixed_asset', 'expense_category_id',
            'payment_currency_id', 'payment_exchange_rate', 'paid_amount_payment_ccy',
            'paid_amount_invoice_ccy', 'exchange_diff_base', 'vat_classification_code',
            'exchange_rate', 'exchange_rate_date', 'exchange_rate_source',
        ] as $col) {
            self::assertSame($before[$col], $after[$col], "Vynechaný klíč `{$col}` se nesmí přepsat.");
        }
        // Přepočet běží nad sloučeným stavem: cena 1000 je s DPH (prices_include_vat
        // přežil), ne netto, ze kterého by vyšlo 1210.
        self::assertSame('1000.00', $after['total_with_vat']);
    }

    public function testPutExplicitNullClearsNullableHeader(): void
    {
        $id = $this->createInvoice();
        $this->seedHeader($id);

        $res = $this->call(UpdatePurchaseInvoiceAction::class, 'PUT', $id, [
            'delivery_date' => null, 'note_above_items' => null,
        ]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        $after = $this->row($id);
        self::assertNull($after['delivery_date']);
        self::assertNull($after['note_above_items']);
        self::assertSame('2096-04-05', $after['tax_date'], 'Nezmíněné DUZP zůstává.');
    }

    // ── PUT /purchase-invoices/{id}/payment-account ─────────────────────────

    public function testPaymentAccountPartialBodyKeepsStoredValues(): void
    {
        $id = $this->createInvoice();
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET payment_account_number = '1000000005', payment_bank_code = '0100',
                    payment_iban = 'CZ0001000000001000000005', payment_bic = 'KOMBCZPP', payment_variable_symbol = '777'
              WHERE id = ?"
        )->execute([$id]);

        $res = $this->callQr('updateAccount', 'PUT', $id, ['variable_symbol' => '888']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $row = $this->row($id);
        self::assertSame(
            ['1000000005', '0100', 'CZ0001000000001000000005', 'KOMBCZPP', '888'],
            [$row['payment_account_number'], $row['payment_bank_code'], $row['payment_iban'], $row['payment_bic'], $row['payment_variable_symbol']],
        );

        $res = $this->callQr('updateAccount', 'PUT', $id, ['bic' => null]);
        self::assertSame(200, $res['status']);
        $row = $this->row($id);
        self::assertNull($row['payment_bic'], 'Explicitní null maže.');
        self::assertSame('1000000005', $row['payment_account_number']);
        self::assertSame('888', $row['payment_variable_symbol']);
    }

    // ── POST /purchase-invoices/{id}/payment-qr/extract-account ─────────────

    public function testExtractAccountWithoutResultKeepsPartialStoredValues(): void
    {
        $id = $this->createInvoice();
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pi-partial-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
        file_put_contents($this->tmpDir . DIRECTORY_SEPARATOR . 'doc.pdf', "%PDF-1.4\n% synteticky dokument bez ISDOC\n%%EOF\n");
        // Neúplný účet (číslo bez kódu banky) = „účet chybí", lazy extrakce se spustí.
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET pdf_path = 'doc.pdf', payment_account_number = '1000000005',
                    payment_bank_code = NULL, payment_iban = NULL, payment_variable_symbol = '777',
                    payment_account_checked_at = NULL
              WHERE id = ?"
        )->execute([$id]);

        $res = $this->callQr('extractAccount', 'POST', $id, []);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        $row = $this->row($id);
        self::assertNotNull($row['payment_account_checked_at'], 'Neúspěšná extrakce se poznamená.');
        self::assertSame('1000000005', $row['payment_account_number'], 'Neúspěch extrakce nesmí mazat uložené údaje.');
        self::assertSame('777', $row['payment_variable_symbol']);
    }

    /** Nový účet z vytěžení nepřebírá BIC uložený k jinému (rozpracovanému) účtu. */
    public function testExtractAccountNewAccountDoesNotInheritStoredBic(): void
    {
        $id = $this->createInvoice();
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pi-partial-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
        file_put_contents($this->tmpDir . DIRECTORY_SEPARATOR . 'doc.pdf', "%PDF-1.4\n% synteticky dokument bez ISDOC\n%%EOF\n");
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET pdf_path = 'doc.pdf', payment_account_number = '19',
                    payment_bank_code = NULL, payment_iban = NULL, payment_bic = 'GIBACZPX',
                    payment_variable_symbol = '777', payment_account_checked_at = NULL
              WHERE id = ?"
        )->execute([$id]);

        $res = $this->callQr('extractAccount', 'POST', $id, [], [
            'ok' => true, 'bank_account' => '1000000005/0100', 'iban' => null, 'variable_symbol' => null,
        ]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        $row = $this->row($id);
        self::assertSame('1000000005', ltrim((string) $row['payment_account_number'], '0'));
        self::assertSame('0100', $row['payment_bank_code']);
        self::assertNull($row['payment_bic'], 'BIC jiného účtu se k nově nalezenému nepřenáší.');
        self::assertSame('777', $row['payment_variable_symbol'], 'Nenalezený VS zůstává uložený.');
    }

    // ── POST /purchase-invoices/{id}/exchange-rate ──────────────────────────

    public function testExchangeRatePartialBodyKeepsStoredRate(): void
    {
        $id = $this->createInvoice();
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET exchange_rate = 25.125, exchange_rate_date = '2096-04-09', exchange_rate_source = 'cnb'
              WHERE id = ?"
        )->execute([$id]);

        $res = $this->call(SetPurchaseInvoiceExchangeRateAction::class, 'POST', $id, ['source' => 'user']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $row = $this->row($id);
        self::assertEqualsWithDelta(25.125, (float) $row['exchange_rate'], 1e-9);
        self::assertSame('2096-04-09', $row['exchange_rate_date']);
        self::assertSame('user', $row['exchange_rate_source']);

        $res = $this->call(SetPurchaseInvoiceExchangeRateAction::class, 'POST', $id, ['rate' => null]);
        self::assertSame(200, $res['status']);
        $row = $this->row($id);
        self::assertNull($row['exchange_rate'], 'Explicitní null kurz resetuje.');
        self::assertNull($row['exchange_rate_date'], 'Smazaný kurz nenechá viset své datum.');
        self::assertSame('manual', $row['exchange_rate_source']);
    }

    /** Nový kurz bez data a zdroje nedědí datum a zdroj ('cnb') starého kurzu. */
    public function testExchangeRateWithoutSourceDoesNotInheritOldSource(): void
    {
        $id = $this->createInvoice();
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET exchange_rate = 25.125, exchange_rate_date = '2096-04-09', exchange_rate_source = 'cnb'
              WHERE id = ?"
        )->execute([$id]);

        $res = $this->call(SetPurchaseInvoiceExchangeRateAction::class, 'POST', $id, ['rate' => 26]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $row = $this->row($id);
        self::assertEqualsWithDelta(26.0, (float) $row['exchange_rate'], 1e-9);
        self::assertNull($row['exchange_rate_date']);
        self::assertSame('manual', $row['exchange_rate_source']);
    }

    // ── PUT /purchase-invoices/{id} — odvozené větve ────────────────────────

    /** Cizí měna: částečný PUT nesmí kurz přenačíst ani změnit. */
    public function testPutForeignCurrencyKeepsRate(): void
    {
        $foreignId = (int) ($this->db->pdo()->query(
            "SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND is_active = 1 AND code <> 'CZK' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($foreignId === 0) {
            self::markTestSkipped('Dodavatel nemá aktivní cizí měnu.');
        }
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate)
             SELECT ?, code, ? FROM currencies WHERE id = ?
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([self::ISSUE_DATE, 27.0, $foreignId]);
        $id = $this->createInvoice();
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET currency_id = ?, exchange_rate = 25.125, exchange_rate_date = '2096-04-09',
                    exchange_rate_source = 'cnb'
              WHERE id = ?"
        )->execute([$foreignId, $id]);

        $res = $this->call(UpdatePurchaseInvoiceAction::class, 'PUT', $id, ['note_below_items' => 'Poznámka']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $row = $this->row($id);
        self::assertSame($foreignId, (int) $row['currency_id']);
        self::assertEqualsWithDelta(25.125, (float) $row['exchange_rate'], 1e-9);
        self::assertSame('2096-04-09', $row['exchange_rate_date']);
        self::assertSame('cnb', $row['exchange_rate_source']);
    }

    /** Dobropis: vazba na fakturu bez document_kind v těle se nezahodí. */
    public function testPutCreditNoteParentLinkWithoutDocumentKind(): void
    {
        $parentId = $this->createInvoice();
        $id = $this->createInvoice();
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET document_kind = 'credit_note' WHERE id = ?")->execute([$id]);

        $res = $this->call(UpdatePurchaseInvoiceAction::class, 'PUT', $id, ['parent_purchase_invoice_id' => $parentId]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame($parentId, (int) $this->row($id)['parent_purchase_invoice_id']);
    }

    /** Neplátce, týž dodavatel, vat_deduction vynechán → uložený režim odpočtu zůstává. */
    public function testPutNonPayerSameVendorKeepsVatDeduction(): void
    {
        $id = $this->createInvoice();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE clients SET is_vat_payer = 0 WHERE id = ?')->execute([$this->vendorId]);
        $pdo->prepare("UPDATE purchase_invoices SET vendor_is_vat_payer = 0, vat_deduction = 'full' WHERE id = ?")->execute([$id]);

        $res = $this->call(UpdatePurchaseInvoiceAction::class, 'PUT', $id, ['note_below_items' => 'Poznámka']);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('full', $this->row($id)['vat_deduction']);
    }

    /** Změna dodavatele na neplátce bez poslaného vat_deduction → 'none'. */
    public function testPutVendorChangeToNonPayerSetsNoDeduction(): void
    {
        $id = $this->createInvoice();
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_vendor, is_vat_payer)
             SELECT supplier_id, "TEST partial update neplátce (PHPUnit)", street, city, zip, country_id,
                    "partial-update-nonpayer@example.test", language, currency_default_id, 1, 0
               FROM clients WHERE id = ?'
        )->execute([$this->vendorId]);
        $nonPayerId = (int) $pdo->lastInsertId();
        self::assertSame('full', $this->row($id)['vat_deduction']);

        $res = $this->call(UpdatePurchaseInvoiceAction::class, 'PUT', $id, ['vendor_id' => $nonPayerId]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $row = $this->row($id);
        self::assertSame($nonPayerId, (int) $row['vendor_id']);
        self::assertSame('none', $row['vat_deduction']);
    }

    /** Nové položky bez hlavičkového kódu → hlavička se odvodí z řádků, nezůstane stará. */
    public function testPutItemsWithoutHeaderCodeRederivesClassification(): void
    {
        $id = $this->createInvoice();
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET vat_classification_code = '24e' WHERE id = ?")->execute([$id]);

        $res = $this->call(UpdatePurchaseInvoiceAction::class, 'PUT', $id, ['items' => [[
            'description' => 'Konzultace 2 (PHPUnit)', 'quantity' => 2, 'unit' => 'ks',
            'unit_price_without_vat' => 500.0, 'vat_rate_id' => $this->vatRateId,
        ]]]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $itemCode = $this->db->pdo()->query(
            "SELECT vat_classification_code FROM purchase_invoice_items WHERE purchase_invoice_id = {$id} LIMIT 1"
        )->fetchColumn();
        $itemCode = $itemCode === false || $itemCode === '' ? null : $itemCode;
        self::assertNotSame('24e', $itemCode, 'Předpoklad testu: tuzemský řádek nemá kód 24e.');
        self::assertSame($itemCode, $this->row($id)['vat_classification_code']);
    }

    // ── POST /purchase-invoices/{id}/project ────────────────────────────────

    public function testProjectRequiresKeyAndNullUnassigns(): void
    {
        $id = $this->createInvoice();
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO projects (client_id, name, currency_id) VALUES (?, 'Zakázka (PHPUnit)', ?)")
            ->execute([$this->vendorId, $this->currencyId]);
        $projectId = (int) $pdo->lastInsertId();

        $res = $this->call(SetPurchaseInvoiceProjectAction::class, 'POST', $id, ['project_id' => $projectId]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        $res = $this->call(SetPurchaseInvoiceProjectAction::class, 'POST', $id, []);
        self::assertSame(400, $res['status'], 'Bez klíče project_id se zakázka nesmí tiše odebrat.');
        self::assertSame('validation_failed', $res['body']['error']['code'] ?? null);
        self::assertSame($projectId, (int) $this->row($id)['project_id']);

        $res = $this->call(SetPurchaseInvoiceProjectAction::class, 'POST', $id, ['project_id' => null]);
        self::assertSame(200, $res['status']);
        self::assertNull($this->row($id)['project_id']);
    }

    // ── PUT /purchase-invoices/{id}/expense-kinds ───────────────────────────

    public function testExpenseKindsItemWithoutKindIsRejected(): void
    {
        $id = $this->createInvoice();
        $itemId = (int) $this->db->pdo()->query(
            "SELECT id FROM purchase_invoice_items WHERE purchase_invoice_id = {$id} ORDER BY order_index, id LIMIT 1"
        )->fetchColumn();

        $res = $this->call(SetPurchaseInvoiceExpenseKindsAction::class, 'PUT', $id, ['items' => [['id' => $itemId, 'expense_kind' => 'service']]]);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        $res = $this->call(SetPurchaseInvoiceExpenseKindsAction::class, 'PUT', $id, ['items' => [['id' => $itemId]]]);
        self::assertSame(400, $res['status'], 'Položka bez expense_kind nesmí druh tiše smazat.');
        $kind = $this->db->pdo()->query("SELECT expense_kind FROM purchase_invoice_items WHERE id = {$itemId}")->fetchColumn();
        self::assertSame('service', $kind);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seedHeader(int $id): void
    {
        $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET tax_date = '2096-04-05', delivery_date = '2096-04-03', received_at = '2096-04-12',
                    prices_include_vat = 1, language = 'en', note_above_items = 'Horní poznámka',
                    advance_paid_amount = 100, vat_deduction = 'proportional', vat_deduction_percent = 60,
                    tax_deductible = 0, is_fixed_asset = 1, vat_classification_code = '40'
              WHERE id = ?"
        )->execute([$id]);
    }

    private function createInvoice(): int
    {
        $created = self::decode(($this->create)($this->request('POST', [
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => 'PARTUPD-' . bin2hex(random_bytes(3)),
            'document_kind'         => 'invoice',
            'issue_date'            => self::ISSUE_DATE,
            'tax_date'              => self::ISSUE_DATE,
            'due_date'              => '2096-05-10',
            'received_at'           => self::ISSUE_DATE,
            'currency_id'           => $this->currencyId,
            'reverse_charge'        => false,
            'prices_include_vat'    => false,
            'items'                 => [[
                'description' => 'Konzultace (PHPUnit)', 'quantity' => 1, 'unit' => 'ks',
                'unit_price_without_vat' => 1000.0, 'vat_rate_id' => $this->vatRateId,
            ]],
        ]), new Psr7Response()));
        self::assertSame(201, $created['status'], json_encode($created['body'], JSON_UNESCAPED_UNICODE));

        return (int) $created['body']['id'];
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);

        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * @param  class-string $action
     * @param  array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $action, string $method, int $id, array $body): array
    {
        $handler = $this->c->get($action);

        return self::decode($handler($this->request($method, $body), new Psr7Response(), ['id' => (string) $id]));
    }

    /**
     * PaymentQrAction s vlastním archivem a AI, která nic nenajde — test nesmí volat placené API.
     *
     * @param  array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function callQr(string $method, string $httpMethod, int $id, array $body, array $llmResult = ['ok' => false]): array
    {
        $llm = $this->createStub(LlmGatewayInterface::class);
        $llm->method('extractPaymentAccount')->willReturn($llmResult);
        $action = new PaymentQrAction(
            $this->c->get(PurchaseInvoiceRepository::class),
            $this->c->get(QrPaymentGenerator::class),
            $this->c->get(BankAccountParser::class),
            new Config(['purchase_invoice' => ['archive_storage' => (string) $this->tmpDir]]),
            $this->c->get(PdfIsdocExtractor::class),
            $this->c->get(IsdocParser::class),
            $llm,
            $this->c->get(PdfImageExtractor::class),
            $this->c->get(DocumentLockService::class),
            $this->c->get(ActivityLogger::class),
            $this->c->get(IpMatcher::class),
            $this->c->get(SupplierPaymentQrSettingsRepository::class),
        );

        return self::decode($action->{$method}($this->request($httpMethod, $body), new Psr7Response(), ['id' => (string) $id]));
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
