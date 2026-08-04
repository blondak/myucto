<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollActivationAction;
use MyInvoice\Action\Payroll\PayrollCapabilitiesAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Repository\Payroll\PayrollStateConflictException;
use MyInvoice\Repository\Payroll\PayrollStateLockedException;
use MyInvoice\Service\Accounting\Payroll\PayrollPostingService;
use MyInvoice\Service\Payroll\PayrollPeriodOwnedException;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollFoundationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollModuleStateRepository $states;
    private PayrollPeriodOwnershipService $ownership;
    private PayrollPostingService $posting;
    private PayrollCapabilitiesAction $capabilitiesAction;
    private PayrollActivationAction $activationAction;
    private SettingsAction $settingsAction;
    private int $userId = 0;
    private int $supplierId = 0;
    private int $otherSupplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->states = $container->get(PayrollModuleStateRepository::class);
            $this->ownership = $container->get(PayrollPeriodOwnershipService::class);
            $this->posting = $container->get(PayrollPostingService::class);
            $this->capabilitiesAction = $container->get(PayrollCapabilitiesAction::class);
            $this->activationAction = $container->get(PayrollActivationAction::class);
            $this->settingsAction = $container->get(SettingsAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        if (!$this->db->hasTable('payroll_module_state')
            || !$this->db->hasTable('payroll_period_ownership')) {
            $this->markTestSkipped('Migrace 1186 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testActivationIsVersionedAndCanBeDisabledDuringSetup(): void
    {
        self::assertSame([
            'supplier_id' => $this->supplierId,
            'status' => 'disabled',
            'start_period' => null,
            'row_version' => 0,
            'activated_at' => null,
            'suspended_at' => null,
            'created_at' => null,
            'updated_at' => null,
        ], $this->states->get($this->supplierId));

        $enabled = $this->states->setActivation(
            $this->supplierId,
            true,
            '2026-06-01',
            0,
            null,
        );
        self::assertSame('setup', $enabled['status']);
        self::assertSame('2026-06', $enabled['start_period']);
        self::assertSame(1, $enabled['row_version']);
        self::assertNotNull($enabled['activated_at']);

        $disabled = $this->states->setActivation(
            $this->supplierId,
            false,
            null,
            1,
            null,
        );
        self::assertSame('disabled', $disabled['status']);
        self::assertNull($disabled['start_period']);
        self::assertSame(2, $disabled['row_version']);
    }

    public function testStaleActivationVersionIsRejected(): void
    {
        $this->states->setActivation($this->supplierId, true, '2026-06-01', 0, null);

        try {
            $this->states->setActivation($this->supplierId, false, null, 0, null);
            self::fail('Zastaralá verze musela být odmítnuta.');
        } catch (PayrollStateConflictException $e) {
            self::assertSame(1, $e->currentVersion);
        }
    }

    public function testActiveModuleCannotBeDisabledDirectly(): void
    {
        $this->states->setActivation($this->supplierId, true, '2026-06-01', 0, null);
        $this->db->pdo()->prepare(
            "UPDATE payroll_module_state SET status = 'active' WHERE supplier_id = ?"
        )->execute([$this->supplierId]);

        $this->expectException(PayrollStateLockedException::class);
        $this->states->setActivation($this->supplierId, false, null, 1, null);
    }

    public function testPeriodCanHaveOnlyOneProcessorPerSupplier(): void
    {
        $this->ownership->claimLegacy($this->supplierId, 2026, 6, 202606, null);
        $this->ownership->claimLegacy($this->supplierId, 2026, 6, 202607, null);

        $stmt = $this->db->pdo()->prepare(
            'SELECT processor, source_id
               FROM payroll_period_ownership
              WHERE supplier_id = ? AND period_start = ?'
        );
        $stmt->execute([$this->supplierId, '2026-06-01']);
        self::assertSame(
            ['processor' => 'legacy', 'source_id' => 202607],
            array_map(
                static fn (mixed $value): mixed => is_numeric($value) ? (int) $value : $value,
                $stmt->fetch(\PDO::FETCH_ASSOC),
            ),
        );

        $this->ownership->claimPayroll(
            $this->otherSupplierId,
            2026,
            6,
            'payroll_run',
            1,
            null,
        );
        $this->ownership->claimPayroll(
            $this->otherSupplierId,
            2026,
            6,
            'payroll_adjustment',
            2,
            null,
        );
        $stmt->execute([$this->otherSupplierId, '2026-06-01']);
        self::assertSame(
            ['processor' => 'payroll', 'source_id' => 2],
            array_map(
                static fn (mixed $value): mixed => is_numeric($value) ? (int) $value : $value,
                $stmt->fetch(\PDO::FETCH_ASSOC),
            ),
        );
        $sourceType = $this->db->pdo()->prepare(
            'SELECT source_type FROM payroll_period_ownership
              WHERE supplier_id = ? AND period_start = ?'
        );
        $sourceType->execute([$this->otherSupplierId, '2026-06-01']);
        self::assertSame('payroll_adjustment', $sourceType->fetchColumn());

        $this->expectException(PayrollPeriodOwnedException::class);
        $this->ownership->claimPayroll(
            $this->supplierId,
            2026,
            6,
            'payroll_run',
            1,
            null,
        );
    }

    public function testInvalidPeriodIsRejectedBeforeDatabaseWrite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ownership->claimLegacy($this->supplierId, 2026, 13, 202613, null);
    }

    public function testFailedLegacyPostingDoesNotReservePeriod(): void
    {
        $pdo = $this->db->pdo();
        $pdo->commit();
        $this->inTx = false;

        try {
            try {
                $this->posting->post(
                    $this->supplierId,
                    2017,
                    6,
                    30_000,
                    'employee',
                );
                self::fail('Neplatný rok musel výpočet odmítnout.');
            } catch (\InvalidArgumentException) {
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM payroll_period_ownership
                  WHERE supplier_id = ? AND period_start = ?'
            );
            $stmt->execute([$this->supplierId, '2017-06-01']);
            self::assertSame(0, (int) $stmt->fetchColumn());
        } finally {
            $pdo->prepare('DELETE FROM supplier WHERE id IN (?, ?)')
                ->execute([$this->supplierId, $this->otherSupplierId]);
            $this->db->close();
        }
    }

    public function testCapabilitiesAreAvailableToPayrollAccountantButNotClient(): void
    {
        $accountant = $this->request('GET', 'accountant');
        $response = ($this->capabilitiesAction)($accountant, new Response());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('disabled', $this->json($response)['state']['status']);

        $client = $this->request('GET', 'client');
        $response = ($this->capabilitiesAction)($client, new Response());
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', $this->json($response)['error']['code']);
    }

    public function testDisabledPayrollIsRejectedByApi(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?'
        )->execute([$this->supplierId]);

        $response = ($this->capabilitiesAction)(
            $this->request('GET', 'accountant'),
            new Response(),
        );
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('payroll_disabled', $this->json($response)['error']['code']);
    }

    public function testDisabledNewModuleDoesNotDisableLegacyPayrollRecap(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?'
        )->execute([$this->supplierId]);

        $preview = $this->posting->preview(
            2026,
            6,
            30_000,
            'employee',
            supplierId: $this->supplierId,
        );

        self::assertSame(30_000, $preview['breakdown']['gross']);
        self::assertSame('employee', $preview['taxpayer_type']);
    }

    /**
     * Mzdy jsou opt-in modul (migrace 1290), stejně jako sklad.
     *
     * Původně sem migrace 1187 dala DEFAULT 1. Mzdy ale vede menšina firem, takže
     * se modul otevíral v menu i v interním API všem, kdo o něj nestáli. Default
     * musí zůstat v DB, ne jen v aplikaci — nová firma nesmí mzdy dostat ani
     * cestou, která `payroll_enabled` v INSERTu vůbec neuvádí.
     */
    public function testPayrollSettingDefaultsToDisabledInSchema(): void
    {
        $default = $this->db->pdo()->query(
            "SELECT COLUMN_DEFAULT
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'supplier'
                AND COLUMN_NAME = 'payroll_enabled'"
        )->fetchColumn();

        self::assertSame('0', (string) $default);
    }

    public function testCompanySettingsCanTogglePayroll(): void
    {
        $request = $this->request('PUT', 'admin')->withParsedBody([
            'payroll_enabled' => false,
        ]);
        $response = $this->settingsAction->updateSupplier($request, new Response());

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->json($response)['payroll_enabled']);
        $stored = $this->db->pdo()->prepare(
            'SELECT payroll_enabled FROM supplier WHERE id = ?'
        );
        $stored->execute([$this->supplierId]);
        self::assertSame(0, (int) $stored->fetchColumn());
    }

    public function testLegalPersonCannotStoreEmployerIdentifiersInCompanySettings(): void
    {
        $request = $this->request('PUT', 'admin')->withParsedBody([
            'taxpayer_type' => 'po',
            'cssz_vsdp' => '87654321',
            'cssz_ossz_code' => '110',
            'health_insurance_number' => '555666777',
        ]);
        $response = $this->settingsAction->updateSupplier($request, new Response());

        self::assertSame(200, $response->getStatusCode());
        $stored = $this->db->pdo()->prepare(
            'SELECT cssz_vsdp, cssz_ossz_code, health_insurance_number
               FROM supplier
              WHERE id = ?'
        );
        $stored->execute([$this->supplierId]);
        self::assertSame([
            'cssz_vsdp' => null,
            'cssz_ossz_code' => null,
            'health_insurance_number' => null,
        ], $stored->fetch(\PDO::FETCH_ASSOC));
    }

    public function testOnlyPayrollSettingsRoleCanActivateModule(): void
    {
        $payload = [
            'enabled' => true,
            'start_period' => '2026-06',
            'row_version' => 0,
        ];

        $accountant = $this->request('PUT', 'accountant')->withParsedBody($payload);
        $response = $this->activationAction->put($accountant, new Response());
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('disabled', $this->states->get($this->supplierId)['status']);

        $admin = $this->request('PUT', 'admin')->withParsedBody($payload);
        $response = $this->activationAction->put($admin, new Response());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('setup', $this->json($response)['state']['status']);

        $audit = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM activity_log
              WHERE supplier_id = ? AND action = 'payroll.activation.enabled'"
        );
        $audit->execute([$this->supplierId]);
        self::assertSame(1, (int) $audit->fetchColumn());
    }

    public function testActivationRejectsYearOutsideSupportMatrix(): void
    {
        $admin = $this->request('PUT', 'admin')->withParsedBody([
            'enabled' => true,
            'start_period' => '2027-01',
            'row_version' => 0,
        ]);
        $response = $this->activationAction->put($admin, new Response());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', $this->json($response)['error']['code']);
        self::assertSame('disabled', $this->states->get($this->supplierId)['status']);
    }

    private function request(string $method, string $role): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/payroll')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
