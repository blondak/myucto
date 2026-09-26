<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\System\EnvironmentCheckService;
use MyInvoice\Service\System\GlobalSeedRestorer;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Dotah globálních seedů nad databází, která o ně přišla stejně jako lokální
 * instalace po `reset.php`: prázdný číselník svátků a chybějící globální příjemce
 * podání. Dotah je vrátí ze seedů migrací, náhled nic nezapíše a druhý běh nic
 * nezdvojí.
 *
 * Test si díru v datech vyrobí sám a v tearDown ji dotahem zase zacelí, takže
 * po sobě nechává databázi ve stavu po migracích.
 */
#[Group('integration')]
final class GlobalSeedRestorerTest extends TestCase
{
    private PDO $pdo;
    private GlobalSeedRestorer $restorer;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->pdo = Bootstrap::buildContainer()->get(Connection::class)->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $this->restorer = new GlobalSeedRestorer($this->pdo, $rootDir . '/db/migrations');
        $this->restorer->apply();
    }

    protected function tearDown(): void
    {
        if (isset($this->restorer)) {
            $this->restorer->apply();
        }
    }

    public function testRestoresEmptiedHolidayCodebook(): void
    {
        $expected = $this->holidayCodes();
        self::assertCount(13, $expected, 'Migrace 1781 seeduje 13 svátků.');

        $this->pdo->exec('DELETE FROM public_holidays');
        self::assertContains('public_holidays', $this->restorer->emptyCodebooks());

        $check = $this->diagnosticsCheck();
        self::assertSame(EnvironmentCheckService::STATUS_FAIL, $check['status']);
        self::assertContains('public_holidays', $check['meta']['empty']);

        self::assertSame(13, $this->restorer->pending()['public_holidays'] ?? 0);
        self::assertSame([], $this->holidayCodes(), 'Náhled nesmí nic zapsat.');

        self::assertSame(13, $this->restorer->apply()['public_holidays'] ?? 0);
        self::assertSame($expected, $this->holidayCodes());
        self::assertNotContains('public_holidays', $this->restorer->emptyCodebooks());
        self::assertNotContains('public_holidays', $this->diagnosticsCheck()['meta']['empty']);

        self::assertSame([], $this->restorer->apply(), 'Druhý běh nesmí nic zdvojit.');
        self::assertSame([], $this->restorer->pending());
    }

    /**
     * Příjemce z migrace 1381 opravila 1535 (IČO, adresa) — dotah musí přehrát i tu
     * opravu, jinak by vrátil zastaralý záznam. Per-tenant override stejného kódu
     * zůstane beze změny.
     */
    public function testRestoresGlobalRecipientWithLaterCorrectionAndKeepsTenantRows(): void
    {
        $used = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM submission_outbox o
               JOIN submission_recipients r ON r.id = o.recipient_id
              WHERE r.supplier_id IS NULL AND r.code IN ('zp_vzp_111', 'cssz_epodani_test')"
        )->fetchColumn();
        if ($used > 0) {
            $this->markTestSkipped('Testovací DB má na globální příjemce navázané podání.');
        }
        $tenantBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM submission_recipients WHERE supplier_id IS NOT NULL')->fetchColumn();

        $this->pdo->exec(
            "DELETE FROM submission_recipients
              WHERE supplier_id IS NULL AND code IN ('zp_vzp_111', 'cssz_epodani_test')"
        );

        self::assertSame(2, $this->restorer->apply()['submission_recipients'] ?? 0);

        $row = $this->pdo->query(
            "SELECT business_id, isds_box_id FROM submission_recipients
              WHERE supplier_id IS NULL AND code = 'zp_vzp_111'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['business_id' => '41197518', 'isds_box_id' => 'i48ae3q'], $row);
        self::assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM submission_recipients WHERE supplier_id IS NULL AND code = 'cssz_epodani_test'"
            )->fetchColumn(),
        );
        self::assertSame(
            $tenantBefore,
            (int) $this->pdo->query('SELECT COUNT(*) FROM submission_recipients WHERE supplier_id IS NOT NULL')->fetchColumn(),
        );
    }

    /** @return array<string, mixed> */
    private function diagnosticsCheck(): array
    {
        $report = Bootstrap::buildContainer()->get(EnvironmentCheckService::class)->report(['global_codebooks']);
        $byId = array_column($report['checks'], null, 'id');
        self::assertArrayHasKey('global_codebooks', $byId);

        return $byId['global_codebooks'];
    }

    /** @return list<string> */
    private function holidayCodes(): array
    {
        return $this->pdo->query('SELECT code FROM public_holidays ORDER BY code')->fetchAll(PDO::FETCH_COLUMN);
    }
}
