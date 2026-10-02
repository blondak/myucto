<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Action\Report\DphPriznaniAction;
use MyInvoice\Action\Report\KontrolniHlaseniAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Report\VatCrossCheckService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * F6 — přijaté doklady ve schvalování (approval_status pending/rejected) jsou koncept,
 * takže v přiznání ani v KH nejsou. Náhledy je musí vyjmenovat: samovyměření blokuje
 * podání (patří do období DUZP), ostatní jsou jen informace (odpočet lze uplatnit později).
 * Bez schvalování se výstup nemění.
 */
#[Group('integration')]
final class PendingApprovalVatCheckTest extends TestCase
{
    private const YEAR = 2047;
    private const MONTH = 5;

    private Connection $db;
    private VatCrossCheckService $crossCheck;
    private DphPriznaniAction $dphAction;
    private KontrolniHlaseniAction $khAction;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $deId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db         = $container->get(Connection::class);
            $this->crossCheck = $container->get(VatCrossCheckService::class);
            $this->dphAction  = $container->get(DphPriznaniAction::class);
            $this->khAction   = $container->get(KontrolniHlaseniAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->deId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'DE' LIMIT 1")->fetchColumn() ?: 0);
        if (in_array(0, [$this->supplierId, $this->currencyId, $this->vatRateId, $this->userId, $this->czId, $this->deId], true)) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }
        if (!$this->db->hasColumn('purchase_invoices', 'approval_status')) {
            $this->markTestSkipped('Schéma bez schvalování přijatých dokladů.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare("UPDATE supplier SET is_vat_payer = 1, is_identified = 0, vat_period = 'monthly' WHERE id = ?")
            ->execute([$this->supplierId]);
        // Cizí rozpracované doklady z jiných testů nesmí do kontroly prosáknout.
        $pdo->prepare("UPDATE purchase_invoices SET approval_status = 'none' WHERE supplier_id = ? AND approval_status <> 'none'")
            ->execute([$this->supplierId]);
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

    public function testPendingSelfAssessmentInPeriodIsBlockingInReturnAndControlStatement(): void
    {
        $vendor = $this->client('Lieferant Prüfung', 'DE123456789', $this->deId);
        $pending = $this->purchase('RC-PENDING', $vendor, '24e', 10000.0, 0.0, 21.0, 'pending', reverseCharge: true);
        $rejected = $this->purchase('RC-REJECTED', $vendor, '24e', 5000.0, 0.0, 21.0, 'rejected', reverseCharge: true);

        $finding = $this->findingByCheck(
            $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly'),
            'pending_approval_self_assessment',
        );
        self::assertNotNull($finding, 'Samovyměření čekající na schválení musí být v náhledu přiznání.');
        self::assertTrue($finding['blocking']);
        self::assertSame('mismatch', $finding['severity']);
        self::assertEqualsCanonicalizing([$pending, $rejected], array_column($finding['documents'], 'invoice_id'));
        self::assertEqualsWithDelta(3150.0, (float) $finding['counter'], 0.01, 'Samovyměřená daň 21 % z 15 000 Kč.');
        self::assertStringContainsString('samovyměření', (string) $finding['note']);

        $kh = $this->khPreview();
        $khFinding = $this->findingByCheck($kh['cross_check'] ?? [], 'pending_approval_self_assessment');
        self::assertNotNull($khFinding, 'Totéž varování musí být v náhledu KH.');
        self::assertTrue($khFinding['blocking']);

        $blocked = $this->khDownload();
        self::assertSame(409, $blocked['status'], 'Stažení KH se samovyměřením ve schvalování je blokované.');
        self::assertSame('vat_cross_check_mismatch', $blocked['body']['error']['code'] ?? null);

        $passed = $this->khDownload(['acknowledge_mismatch' => '1']);
        self::assertSame(200, $passed['status'], 'S vědomým potvrzením KH projde.');

        $dph = $this->dphDownload();
        self::assertSame(409, $dph['status'], 'Stažení přiznání se samovyměřením ve schvalování je blokované.');
    }

    public function testPendingDomesticPurchaseIsInformativeOnly(): void
    {
        $vendor = $this->client('Dodavatel schvalování', 'CZ22222220', $this->czId);
        $id = $this->purchase('PF-PENDING', $vendor, '40', 20000.0, 4200.0, 21.0, 'pending');

        $findings = $this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertNull($this->findingByCheck($findings, 'pending_approval_self_assessment'));
        $finding = $this->findingByCheck($findings, 'pending_approval_deduction');
        self::assertNotNull($finding, 'Běžný doklad ve schvalování je informace v náhledu přiznání.');
        self::assertFalse($finding['blocking']);
        self::assertSame('info', $finding['severity']);
        self::assertSame([$id], array_column($finding['documents'], 'invoice_id'));
        self::assertStringContainsString('později', (string) $finding['note']);

        $kh = $this->khPreview();
        self::assertNotNull($this->findingByCheck($kh['cross_check'] ?? [], 'pending_approval_deduction'));
        self::assertSame(200, $this->khDownload()['status'], 'Informativní nález stažení KH neblokuje.');
    }

    public function testPendingSelfAssessmentOutsidePeriodIsNotReported(): void
    {
        $vendor = $this->client('Lieferant später', 'DE123456789', $this->deId);
        $later = sprintf('%04d-%02d-10', self::YEAR, self::MONTH + 1);
        $this->purchase('RC-LATER', $vendor, '24e', 10000.0, 0.0, 21.0, 'pending', reverseCharge: true, date: $later);

        self::assertSame([], $this->approvalFindings($this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly')));
        self::assertSame([], $this->khPreview()['cross_check'] ?? null);
    }

    public function testWithoutApprovalNothingChanges(): void
    {
        $vendor = $this->client('Lieferant ohne', 'DE123456789', $this->deId);
        // Obyčejný koncept bez schvalování: do evidence nejde a náhledy o něm mlčí jako dřív.
        $this->purchase('RC-DRAFT', $vendor, '24e', 10000.0, 0.0, 21.0, 'none', reverseCharge: true);

        self::assertSame([], $this->approvalFindings($this->crossCheck->check($this->supplierId, self::YEAR, self::MONTH, 'monthly')));
        self::assertSame([], $this->khPreview()['cross_check'] ?? null);
        self::assertSame(200, $this->khDownload()['status']);
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    private function approvalFindings(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            static fn (array $f): bool => str_starts_with((string) ($f['check'] ?? ''), 'pending_approval'),
        ));
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>|null
     */
    private function findingByCheck(array $findings, string $check): ?array
    {
        foreach ($findings as $f) {
            if (($f['check'] ?? null) === $check) {
                return $f;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function khPreview(): array
    {
        $resp = $this->khAction->preview($this->request('/api/reports/dphkh1/preview'), new Psr7Response());
        $resp->getBody()->rewind();
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return (array) ($decoded['data'] ?? $decoded);
    }

    /**
     * @param array<string,string> $extraQuery
     * @return array{status:int, body:array<string,mixed>}
     */
    private function khDownload(array $extraQuery = []): array
    {
        return $this->decode($this->khAction->download($this->request('/api/reports/dphkh1', $extraQuery), new Psr7Response()));
    }

    /**
     * @param array<string,string> $extraQuery
     * @return array{status:int, body:array<string,mixed>}
     */
    private function dphDownload(array $extraQuery = []): array
    {
        return $this->decode($this->dphAction->download($this->request('/api/reports/dphdp3', $extraQuery), new Psr7Response()));
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function decode(\Psr\Http\Message\ResponseInterface $resp): array
    {
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** @param array<string,string> $extraQuery */
    private function request(string $path, array $extraQuery = []): \Psr\Http\Message\ServerRequestInterface
    {
        $query = array_merge(['year' => (string) self::YEAR, 'month' => (string) self::MONTH, 'period' => 'monthly'], $extraQuery);
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withQueryParams($query);
    }

    private function client(string $name, string $dic, int $countryId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "test@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $countryId, $dic, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function purchase(
        string $number,
        int $vendorId,
        string $code,
        float $base,
        float $vat,
        float $rate,
        string $approvalStatus,
        bool $reverseCharge = false,
        ?string $date = null,
    ): int {
        $with = $base + $vat;
        $date ??= sprintf('%04d-%02d-15', self::YEAR, self::MONTH);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, approval_status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, ?, "{}", ?, ?, ?, "draft", ?, ?, "full", ?)'
        )->execute([
            $this->supplierId, $vendorId, $number, $date, $date, $date, $date, $this->currencyId,
            $reverseCharge ? 1 : 0, $base, $vat, $with, $approvalStatus, $code, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, vat_classification_code, order_index)
             VALUES (?, "Test položka", 1, "ks", ?, ?, ?, ?, ?, ?, ?, 0)'
        )->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $with, $code]);
        return $id;
    }
}
