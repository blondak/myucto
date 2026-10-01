<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Action\Codebook\ExpenseCategoriesAction;
use MyInvoice\Action\Codebook\RevenueCategoriesAction;
use MyInvoice\Action\Codebook\TaxConstantsAction;
use MyInvoice\Action\Codebook\VatClassificationsAction;
use MyInvoice\Action\Report\EpoDirectSubmissionAction;
use MyInvoice\Action\Report\TaxSubmissionAction;
use MyInvoice\Action\Report\TaxSubmissionEpoAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ExpenseCategoryRepository;
use MyInvoice\Repository\RevenueCategoryRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Repository\VatClassificationRepository;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Issue #113 — částečný update číselníků daňové domény a metadat podání: vynechaný
 * klíč zachová uloženou hodnotu, explicitní null ji smaže. Rollback v tearDown.
 */
#[Group('integration')]
final class TaxCodebookPartialUpdateTest extends TestCase
{
    private const CONSTANTS_YEAR = 2099;

    private ContainerInterface $container;
    private Connection $db;
    private int $supplierId = 0;
    private int $userId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    /** @param array<string,string> $args */
    private function call(object $action, string $method, array $body, array $args = []): ResponseInterface
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body);
        $resp = $args === []
            ? $action->{$method}($req, new Psr7Response())
            : $action->{$method}($req, new Psr7Response(), $args);
        $resp->getBody()->rewind();
        return $resp;
    }

    private function json(ResponseInterface $r): array
    {
        $r->getBody()->rewind();
        $decoded = json_decode((string) $r->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function testExpenseCategoryUpdateKeepsOmittedFields(): void
    {
        $repo = $this->container->get(ExpenseCategoryRepository::class);
        $id = $repo->create($this->supplierId, ['code' => 'tpart-exp', 'label' => 'Původní', 'fixed_or_var' => 'fixed', 'display_order' => 5]);
        $repo->update($id, $this->supplierId, ['code' => 'tpart-exp', 'label' => 'Původní', 'fixed_or_var' => 'fixed', 'display_order' => 5, 'archived' => true]);

        $r = $this->call($this->container->get(ExpenseCategoriesAction::class), 'update', ['label' => 'Nový název'], ['id' => (string) $id]);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $row = $repo->find($id, $this->supplierId);
        self::assertSame('Nový název', $row['label']);
        self::assertSame('tpart-exp', $row['code']);
        self::assertSame('fixed', $row['fixed_or_var']);
        self::assertSame(5, $row['display_order']);
        self::assertTrue($row['archived'], 'Archivovaná kategorie se částečným updatem nesmí odarchivovat.');
    }

    public function testRevenueCategoryUpdateKeepsNumberingSeries(): void
    {
        $repo = $this->container->get(RevenueCategoryRepository::class);
        $id = $repo->create($this->supplierId, [
            'code' => 'tpart-rev', 'label' => 'Původní', 'display_order' => 3,
            'invoice_number_format' => 'TP{YYYY}{CCC}', 'proforma_number_format' => 'ZTP{YY}{CCC}',
            'credit_note_number_format' => 'DTP{YY}{CCC}', 'invoice_number_period' => 'year',
        ]);

        $r = $this->call($this->container->get(RevenueCategoriesAction::class), 'update', ['label' => 'Nový název'], ['id' => (string) $id]);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $row = $repo->find($id, $this->supplierId);
        self::assertSame('Nový název', $row['label']);
        self::assertSame('TP{YYYY}{CCC}', $row['invoice_number_format'], 'Vlastní číselná řada kategorie se nesmí ztratit.');
        self::assertSame('ZTP{YY}{CCC}', $row['proforma_number_format']);
        self::assertSame('DTP{YY}{CCC}', $row['credit_note_number_format']);
        self::assertSame('year', $row['invoice_number_period']);
        self::assertSame(3, $row['display_order']);

        $r = $this->call($this->container->get(RevenueCategoriesAction::class), 'update', ['proforma_number_format' => null], ['id' => (string) $id]);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $row = $repo->find($id, $this->supplierId);
        self::assertNull($row['proforma_number_format']);
        self::assertSame('TP{YYYY}{CCC}', $row['invoice_number_format']);
    }

    public function testVatClassificationUpdateKeepsReportingFields(): void
    {
        $repo = $this->container->get(VatClassificationRepository::class);
        $line = DphPriznaniBuilder::USER_SELECTABLE_LINES[0];
        $id = $repo->create($this->supplierId, [
            'code' => 'TPARTX', 'label' => 'Původní', 'direction' => 'sale', 'dphdp3_line' => $line,
            'kh_section' => 'A4', 'vat_rate' => 21, 'is_reverse_charge' => true, 'kod_pred_pl' => '4',
            'kh_regime_code' => '1', 'kh_bad_debt' => 'N', 'display_order' => 7,
        ]);

        $r = $this->call($this->container->get(VatClassificationsAction::class), 'update', ['label' => 'Nový název'], ['id' => (string) $id]);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $row = $repo->find($id, $this->supplierId);
        self::assertSame('Nový název', $row['label']);
        self::assertSame('sale', $row['direction']);
        self::assertSame($line, (string) $row['dphdp3_line'], 'Řádek přiznání se částečným updatem nesmí ztratit.');
        self::assertSame('A4', $row['kh_section']);
        self::assertEqualsWithDelta(21.0, $row['vat_rate'], 0.001);
        self::assertTrue($row['is_reverse_charge']);
        self::assertSame('4', $row['kod_pred_pl']);
        self::assertSame('1', (string) $row['kh_regime_code']);
        self::assertSame('N', $row['kh_bad_debt']);
        self::assertSame(7, $row['display_order']);

        $r = $this->call($this->container->get(VatClassificationsAction::class), 'update', ['kh_bad_debt' => null], ['id' => (string) $id]);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $row = $repo->find($id, $this->supplierId);
        self::assertNull($row['kh_bad_debt']);
        self::assertSame('A4', $row['kh_section']);
    }

    public function testTaxConstantsUpdateKeepsStoredOverrideKeys(): void
    {
        $repo = $this->container->get(TaxConstantsRepository::class);
        $full = TaxConstants::forYear(2026);
        $full['year'] = self::CONSTANTS_YEAR;
        $full['payroll']['advance_tax'] = 0.16;
        foreach ($full['pausal_monthly'] as &$segment) {
            $segment['from'] = self::CONSTANTS_YEAR . substr((string) $segment['from'], 4);
        }
        unset($segment);
        $repo->upsert(self::CONSTANTS_YEAR, $full);
        $action = $this->container->get(TaxConstantsAction::class);

        // Starší klient bez bloku payroll: uložený override mezd se nesmí ztratit.
        $withoutPayroll = $full;
        unset($withoutPayroll['payroll']);
        $r = $this->call($action, 'update', ['data' => $withoutPayroll], ['year' => (string) self::CONSTANTS_YEAR]);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        self::assertEqualsWithDelta(0.16, (float) ($repo->override(self::CONSTANTS_YEAR)['payroll']['advance_tax'] ?? 0), 0.0001);

        // Jediný klíč: ostatní uložené hodnoty zůstávají.
        $r = $this->call($action, 'update', ['data' => ['credit_taxpayer' => 12345]], ['year' => (string) self::CONSTANTS_YEAR]);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $stored = $repo->override(self::CONSTANTS_YEAR);
        self::assertEquals(12345, $stored['credit_taxpayer']);
        self::assertEquals($full['tax_rate_low'], $stored['tax_rate_low']);
        self::assertEqualsWithDelta(0.16, (float) $stored['payroll']['advance_tax'], 0.0001);
    }

    public function testEpoSettingsUpdateKeepsOmittedFolder(): void
    {
        $pdo = $this->db->pdo();
        $folder = function (string $name) use ($pdo): int {
            $pdo->prepare('INSERT INTO document_folders (supplier_id, parent_id, name, created_by) VALUES (?, NULL, ?, ?)')
                ->execute([$this->supplierId, $name, $this->userId]);
            return (int) $pdo->lastInsertId();
        };
        $vat = $folder('Partial DPH');
        $income = $folder('Partial DP');
        $other = $folder('Partial jiná');
        $epo = $this->container->get(TaxSubmissionEpoRepository::class);
        $epo->saveSettings($this->supplierId, $vat, $income, $this->userId);
        $action = $this->container->get(TaxSubmissionEpoAction::class);

        $r = $this->call($action, 'updateSettings', ['vat_root_folder_id' => $other]);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        self::assertSame(['vat_root_folder_id' => $other, 'income_tax_root_folder_id' => $income], $epo->settings($this->supplierId));

        $r = $this->call($action, 'updateSettings', ['income_tax_root_folder_id' => null]);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        self::assertSame(['vat_root_folder_id' => $other, 'income_tax_root_folder_id' => null], $epo->settings($this->supplierId));
    }

    public function testCredentialSupplierAccessRequiresEnabledKey(): void
    {
        $r = $this->call($this->container->get(EpoDirectSubmissionAction::class), 'setCredentialSupplier', [], ['credentialId' => '999999999']);
        self::assertSame(400, $r->getStatusCode(), (string) $r->getBody());
        self::assertSame('validation_failed', $this->json($r)['error']['code'] ?? null);
    }

    public function testResubmitKeepsFilingMetadata(): void
    {
        $repo = $this->container->get(TaxSubmissionRepository::class);
        $id = $repo->archive($this->supplierId, 'dpfdp7', 2020, null, null,
            '<?xml version="1.0"?><Pisemnost nazevSW="test"/>', [], 'passed', [], $this->userId, 'B', 'downloaded');
        $repo->markSubmitted($id, $this->supplierId, '2021-03-15 10:00:00', 'CJ-PARTIAL-1', $this->userId);
        $this->db->pdo()->prepare("UPDATE tax_submissions SET status = 'accepted' WHERE id = ?")->execute([$id]);
        $action = $this->container->get(TaxSubmissionAction::class);

        $r = $this->call($action, 'submit', [], ['id' => (string) $id]);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $row = $repo->find($id, $this->supplierId);
        self::assertSame('2021-03-15 10:00:00', (string) $row['submitted_at'], 'Datum podání se nesmí přepsat na teď.');
        self::assertSame('CJ-PARTIAL-1', $row['submission_ref'], 'Číslo jednací se nesmí smazat.');
        self::assertSame('accepted', $row['status'], 'Opakované označení nesmí srazit přijaté podání.');

        $r = $this->call($action, 'submit', ['submission_ref' => null], ['id' => (string) $id]);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $row = $repo->find($id, $this->supplierId);
        self::assertNull($row['submission_ref']);
        self::assertSame('2021-03-15 10:00:00', (string) $row['submitted_at']);
    }
}
