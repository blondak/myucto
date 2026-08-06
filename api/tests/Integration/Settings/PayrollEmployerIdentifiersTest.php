<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
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
 * Pravidlo, které tenhle test drží: přesměrování do Mezd platí jen proti ZAPNUTÉMU modulu.
 *
 * Běží v transakci → rollback.
 */
#[Group('integration')]
final class PayrollEmployerIdentifiersTest extends TestCase
{
    private Connection $db;
    private SettingsAction $settings;
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
            $container = Bootstrap::buildApp()->getContainer();
            $this->db       = $container->get(Connection::class);
            $this->settings = $container->get(SettingsAction::class);
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

    public function testEnabledPayrollStillClearsLegacyIdentifiers(): void
    {
        $this->setSupplier(taxpayerType: 'po', payrollEnabled: true);
        $this->db->pdo()->prepare(
            "UPDATE supplier
                SET cssz_vsdp = '87654321', health_insurance_number = '555666777'
              WHERE id = ?"
        )->execute([$this->supplierId]);

        $resp = $this->save(['taxpayer_type' => 'po']);
        self::assertSame(200, $resp->getStatusCode());

        self::assertSame(
            [null, null, null],
            $this->storedIdentifiers(),
            'Se zapnutými Mzdami zůstává kanonickým zdrojem mzdový záznam.',
        );
    }

    /**
     * Přepínač i identifikátory přijdou v JEDNOM těle — formulář Nastavení firmy ukládá
     * všechny záložky najednou. Rozhodovat se proto musí podle hodnoty z těla, ne podle
     * toho, co je zrovna v DB, jinak by zapnutí Mezd ve stejném uložení pole nesmazalo.
     */
    public function testPayrollToggleInSameBodyDecidesTheOutcome(): void
    {
        $this->setSupplier(taxpayerType: 'po', payrollEnabled: false);

        $resp = $this->save([
            'payroll_enabled' => true,
            'cssz_vsdp' => '87654321',
            'health_insurance_number' => '555666777',
        ]);
        self::assertSame(200, $resp->getStatusCode());

        self::assertSame([null, null, null], $this->storedIdentifiers());
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
