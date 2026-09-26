<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * MZ-03 nastavil mzdový modul jako jediný zdroj identifikátorů odvodů zaměstnavatele a
 * SettingsAction od té doby u právnické osoby při každém uložení Nastavení firmy nuloval
 * `cssz_vsdp` / `cssz_ossz_code` / `health_insurance_number`. Jenže Mzdy jsou opt-in
 * (migrace 1290): s vypnutým modulem kanonický záznam vůbec neexistuje, takže se údaj
 * neměl kam uložit a legacy pole se místo záložního zdroje jen tiše mazala.
 *
 * Pravidlo, které tenhle test drží: přesměrování do Mezd platí jen proti ZAPNUTÉMU modulu,
 * a i tehdy se smí smazat jen údaj, který Mzdy opravdu drží. Nejdřív se přenese do
 * prázdných míst v nastavení zaměstnavatele. Firma, která Mzdy zapnula a nastavení
 * zaměstnavatele ještě nemá, údaj nesmí ztratit.
 *
 * Běží v transakci → rollback.
 */
#[Group('integration')]
final class PayrollEmployerIdentifiersTest extends TestCase
{
    private Connection $db;
    private SettingsAction $settings;
    private PayrollInstitutionAccountRepository $accounts;
    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db       = $container->get(Connection::class);
            $this->settings = $container->get(SettingsAction::class);
            $this->accounts = $container->get(PayrollInstitutionAccountRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/user v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
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

    public function testDisabledPayrollKeepsEmployerIdentifiersOnCompany(): void
    {
        $this->setSupplier(taxpayerType: 'po', payrollEnabled: false);

        $resp = $this->save([
            'cssz_vsdp' => '87654321',
            'cssz_ossz_code' => '301',
            'health_insurance_number' => '555666777',
        ]);
        self::assertSame(200, $resp->getStatusCode());

        self::assertSame(
            ['87654321', '301', '555666777'],
            $this->storedIdentifiers(),
            'S vypnutými Mzdami je Nastavení firmy jediným zdrojem VS zaměstnavatele.',
        );
    }

    /**
     * Uložení bez těchto polí (uživatel mění jinou záložku) je smí nechat být — jinak by
     * se hodnota ztratila při libovolné úpravě firmy.
     */
    public function testUnrelatedSaveDoesNotWipeIdentifiersWithPayrollOff(): void
    {
        $this->setSupplier(taxpayerType: 'po', payrollEnabled: false);
        $this->db->pdo()->prepare(
            "UPDATE supplier
                SET cssz_vsdp = '87654321', health_insurance_number = '555666777'
              WHERE id = ?"
        )->execute([$this->supplierId]);

        $resp = $this->save(['taxpayer_type' => 'po']);
        self::assertSame(200, $resp->getStatusCode());

        [$social, , $health] = $this->storedIdentifiers();
        self::assertSame('87654321', $social);
        self::assertSame('555666777', $health);
    }

    public function testEnabledPayrollMovesLegacyIdentifiersIntoPayroll(): void
    {
        $this->setSupplier(taxpayerType: 'po', payrollEnabled: true);
        $officeId = $this->createEmployerSettings();
        $accountId = $this->createHealthAccount();
        $this->db->pdo()->prepare(
            "UPDATE supplier
                SET cssz_vsdp = '87654321', cssz_ossz_code = '301', health_insurance_number = '555666777'
              WHERE id = ?"
        )->execute([$this->supplierId]);

        $resp = $this->save(['taxpayer_type' => 'po']);
        self::assertSame(200, $resp->getStatusCode());

        self::assertSame(
            [null, null, null],
            $this->storedIdentifiers(),
            'Se zapnutými Mzdami zůstává kanonickým zdrojem mzdový záznam.',
        );
        self::assertSame(['87654321', '301', '555666777'], $this->payrollIdentifiers($officeId, $accountId));
    }

    /**
     * Zapnuté Mzdy bez nastavení zaměstnavatele: údaj nemá kam jít, a proto se
     * nesmí smazat. Dřív se tu nuloval a firma o VS zaměstnavatele přišla.
     */
    public function testEnabledPayrollWithoutEmployerSettingsKeepsIdentifiers(): void
    {
        $this->setSupplier(taxpayerType: 'po', payrollEnabled: true);
        $this->db->pdo()->prepare(
            "UPDATE supplier
                SET cssz_vsdp = '87654321', health_insurance_number = '555666777'
              WHERE id = ?"
        )->execute([$this->supplierId]);

        $resp = $this->save(['taxpayer_type' => 'po']);
        self::assertSame(200, $resp->getStatusCode());

        self::assertSame(['87654321', null, '555666777'], $this->storedIdentifiers());
    }

    /**
     * Přepínač i identifikátory přijdou v JEDNOM těle — formulář Nastavení firmy ukládá
     * všechny záložky najednou. Rozhodovat se proto musí podle hodnoty z těla, ne podle
     * toho, co je zrovna v DB, jinak by zapnutí Mezd ve stejném uložení pole nesmazalo.
     */
    public function testPayrollToggleInSameBodyDecidesTheOutcome(): void
    {
        $this->setSupplier(taxpayerType: 'po', payrollEnabled: false);
        $officeId = $this->createEmployerSettings();
        $accountId = $this->createHealthAccount();

        $resp = $this->save([
            'payroll_enabled' => true,
            'cssz_vsdp' => '87654321',
            'health_insurance_number' => '555666777',
        ]);
        self::assertSame(200, $resp->getStatusCode());

        self::assertSame([null, null, null], $this->storedIdentifiers());
        self::assertSame(['87654321', null, '555666777'], $this->payrollIdentifiers($officeId, $accountId));
    }

    /**
     * Ruční zapnutí Mezd v Nastavení firmy u firmy, která nastavení zaměstnavatele
     * ještě nemá (typicky první zapnutí): VS zaměstnavatele zůstane na firmě, dokud
     * ho nepřevezme nastavení zaměstnavatele.
     */
    public function testEnablingPayrollWithoutEmployerSettingsKeepsIdentifiers(): void
    {
        $this->setSupplier(taxpayerType: 'po', payrollEnabled: false);

        $resp = $this->save([
            'payroll_enabled' => true,
            'cssz_vsdp' => '87654321',
            'cssz_ossz_code' => '301',
            'health_insurance_number' => '555666777',
        ]);
        self::assertSame(200, $resp->getStatusCode());

        self::assertSame(['87654321', '301', '555666777'], $this->storedIdentifiers());
    }

    public function testNaturalPersonKeepsPersonalIdentifiersRegardlessOfPayroll(): void
    {
        $this->setSupplier(taxpayerType: 'fo', payrollEnabled: true);

        $resp = $this->save([
            'cssz_vsdp' => '87654321',
            'health_insurance_number' => '555666777',
        ]);
        self::assertSame(200, $resp->getStatusCode());

        [$social, , $health] = $this->storedIdentifiers();
        self::assertSame('87654321', $social);
        self::assertSame('555666777', $health);
    }

    private function setSupplier(string $taxpayerType, bool $payrollEnabled): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET taxpayer_type = ?, payroll_enabled = ? WHERE id = ?'
        )->execute([$taxpayerType, $payrollEnabled ? 1 : 0, $this->supplierId]);
    }

    private function createEmployerSettings(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, is_active)
             VALUES (?, "SYNTID", "Syntetická účtárna", 1)'
        )->execute([$this->supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, default_health_insurer_code)
             VALUES (?, ?, "111")'
        )->execute([$this->supplierId, $officeId]);

        return $officeId;
    }

    private function createHealthAccount(): int
    {
        $account = $this->accounts->create($this->supplierId, [
            'institution_type' => 'health_insurer',
            'institution_code' => '111',
            'institution_name' => 'Syntetická zdravotní pojišťovna',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => null,
            'specific_symbol' => null,
            'constant_symbol' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:settings-identifiers',
            'verified_on' => '2026-06-15',
        ], $this->userId);

        return (int) $account['id'];
    }

    /** @return array{0:?string,1:?string,2:?string} */
    private function payrollIdentifiers(int $officeId, int $accountId): array
    {
        $pdo = $this->db->pdo();
        $office = $pdo->prepare('SELECT social_security_variable_symbol FROM payroll_offices WHERE id = ?');
        $office->execute([$officeId]);
        $code = $pdo->prepare('SELECT social_security_office_code FROM payroll_employer_settings WHERE supplier_id = ?');
        $code->execute([$this->supplierId]);
        $account = $pdo->prepare('SELECT variable_symbol FROM payroll_institution_accounts WHERE id = ?');
        $account->execute([$accountId]);
        $value = static fn (mixed $v): ?string => $v === null || $v === false ? null : (string) $v;

        return [$value($office->fetchColumn()), $value($code->fetchColumn()), $value($account->fetchColumn())];
    }

    /** @param array<string,mixed> $body */
    private function save(array $body): Psr7Response
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/settings/supplier')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);

        return $this->settings->updateSupplier($req, new Psr7Response());
    }

    /** @return array{0:?string,1:?string,2:?string} */
    private function storedIdentifiers(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT cssz_vsdp, cssz_ossz_code, health_insurance_number
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$this->supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            $row['cssz_vsdp'] ?? null,
            $row['cssz_ossz_code'] ?? null,
            $row['health_insurance_number'] ?? null,
        ];
    }
}
