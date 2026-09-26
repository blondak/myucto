<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Auth;

use MyInvoice\Action\Auth\SetupAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\License\LicenseState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Mzdy koupené spolu s instalací se při setupu zapnou na první firmě.
 *
 * Test nejde přes celý setup (ten potřebuje prázdnou instalaci) - ověřuje
 * krok, který po úspěšné aktivaci licence rozhoduje o zapnutí modulu.
 */
#[Group('integration')]
final class SetupPurchasedPayrollTest extends TestCase
{
    private Connection $db;
    private SetupAction $action;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(Bootstrap::rootDir() . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(SetupAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('supplier', 'payroll_enabled')) {
            $this->markTestSkipped('Sloupec supplier.payroll_enabled neexistuje.');
        }
        $this->supplierId = (int) ($this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('V DB není žádná firma.');
        }
        $this->db->pdo()->beginTransaction();
        $this->inTx = true;
        $this->db->pdo()->prepare('UPDATE supplier SET payroll_enabled = 0 WHERE id = ?')->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testPurchasedPayrollIsEnabled(): void
    {
        $this->enable($this->state(payrollEnabled: true, payrollUsersLicensed: 2));

        self::assertSame(1, $this->payrollEnabled());
    }

    public function testTrialDoesNotEnablePayroll(): void
    {
        $this->enable(new LicenseState(
            LicenseState::TRIAL, 'test-instance', '', null, 0, 0, 0,
            time() + 3600, null, null, null, null, true,
        ));

        self::assertSame(0, $this->payrollEnabled());
    }

    public function testLicenseWithoutPayrollDoesNotEnablePayroll(): void
    {
        $this->enable($this->state(payrollEnabled: false, payrollUsersLicensed: 0));

        self::assertSame(0, $this->payrollEnabled());
    }

    public function testPayrollWithoutUsersDoesNotEnablePayroll(): void
    {
        $this->enable($this->state(payrollEnabled: true, payrollUsersLicensed: 0));

        self::assertSame(0, $this->payrollEnabled());
    }

    private function state(bool $payrollEnabled, int $payrollUsersLicensed): LicenseState
    {
        return new LicenseState(
            LicenseState::ACTIVE, 'test-instance', 'test', null, 0, 0, 0,
            time() + 3600, null, null, null, null, true,
            payrollEnabled: $payrollEnabled,
            payrollTier: $payrollEnabled ? 'up_to_25' : null,
            payrollMaxEmployees: $payrollEnabled ? 25 : null,
            payrollUsersLicensed: $payrollUsersLicensed,
        );
    }

    private function enable(LicenseState $state): void
    {
        $method = new \ReflectionMethod(SetupAction::class, 'enablePurchasedPayroll');
        $method->invoke($this->action, $this->supplierId, $state);
    }

    private function payrollEnabled(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT payroll_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplierId]);

        return (int) $stmt->fetchColumn();
    }
}
