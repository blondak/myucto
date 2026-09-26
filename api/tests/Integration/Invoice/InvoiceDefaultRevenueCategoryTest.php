<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Výchozí kategorie tržby se musí aplikovat při zakládání vydané faktury přes
 * InvoiceRepository::createDraft(). Symetrie k PurchaseDefaultExpenseCategoryTest.
 *
 * Pravidla (PŘEDNOST: explicitní volba > zakázka > klient):
 *   - payload bez revenue_category_id + klient má default     → klientův default
 *   - payload s explicitním revenue_category_id               → explicitní vyhrává
 *   - klient bez defaultu + payload bez kategorie             → NULL
 *   - zakázka má default                                      → přednost před klientem
 *
 * Soft-skip pokud chybí cfg.php (CI runner bez DB). Vše uklizeno v tearDown.
 */
#[Group('integration')]
final class InvoiceDefaultRevenueCategoryTest extends TestCase
{
    private Connection $db;
    private InvoiceRepository $repo;
    private FinalFromProformaCreator $proformaCreator;
    private RecurringInvoiceGenerator $generator;
    private RecurringTemplateRepository $templates;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $vatRateId = 0;

    /** @var int[] */
    private array $catIds = [];
    /** @var int[] */
    private array $clientIds = [];
    /** @var int[] */
    private array $projectIds = [];
    /** @var int[] */
    private array $invoiceIds = [];
    /** @var int[] */
    private array $templateIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container  = Bootstrap::buildContainer();
            $this->db   = $container->get(Connection::class);
            $this->repo = $container->get(InvoiceRepository::class);
            $this->proformaCreator = $container->get(FinalFromProformaCreator::class);
            $this->generator = $container->get(RecurringInvoiceGenerator::class);
            $this->templates = $container->get(RecurringTemplateRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        // Platná, nereverzní sazba DPH k DUZP (recurring generate ji vyžaduje).
        $this->vatRateId  = (int) ($pdo->query("SELECT id FROM vat_rates WHERE is_reverse_charge = 0 AND (valid_to IS NULL OR valid_to >= CURDATE()) ORDER BY is_default DESC, rate_percent DESC LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->templateIds as $id) {
            $pdo->prepare('DELETE FROM recurring_invoice_template_items WHERE template_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM recurring_invoice_templates WHERE id = ?')->execute([$id]);
        }
        foreach ($this->invoiceIds as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->projectIds as $id) {
            $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
        }
        foreach ($this->clientIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        foreach ($this->catIds as $id) {
            $pdo->prepare('DELETE FROM revenue_categories WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testCustomerDefaultAppliedWhenPayloadHasNoCategory(): void
    {
        $catId  = $this->category('TST-RDEF-A', 'Test revenue default A');
        $client = $this->customer('Zákazník s defaultem', 'CZ30000001', $catId);

        $id = $this->repo->createDraft($this->payload($client), $this->userId);
        $this->invoiceIds[] = $id;

        self::assertSame($catId, $this->storedCategory($id),
            'createDraft bez revenue_category_id musí doplnit výchozí kategorii zákazníka');
    }

    public function testExplicitCategoryWinsOverCustomerDefault(): void
    {
        $defaultCat  = $this->category('TST-RDEF-B', 'Test revenue default B');
        $explicitCat = $this->category('TST-REXP-B', 'Test revenue explicit B');
        $client      = $this->customer('Zákazník s defaultem 2', 'CZ30000002', $defaultCat);

        $payload = $this->payload($client);
        $payload['revenue_category_id'] = $explicitCat;

        $id = $this->repo->createDraft($payload, $this->userId);
        $this->invoiceIds[] = $id;

        self::assertSame($explicitCat, $this->storedCategory($id),
            'explicitně zvolená kategorie má přebít výchozí kategorii zákazníka');
    }

    public function testNoDefaultStaysNull(): void
    {
        $client = $this->customer('Zákazník bez defaultu', 'CZ30000003', null);

        $id = $this->repo->createDraft($this->payload($client), $this->userId);
        $this->invoiceIds[] = $id;

        self::assertNull($this->storedCategory($id),
            'zákazník bez defaultu + payload bez kategorie → revenue_category_id zůstane NULL');
    }

    public function testProjectDefaultTakesPrecedenceOverCustomer(): void
    {
        $clientCat  = $this->category('TST-RPC-CLI', 'Klientská kategorie');
        $projectCat = $this->category('TST-RPC-PRJ', 'Zakázková kategorie');
        $client     = $this->customer('Zákazník s projektem', 'CZ30000004', $clientCat);
        $project    = $this->project($client, $projectCat);

        $payload = $this->payload($client);
        $payload['project_id'] = $project;

        $id = $this->repo->createDraft($payload, $this->userId);
        $this->invoiceIds[] = $id;

        self::assertSame($projectCat, $this->storedCategory($id),
            'výchozí kategorie zakázky má přednost před kategorií zákazníka');
    }

    public function testHelperResolvesProjectThenClientThenNull(): void
    {
        $clientCat  = $this->category('TST-H-CLI', 'Helper klient');
        $projectCat = $this->category('TST-H-PRJ', 'Helper projekt');
        $clientWith = $this->customer('Helper klient s def', 'CZ30000010', $clientCat);
        $clientNo   = $this->customer('Helper klient bez def', 'CZ30000011', null);
        $projWith   = $this->project($clientWith, $projectCat);
        $projNoDef  = $this->project($clientWith, null);
        $pdo = $this->db->pdo();

        self::assertSame($projectCat, InvoiceRepository::resolveDefaultRevenueCategoryId($pdo, $clientWith, $projWith),
            'projekt s defaultem vyhrává nad klientem');
        self::assertSame($clientCat, InvoiceRepository::resolveDefaultRevenueCategoryId($pdo, $clientWith, $projNoDef),
            'projekt bez defaultu → fallback na klienta');
        self::assertSame($clientCat, InvoiceRepository::resolveDefaultRevenueCategoryId($pdo, $clientWith, null),
            'bez projektu → klientův default');
        self::assertNull(InvoiceRepository::resolveDefaultRevenueCategoryId($pdo, $clientNo, null),
            'klient bez defaultu + bez projektu → null');
    }

    public function testProformaToFinalCopiesCategory(): void
    {
        $catId  = $this->category('TST-PF-A', 'Proforma kategorie');
        $client = $this->customer('Zákazník proforma', 'CZ30000012', null);

        $payload = $this->payload($client);
        $payload['invoice_type'] = 'proforma';
        $payload['revenue_category_id'] = $catId;
        $proformaId = $this->repo->createDraft($payload, $this->userId);
        $this->invoiceIds[] = $proformaId;

        $finalId = $this->proformaCreator->create($proformaId, $this->userId);
        array_unshift($this->invoiceIds, $finalId); // child smazat dřív než parent (proforma)

        self::assertSame($catId, $this->storedCategory($finalId),
            'finální faktura z proformy musí zdědit kategorii tržby proformy');
    }

    public function testRecurringGenerationAppliesProjectDefault(): void
    {
        if ($this->vatRateId === 0) {
            self::markTestSkipped('Žádná použitelná sazba DPH.');
        }
        $clientCat  = $this->category('TST-R-CLI', 'Recurring klient');
        $projectCat = $this->category('TST-R-PRJ', 'Recurring projekt');
        $client  = $this->customer('Zákazník recurring', 'CZ30000013', $clientCat);
        $project = $this->project($client, $projectCat);
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $tplId = $this->templates->create([
            'supplier_id'    => $this->supplierId,
            'client_id'      => $client,
            'project_id'     => $project,
            'name'           => 'TEST recurring revenue cat (PHPUnit)',
            'frequency'      => 'monthly',
            'end_of_month'   => false,
            'anchor_date'    => $today,
            'next_run_date'  => $today,
            'invoice_type'   => 'invoice',
            'currency_id'    => $this->currencyId,
            'language'       => 'cs',
            'payment_method' => 'bank_transfer',
            'payment_due_days' => 14,
            'increment_month_in_descriptions' => false,
            'auto_issue'     => false,
            'auto_send_email'=> false,
            'status'         => 'active',
        ], $this->userId);
        $this->templateIds[] = $tplId;
        $this->templates->replaceItems($tplId, [[
            'description' => 'Paušál',
            'quantity' => 1.0,
            'unit' => 'měs',
            'unit_price_without_vat' => 1000.00,
            'vat_rate_id' => $this->vatRateId,
            'order_index' => 0,
        ]]);

        $result = $this->generator->generate($tplId, $today, $this->userId, '127.0.0.1', 'phpunit');
        $this->invoiceIds[] = $result['invoice_id'];

        self::assertSame($projectCat, $this->storedCategory($result['invoice_id']),
            'recurring generace musí aplikovat výchozí kategorii zakázky (přednost před klientem)');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function storedCategory(int $invoiceId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT revenue_category_id FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $val = $stmt->fetchColumn();
        return $val === null || $val === false ? null : (int) $val;
    }

    private function category(string $code, string $label): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO revenue_categories (supplier_id, code, label) VALUES (?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $code, $label]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->catIds[] = $id;
        return $id;
    }

    private function customer(string $name, string $dic, ?int $defaultCategoryId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor,
                                  default_revenue_category_id)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "c@example.com", "cs", ?, 1, 0, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId, $defaultCategoryId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->clientIds[] = $id;
        return $id;
    }

    private function project(int $clientId, ?int $defaultCategoryId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO projects (client_id, name, payment_due_days, hourly_rate, currency_id, status,
                                   default_revenue_category_id)
             VALUES (?, "Test zakázka", 14, 1500, ?, "active", ?)'
        );
        $stmt->execute([$clientId, $this->currencyId, $defaultCategoryId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->projectIds[] = $id;
        return $id;
    }

    /** Minimální validní payload pro createDraft (povinné: client_id, datumy, currency). */
    private function payload(int $clientId): array
    {
        return [
            'client_id'    => $clientId,
            'invoice_type' => 'invoice',
            'issue_date'   => '2099-06-10',
            'tax_date'     => '2099-06-10',
            'due_date'     => '2099-06-24',
            'currency_id'  => $this->currencyId,
        ];
    }
}
