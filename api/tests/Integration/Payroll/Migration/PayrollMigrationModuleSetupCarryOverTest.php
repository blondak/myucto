<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationModuleSetup;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převod mezd (Money S3, PREMIER, PAMICA) zakládá firmě nastavení zaměstnavatele.
 * Firma, která VS ČSSZ a kód OSSZ vede v Nastavení firmy, je nesmí ztratit: převod
 * je převezme do Mezd a k doplnění vypíše jen to, co opravdu chybí. Dřív účtárna
 * vznikla bez nich a další uložení Nastavení firmy je u PO se zapnutými Mzdami smazalo.
 *
 * Běží v transakci na izolované firmě → rollback.
 */
#[Group('integration')]
final class PayrollMigrationModuleSetupCarryOverTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollMigrationModuleSetup $setup;
    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->setup = $container->get(PayrollMigrationModuleSetup::class);
            $licensed = $container->get(PayrollModuleAccess::class)->isLicensed();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$licensed) {
            $this->markTestSkipped('Licence bez mzdového doplňku.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            "UPDATE supplier
                SET taxpayer_type = 'po', payroll_enabled = 0,
                    cssz_vsdp = '0012345678', cssz_ossz_code = '301', health_insurance_number = '555666777'
              WHERE id = ?"
        )->execute([$this->supplierId]);
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

    public function testMigrationCarriesCompanyIdentifiersIntoCreatedEmployerSettings(): void
    {
        $result = $this->setup->ensure($this->supplierId, $this->userId, '2026-08');
        if ($result['outcome'] !== PayrollMigrationModuleSetup::OUTCOME_READY) {
            $this->markTestSkipped('Mzdy nejde v testovací instalaci zapnout: ' . $result['outcome']);
        }

        self::assertTrue($result['office_created']);
        self::assertSame(['cssz_vsdp' => '0012345678', 'cssz_ossz_code' => '301'], $result['carried']);

        $stmt = $this->db->pdo()->prepare(
            'SELECT office.social_security_variable_symbol, settings.social_security_office_code
               FROM payroll_employer_settings settings
               JOIN payroll_offices office ON office.supplier_id = settings.supplier_id AND office.id = settings.default_office_id
              WHERE settings.supplier_id = ?'
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame(
            ['social_security_variable_symbol' => '0012345678', 'social_security_office_code' => '301'],
            $stmt->fetch(\PDO::FETCH_ASSOC),
        );

        self::assertNotContains(PayrollMigrationModuleSetup::TODO_SOCIAL_SECURITY_SYMBOL, $result['todo']);
        self::assertNotContains(PayrollMigrationModuleSetup::TODO_SOCIAL_SECURITY_OFFICE, $result['todo']);
        self::assertContains(PayrollMigrationModuleSetup::TODO_INSTITUTION_ACCOUNTS, $result['todo']);
        if ($result['start_period'] === null) {
            $this->markTestSkipped('Začátek vedení mezd pro rok 2026 testovací instalace nepodporuje.');
        }
        self::assertSame('2026-09-01', $result['registration_from']);
        self::assertNotContains(PayrollMigrationModuleSetup::TODO_SOCIAL_SECURITY_REGISTRATION, $result['todo']);
        self::assertSame(
            [['2026-09-01', '0012345678', PayrollMigrationModuleSetup::REGISTRATION_SOURCE]],
            $this->registrations(),
        );

        $legacy = $this->db->pdo()->prepare('SELECT cssz_vsdp, cssz_ossz_code, health_insurance_number FROM supplier WHERE id = ?');
        $legacy->execute([$this->supplierId]);
        self::assertSame(
            ['cssz_vsdp' => null, 'cssz_ossz_code' => null, 'health_insurance_number' => '555666777'],
            $legacy->fetch(\PDO::FETCH_ASSOC),
            'Číslo plátce nemá bez účtu pojišťovny kam jít, a proto zůstává na firmě.',
        );

        $protocol = new ImportProtocol('import');
        PayrollMigrationModuleSetup::report($protocol, 'payroll', $result, 'Money S3');
        $texts = implode("\n", array_column($protocol->toArray()['steps'][0]['messages'], 'text'));
        self::assertStringContainsString('převzal z Nastavení firmy variabilní symbol ČSSZ 0012345678', $texts);
        self::assertStringContainsString('založil registraci mzdové účtárny u ČSSZ s účinností od 1. 9. 2026', $texts);
        self::assertStringNotContainsString('variabilní symbol plátce pojistného ČSSZ u mzdové účtárny', $texts);
        self::assertStringNotContainsString('datum, od kdy variabilní symbol ČSSZ platí', $texts);
    }

    public function testShortSymbolGetsNoRegistrationAndStaysOnTodo(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET cssz_vsdp = '123456789' WHERE id = ?")->execute([$this->supplierId]);

        $result = $this->setup->ensure($this->supplierId, $this->userId, '2026-08');
        if ($result['outcome'] !== PayrollMigrationModuleSetup::OUTCOME_READY) {
            $this->markTestSkipped('Mzdy nejde v testovací instalaci zapnout: ' . $result['outcome']);
        }

        self::assertNull($result['registration_from']);
        self::assertSame([], $this->registrations());
        self::assertContains(PayrollMigrationModuleSetup::TODO_SOCIAL_SECURITY_REGISTRATION, $result['todo']);
    }

    public function testExistingRegistrationIsKept(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "MZDY", "Syntetická účtárna", "9990001234", 1)'
        )->execute([$this->supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_employer_settings (supplier_id, default_office_id) VALUES (?, ?)')
            ->execute([$this->supplierId, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2019-03-01", "9990001234", "synthetic-registration")'
        )->execute([$this->supplierId, $officeId]);

        $result = $this->setup->ensure($this->supplierId, $this->userId, '2026-08');
        if ($result['outcome'] !== PayrollMigrationModuleSetup::OUTCOME_READY) {
            $this->markTestSkipped('Mzdy nejde v testovací instalaci zapnout: ' . $result['outcome']);
        }

        self::assertNull($result['registration_from']);
        self::assertSame([['2019-03-01', '9990001234', 'synthetic-registration']], $this->registrations());
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private function registrations(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT effective_from, social_security_variable_symbol, source_reference
               FROM payroll_office_registration_versions WHERE supplier_id = ? ORDER BY effective_from'
        );
        $stmt->execute([$this->supplierId]);

        return array_map(static fn (array $row): array => array_map('strval', $row), $stmt->fetchAll(\PDO::FETCH_NUM));
    }

    public function testFilledEmployerSettingsAreNotOverwritten(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "MZDY", "Syntetická účtárna", "9990001234", 1)'
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "110")'
        )->execute([$this->supplierId, (int) $pdo->lastInsertId()]);

        $result = $this->setup->ensure($this->supplierId, $this->userId, '2026-08');
        if ($result['outcome'] !== PayrollMigrationModuleSetup::OUTCOME_READY) {
            $this->markTestSkipped('Mzdy nejde v testovací instalaci zapnout: ' . $result['outcome']);
        }

        self::assertFalse($result['office_created']);
        self::assertSame([], $result['carried']);
        $stmt = $pdo->prepare(
            'SELECT office.social_security_variable_symbol, settings.social_security_office_code
               FROM payroll_employer_settings settings
               JOIN payroll_offices office ON office.supplier_id = settings.supplier_id AND office.id = settings.default_office_id
              WHERE settings.supplier_id = ?'
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame(
            ['social_security_variable_symbol' => '9990001234', 'social_security_office_code' => '110'],
            $stmt->fetch(\PDO::FETCH_ASSOC),
        );
    }
}
